<?php
/**
 * Plugin Name:       Nolantis Gestion Plugin
 * Plugin URI:        https://nolantis.com
 * Update URI:        https://github.com/GinesPalazon/nolantis-gestion-plugin/
 * Description:       Plugin de gestion para Nolantis.
 * Version:           1.0.0
 * Author:            Nolantis
 * Author URI:        https://nolantis.com
 * License:           GPL-2.0+
 * Text Domain:       nolantis-gestion
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_VERSION', '1.0.0' );
define( 'NOLANTIS_PLUGIN_FILE', __FILE__ );
define( 'NOLANTIS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NOLANTIS_ROOT_PATH', dirname( NOLANTIS_PLUGIN_PATH ) . '/' );
define( 'NOLANTIS_PLUGIN_LOGO_PATH', NOLANTIS_PLUGIN_PATH . 'logo-nolantis.png' );
define( 'NOLANTIS_PLUGIN_LOGO_URL', NOLANTIS_PLUGIN_URL . 'logo-nolantis.png' );
define( 'NOLANTIS_PLUGIN_LOGO_WHITE_PATH', NOLANTIS_PLUGIN_PATH . 'logo-nolantis-blanco.png' );
define( 'NOLANTIS_PLUGIN_LOGO_WHITE_URL', NOLANTIS_PLUGIN_URL . 'logo-nolantis-blanco.png' );
define( 'NOLANTIS_PLUGIN_LOGO_WHITE_SF_PATH', NOLANTIS_PLUGIN_PATH . 'logo-nolantis-blanco-sf.png' );
define( 'NOLANTIS_PLUGIN_LOGO_WHITE_SF_URL', NOLANTIS_PLUGIN_URL . 'logo-nolantis-blanco-sf.png' );

require_once NOLANTIS_PLUGIN_PATH . 'includes/core.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-smtp.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-limit-login.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-admin-access.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-login-branding.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/admin-ui.php';

function nolantis_activate_plugin() {
    nolantis_register_admin_access_settings();
    nolantis_sync_admin_access_htaccess();
    flush_rewrite_rules( false );
}
register_activation_hook( __FILE__, 'nolantis_activate_plugin' );

function nolantis_deactivate_plugin() {
    nolantis_remove_admin_access_htaccess_rules();
    flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'nolantis_deactivate_plugin' );
