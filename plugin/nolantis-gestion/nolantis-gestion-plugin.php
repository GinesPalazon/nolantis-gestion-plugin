<?php
/**
 * Plugin Name:       WP-Gestion-integral Nolantis
 * Plugin URI:        https://nolantis.com
 * Update URI:        https://github.com/GinesPalazon/nolantis-gestion-plugin/
 * Description:       Plugin de gestión y seguridad para webs gestionadas por Nolantis.
 * Version:           2.0.1
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Nolantis
 * Author URI:        https://nolantis.com
 * License:           GPL-2.0+
 * Text Domain:       nolantis-gestion
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_VERSION', '2.0.1' );
define( 'NOLANTIS_VERSION_OPTION', 'nolantis_plugin_version' );
define( 'NOLANTIS_PLUGIN_FILE', __FILE__ );
define( 'NOLANTIS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_ICON_PATH', NOLANTIS_PLUGIN_PATH . 'nolantis-icon.png' );
define( 'NOLANTIS_PLUGIN_ICON_URL', NOLANTIS_PLUGIN_URL . 'nolantis-icon.png' );

require_once NOLANTIS_PLUGIN_PATH . 'includes/core.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-smtp.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-limit-login.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-integrity.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-admin-access.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-login-branding.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-general-settings.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/module-hardening.php';
require_once NOLANTIS_PLUGIN_PATH . 'includes/admin-ui.php';

function nolantis_activate_plugin() {
    nolantis_ensure_autoloaded_options();
    nolantis_smtp_migrate_legacy_data();
    nolantis_ll_install();
    nolantis_mail_log_install();
    nolantis_sync_admin_access_htaccess();
    nolantis_hardening_write_uploads_rules( nolantis_hardening_enabled( 'block_php_uploads' ) );
    update_option( NOLANTIS_VERSION_OPTION, NOLANTIS_VERSION, true );
    flush_rewrite_rules( false );
}
register_activation_hook( __FILE__, 'nolantis_activate_plugin' );

function nolantis_deactivate_plugin() {
    nolantis_remove_admin_access_htaccess_rules();
    nolantis_ll_unschedule_purge();
    nolantis_mail_log_unschedule_purge();
    nolantis_integrity_unschedule();
    nolantis_hardening_write_uploads_rules( false );
    flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'nolantis_deactivate_plugin' );

/**
 * Al actualizar el plugin (desde GitHub o con la actualizacion automatica) WordPress no ejecuta el
 * hook de activacion. La primera vez que carga una version nueva se reaplican las reglas de .htaccess:
 * asi, por ejemplo, la regla de la ruta de acceso de versiones anteriores pasa delante de la de WordPress.
 */
function nolantis_maybe_upgrade() {
    if ( NOLANTIS_VERSION === get_option( NOLANTIS_VERSION_OPTION ) ) {
        return;
    }

    nolantis_ensure_autoloaded_options();
    nolantis_smtp_migrate_legacy_data();
    nolantis_sync_admin_access_htaccess();
    nolantis_hardening_write_uploads_rules( nolantis_hardening_enabled( 'block_php_uploads' ) );
    update_option( NOLANTIS_VERSION_OPTION, NOLANTIS_VERSION, true );
}
add_action( 'init', 'nolantis_maybe_upgrade', 1 );

/**
 * Rendimiento: los ajustes que se leen en cada visita deben existir y cargarse con el resto de
 * opciones de WordPress. Si no existen, WordPress hace una consulta en cada pagina para comprobarlo.
 * Se crean vacios (no se guardan los valores por defecto: el plugin los sigue aplicando al leerlos).
 */
function nolantis_ensure_autoloaded_options() {
    $options = array( 'nolantis_limit_login_settings', 'nolantis_hardening_settings', 'nolantis_general_settings', 'nolantis_admin_access_settings' );

    foreach ( $options as $option ) {
        if ( false !== get_option( $option ) ) {
            continue;
        }

        // Se guarda tal cual un array vacio, sin pasar por el saneado de los ajustes.
        add_filter( "sanitize_option_{$option}", 'nolantis_return_empty_array', PHP_INT_MAX );
        add_option( $option, array(), '', true );
        remove_filter( "sanitize_option_{$option}", 'nolantis_return_empty_array', PHP_INT_MAX );
    }
}

function nolantis_return_empty_array() {
    return array();
}
