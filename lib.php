<?php  // $Id$
/**
 * LDAP User role assignment plugin.
 *
 * This plugin synchronises user roles with LDAP
 *
 * @package    enrol
 * @subpackage ldapuserrel
 * @copyright  Maxime Pelletier <maxime.pelletier@educsa.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class enrol_ldapuserrel_plugin extends enrol_plugin {

    var $log;

    /**
     * Constructor for the plugin. In addition to calling the parent
     * constructor, we define and 'fix' some settings depending on the
     * real settings the admin defined.
     */
    public function __construct() {
        global $CFG;
        require_once($CFG->libdir.'/ldaplib.php');

        // Do our own stuff to fix the config (it's easier to do it
        // here than using the admin settings infrastructure). We
        // don't call $this->set_config() for any of the 'fixups'
        // (except the objectclass, as it's critical) because the user
        // didn't specify any values and relied on the default values
        // defined for the user type she chose.
        $this->load_config();

        // Make sure we get sane defaults for critical values.
        $this->config->ldapencoding = $this->get_config('ldapencoding', 'utf-8');
        $this->config->user_type = $this->get_config('user_type', 'default');

        $ldap_usertypes = ldap_supported_usertypes();
        $this->config->user_type_name = $ldap_usertypes[$this->config->user_type];
        unset($ldap_usertypes);

        $default = ldap_getdefaults();
        // Remove the objectclass default, as the values specified there are for
        // users, and we are dealing with groups here.
        unset($default['objectclass']);

        // Use defaults if values not given. Dont use this->get_config()
        // here to be able to check for 0 and false values too.
        foreach ($default as $key => $value) {
            // Watch out - 0, false are correct values too, so we can't use $this->get_config()
            if (!isset($this->config->{$key}) or $this->config->{$key} == '') {
                $this->config->{$key} = $value[$this->config->user_type];
            }
        }

        if (empty($this->config->filter)) {
            // Can't send empty filter. Fix it for now and future occasions
            $this->set_config('filter', '(objectClass=*)');
        } else if (stripos($this->config->filter, 'objectClass=') === 0) {
            // Value is 'objectClass=some-string-here', so just add ()
            // around the value (filter _must_ have them).
            // Fix it for now and future occasions
            $this->set_config('filter', '('.$this->config->filter.')');
        } else if (stripos($this->config->filter, '(') !== 0) {
            // Value is 'some-string-not-starting-with-left-parentheses',
            // which is assumed to be the objectClass matching value.
            // So build a valid filter with it.
            $this->set_config('filter', '(objectClass='.$this->config->filter.')');
        } else {
            // There is an additional possible value
            // '(some-string-here)', that can be used to specify any
            // valid filter string, to select subsets of users based
            // on any criteria. For example, we could select the users
            // whose objectClass is 'user' and have the
            // 'enabledMoodleUser' attribute, with something like:
            //
            //   (&(objectClass=user)(enabledMoodleUser=1))
            //
            // In this particular case we don't need to do anything,
            // so leave $this->config->objectclass as is.
        }
    }
	
    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param object $instance
     * @return bool
     */
    public function instance_deleteable($instance) {
        if (!enrol_is_enabled('ldapuserrel')) {
            return true;
        }
        if (!$this->get_config('host_url') or !$this->get_config('idnumber_attribute') or !$this->get_config('filter') ) {
            return true;
        }

        //TODO: connect to external system and make sure no users are to be enrolled in this course
        return false;
    }

    /**
     * Does this plugin allow manual unenrolment of a specific user?
     * Yes, but only if user suspended...
     *
     * @param stdClass $instance course enrol instance
     * @param stdClass $ue record from user_enrolments table
     *
     * @return bool - true means user with 'enrol/xxx:unenrol' may unenrol this user, false means nobody may touch this user enrolment
     */
    public function allow_unenrol_user(stdClass $instance, stdClass $ue) {
        if ($ue->status == ENROL_USER_SUSPENDED) {
            return true;
        }

        return false;
    }

	/*
	 * MAIN FUNCTION
	 * For the given user, let's go out and look in an external database
	 * for an authoritative list of relationships, and then adjust the
	 * local Moodle assignments to match.
	 * @param bool $verbose
	 * @return int 0 means success, 1 db connect failure, 2 db read failure
	 */
	function setup_enrolments($verbose = false) {
		global $CFG, $DB;

		mtrace('Starting LDAP user role assignment synchronization...');

		if ($verbose) {
			mtrace("Calling ldap_connect()");
		}
		$ldapconnection = $this->ldap_connect();
		if (!$ldapconnection) {
			mtrace('Error: [ENROL_ldapuserrel] Could not make a connection to LDAP');
			return;
		}

		// we may need a lot of memory here
		@set_time_limit(0);
		raise_memory_limit(MEMORY_HUGE);

		// Store the field values in some shorter variable names to ease reading of the code.
		$flocalsubject  = strtolower($this->get_config('localsubjectuserfield')); // Mentor
		$flocalobject   = strtolower($this->get_config('localobjectuserfield')); // Mentee

		// Unique identifier of the role assignment
		$uniqfield = $DB->sql_concat("r.id", "'|'", "u1.$flocalsubject", "'|'", "u2.$flocalobject");
				
		// Query to retreive all user role assignment from Moodle
		$sql = "SELECT $uniqfield AS uniq,
			ra.*, r.id ,
			u1.{$flocalsubject} AS subjectid,
			u2.{$flocalobject} AS objectid
			FROM {role_assignments} ra
			JOIN {role} r ON ra.roleid = r.id
			JOIN {context} c ON c.id = ra.contextid
			JOIN {user} u1 ON ra.userid = u1.id
			JOIN {user} u2 ON c.instanceid = u2.id
			WHERE ra.component = 'enrol_ldapuserrel' 
			AND c.contextlevel = " . CONTEXT_USER;

		// Is there any role in Moodle?
		// The first column is used as the key
		if (!$existing = $DB->get_records_sql($sql)) {
			$existing = array();
		}

		if ($verbose) {
			mtrace(sizeof($existing)." role assignement entries from ldapuserrel found in Moodle DB");
		}
		
		// Get enrolments for each type of role.
		$roles = get_roles_for_contextlevels(CONTEXT_USER);
		if ($verbose) {
			mtrace(sizeof($roles)." user roles found in Moodle DB");
			//print_r($roles);
		}
		$enrolments = array();
		foreach($roles as $role) {
			// Find role name
			$rolename = $DB->get_field('role', 'name', array('id' => $role) );

			// Get all contexts
			$ldap_contexts = explode(';', $this->config->{'contexts_role'.$role});

			// Get all the fields we will want for the potential course creation
			// as they are light. Don't get membership -- potentially a lot of data.
			$ldap_fields_wanted = array('dn', $this->config->idnumber_attribute);

			// Add the field containing the list of user for the given role
			array_push($ldap_fields_wanted, $this->config->{'memberattribute_role'.$role});

			// Define the search pattern
			$ldap_search_pattern = $this->config->filter;

			if ($verbose) {
				mtrace("Filter : ".$ldap_search_pattern);
				mtrace("LDAP attributes:");
				//print_r($ldap_fields_wanted);
			}

			foreach ($ldap_contexts as $ldap_context) {
				$ldap_context = trim($ldap_context);
				if (empty($ldap_context)) {
					continue; // Next;
				}
			
				if ($this->config->search_sub) {
					// Use ldap_search to find first user from subtree
					$ldap_result = ldap_search($ldapconnection,
												$ldap_context,
												$ldap_search_pattern,
												$ldap_fields_wanted);
				} else {
					// Search only in this context
					$ldap_result = ldap_list($ldapconnection,
											  $ldap_context,
											  $ldap_search_pattern,
											  $ldap_fields_wanted);
				}
				if (!$ldap_result) {
					mtrace('Warning: [ENROL_ldapuserrel] Couldn\'t get entries from LDAP -- no relationships to assign');
					continue; // Next
				}
			
				// Check and push results
				$records = ldap_get_entries($ldapconnection, $ldap_result);
				
				// LDAP libraries return an odd array, really. fix it:
				$flat_records = array();
				for ($c = 0; $c < $records['count']; $c++) {
					array_push($flat_records, $records[$c]);
				}
				// Free some mem
				unset($records);

				mtrace("Syncing ".sizeof($flat_records)." entries from LDAP for context ".$ldap_context." and role ".$rolename);

				// Is there something in LDAP?
				if (count($flat_records)) {
					$subjectusers = array(); // cache of mapping of localsubjectuserfield to mdl_user.id (for get_context_instance)
					$objectusers = array(); // cache of mapping of localsubjectuserfield to mdl_user.id (for get_context_instance)

					// We loop through all the records of the remote table
					foreach($flat_records as $mentor) {
						$mentor_idnumber = $mentor{$this->config->idnumber_attribute}[0];						

						if ($verbose) {
							mtrace("Mentor LDAP entry:".$mentor_idnumber);
							//print_r($mentor);
						}
						
						if ( !isset($mentor{$this->config->{'memberattribute_role'.$role}}) ) {
							// No children set, we skip this entry
							if ($verbose) {
								mtrace("--> No mentee for ".$mentor_idnumber);
							}
							continue;
						}

						for ( $i=0; $i < (sizeof($mentor{$this->config->{'memberattribute_role'.$role}})-1);$i++ ) {
							$mentee = $mentor{$this->config->{'memberattribute_role'.$role}}[$i];
							$key = $role . '|' . $mentor_idnumber . '|' . $mentee;

							if ($verbose) {
								mtrace("--> Mentee LDAP entry:".$mentee."(".$key.")");
							}
						
							// Check if the role is already assigned
							if (array_key_exists($key, $existing)) {
								// exists in moodle db already, unset it (so we can delete everything left)
								unset($existing[$key]);
								if ($verbose) {
									mtrace("--> Warning: [$key] exists in moodle already");
								}
								continue;
							}

										
							// Fill the subject array
							if (!array_key_exists($mentor_idnumber, $subjectusers)) {
								$subjectusers[$mentor_idnumber] = $DB->get_field('user', 'id', array($flocalsubject => $mentor_idnumber) );
							}	
						
							// Check if subject exist in Moodle
							if ($subjectusers[$mentor_idnumber] == false) {
								mtrace("--> Warning: [" . $mentor_idnumber . "] couldn't find subject user -- skipping $key");
								// couldn't find user, skip
								continue;
							}

							// Fill the object array
							if (!array_key_exists($mentee, $objectusers)) {
								$objectusers[$mentee] = $DB->get_field('user', 'id', array($flocalobject => $mentee) );
							}
						
							// Check if object exist in Moodle
							if ($objectusers[$mentee] == false) {
								// couldn't find user, skip
								mtrace("--> Warning: [" . $mentee . "] couldn't find object user --  skipping $key");
								continue;
							}
						
							// Get the context of the object
							$context = get_context_instance(CONTEXT_USER, $objectusers[$mentee]);
							mtrace("----> Information: [" . $mentor_idnumber . "] assigning role " . $rolename . " to " . $mentor_idnumber . " on " . $mentee);
							// MOODLE 1.X => role_assign($roles[$row->{$fremoterole}]->id, $subjectusers[$row->{$fremotesubject}], 0, $context->id, 0, 0, 0, 'ldapuserrel');
							// MOODLE 2.X => role_assign($roleid, $userid, $contextid, $component = '', $itemid = 0, $timemodified = '') 
							role_assign($role, $subjectusers[$mentor_idnumber], $context->id, 'enrol_ldapuserrel', 0, '');
						}
					}
				}

				mtrace("Deleting old role assignations");
				// delete everything left in existing
				foreach ($existing as $key => $assignment) {
					if ($assignment->component == 'enrol_ldapuserrel') {
						mtrace("Information: [$key] unassigning $key");
						// MOODLE 1.X => role_unassign($assignment->roleid, $assignment->userid, 0, $assignment->contextid);
						role_unassign($assignment->roleid, $assignment->userid, $assignment->contextid, 'enrol_ldapuserrel', 0);
					}
				}
			}
		}
		
		if ($verbose) {
			mtrace("Calling ldap_close()");
		}
		$this->ldap_close();
		mtrace('Execution completed normally...');
	}
	
	    /**
     * Connect to the LDAP server, using the plugin configured
     * settings. It's actually a wrapper around ldap_connect_moodle()
     *
     * @return mixed A valid LDAP connection or false.
     */
    protected function ldap_connect() {
        global $CFG;
        require_once($CFG->libdir.'/ldaplib.php');

        // Cache ldap connections. They are expensive to set up
        // and can drain the TCP/IP ressources on the server if we
        // are syncing a lot of users (as we try to open a new connection
        // to get the user details). This is the least invasive way
        // to reuse existing connections without greater code surgery.
        if(!empty($this->ldapconnection)) {
            $this->ldapconns++;
            return $this->ldapconnection;
        }

        if ($ldapconnection = ldap_connect_moodle($this->get_config('host_url'), $this->get_config('ldap_version'),
                                                  $this->get_config('user_type'), $this->get_config('bind_dn'),
                                                  $this->get_config('bind_pw'), $this->get_config('opt_deref'),
                                                  $debuginfo)) {
            $this->ldapconns = 1;
            $this->ldapconnection = $ldapconnection;
            return $ldapconnection;
        }

        // Log the problem, but don't show it to the user. She doesn't
        // even have a chance to see it, as we redirect instantly to
        // the user/front page.
        error_log($this->errorlogtag.$debuginfo);

        return false;
    }

    /**
     * Disconnects from a LDAP server
     *
     */
    protected function ldap_close() {
        $this->ldapconns--;
        if($this->ldapconns == 0) {
            @ldap_close($this->ldapconnection);
            unset($this->ldapconnection);
        }
    }
} // end of class
