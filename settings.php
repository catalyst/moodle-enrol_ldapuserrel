<?php
/**
* LDAP User role assignment plugin settings and presets.
*
* @package    enrol
* @subpackage ldapuserrel
* @copyright  Maxime Pelletier <maxime.pelletier@educsa.org>
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
	require_once($CFG->dirroot.'/enrol/ldap/settingslib.php');
	require_once($CFG->libdir.'/ldaplib.php');
	$yesno = array(get_string('no'), get_string('yes'));
	
	$settings->add(new admin_setting_heading('enrol_ldapuserrel_settings', '', get_string('pluginname_desc', 'enrol_ldapuserrel')));

	//--- connection settings ---
	$settings->add(new admin_setting_heading('enrol_ldapuserrel_server_settings', get_string('server_settings', 'enrol_ldapuserrel'), ''));
	$settings->add(new admin_setting_configtext_trim_lower('enrol_ldapuserrel/host_url', get_string('host_url_key', 'enrol_ldapuserrel'), get_string('host_url', 'enrol_ldapuserrel'), ''));

	// Set LDAPv3 as the default. Nowadays all the servers support it and it gives us some real benefits.
	$options = array(3=>'3', 2=>'2');
	$settings->add(new admin_setting_configselect('enrol_ldapuserrel/ldap_version', get_string('version_key', 'enrol_ldapuserrel'), get_string('version', 'enrol_ldapuserrel'), 3, $options));
	$settings->add(new admin_setting_configtext_trim_lower('enrol_ldapuserrel/ldapencoding', get_string('ldap_encoding_key', 'enrol_ldapuserrel'), get_string('ldap_encoding', 'enrol_ldapuserrel'), 'utf-8'));

	//--- binding settings ---
	$settings->add(new admin_setting_heading('enrol_ldap_bind_settings', get_string('bind_settings', 'enrol_ldapuserrel'), ''));
	$settings->add(new admin_setting_configtext_trim_lower('enrol_ldapuserrel/bind_dn', get_string('bind_dn_key', 'enrol_ldapuserrel'), get_string('bind_dn', 'enrol_ldapuserrel'), ''));
	$settings->add(new admin_setting_configpasswordunmask('enrol_ldapuserrel/bind_pw', get_string('bind_pw_key', 'enrol_ldapuserrel'), get_string('bind_pw', 'enrol_ldapuserrel'), ''));

	// ----- LDAP data settings -------------
	$options = $yesno;
	$settings->add(new admin_setting_configselect('enrol_ldapuserrel/search_sub', get_string('search_sub_key', 'enrol_ldapuserrel'), get_string('search_sub', 'enrol_ldapuserrel'), 0, $options));
	
	$settings->add(new admin_setting_configtext_trim_lower('enrol_ldapuserrel/filter', get_string('filter_key', 'enrol_ldapuserrel'), get_string('filter', 'enrol_ldapuserrel'), ''));

    $options = ldap_supported_usertypes();
    $settings->add(new admin_setting_configselect('enrol_ldap/user_type', get_string('user_type_key', 'enrol_ldap'), get_string('user_type', 'enrol_ldap'), 'default', $options));
 
	$settings->add(new admin_setting_configtext_trim_lower('enrol_ldapuserrel/idnumber_attribute', get_string('idnumber_attribute_key', 'enrol_ldapuserrel'), get_string('idnumber_attribute', 'enrol_ldapuserrel'), '', true, true));
	
	//--- Role mapping settings matrix ---
	$settings->add(new admin_setting_heading('enrol_ldapuserrel_roles', get_string('roles', 'enrol_ldapuserrel'), ''));
	if (!during_initial_install()) {
		$settings->add(new admin_setting_ldap_rolemapping('enrol_ldapuserrel/role_mapping', get_string ('role_mapping_key', 'enrol_ldapuserrel'), get_string ('role_mapping', 'enrol_ldapuserrel'), ''));
	}
	
	//--- Moodle Field settings -----------------------------------------------------------------------------------
	$settings->add(new admin_setting_heading('enrol_ldapuserrel_remoteheader', get_string('remote_fields_mapping', 'enrol_ldapuserrel'), ''));

	$settings->add(new admin_setting_configtext('enrol_ldapuserrel/localsubjectuserfield', get_string('localsubjectuserfield', 'enrol_ldapuserrel'), get_string('localsubjectuserfield_desc', 'enrol_ldapuserrel'), 'username'));

	$settings->add(new admin_setting_configtext('enrol_ldapuserrel/localobjectuserfield', get_string('localobjectuserfield', 'enrol_ldapuserrel'), get_string('localobjectuserfield_desc', 'enrol_ldapuserrel'), 'username'));		

	$settings->add(new admin_setting_configtext('enrol_ldapuserrel/localrolefield', get_string('localrolefield', 'enrol_ldapuserrel'), get_string('localrolefield_desc', 'enrol_ldapuserrel'), 'shortname'));	
}
