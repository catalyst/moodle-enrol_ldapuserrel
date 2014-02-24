<?php
/**
 * LDAP User role assignment plugin version specification.
 *
 * @package    enrol
 * @subpackage ldapuserrel
 * @copyright  Maxime Pelletier <maxime.pelletier@educsa.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2014022400;        // The current plugin version (Date: YYYYMMDDXX)
$plugin->requires  = 2012061700;        // Requires this Moodle version
$plugin->release   = '0.2';
$plugin->component = 'enrol_ldapuserrel';  // Full name of the plugin (used for diagnostics)
$plugin->dependencies = array('enrol_ldap' => 2012061700); // We need the setting matrix (settingslib.php file)
$plugin->maturity  = MATURITY_BETA;
