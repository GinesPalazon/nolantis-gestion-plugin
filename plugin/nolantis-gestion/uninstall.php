<?php
/**
 * Se ejecuta SOLO al borrar el plugin desde WordPress (no al desactivarlo).
 *
 * Elimina todos sus datos: tablas, ajustes (incluida la contrasena SMTP), datos de usuarios,
 * temporales, tareas programadas y sus reglas de .htaccess. En multisitio, en cada web.
 *
 * WordPress no carga el plugin al ejecutar este archivo: no se usan sus funciones ni constantes.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Quita por completo un bloque "# BEGIN marcador ... # END marcador" de un archivo .htaccess.
 */
function nolantis_uninstall_remove_htaccess_block( $file, $marker ) {
    if ( ! is_file( $file ) || ! is_writable( $file ) ) {
        return;
    }

    $content = (string) file_get_contents( $file );
    $pattern = '/\n?# BEGIN ' . preg_quote( $marker, '/' ) . '\n.*?# END ' . preg_quote( $marker, '/' ) . '\n?/s';
    $cleaned = preg_replace( $pattern, "\n", $content );

    if ( is_string( $cleaned ) && $cleaned !== $content ) {
        file_put_contents( $file, ltrim( $cleaned, "\n" ), LOCK_EX );
    }
}

function nolantis_uninstall_site() {
    global $wpdb;

    // Tablas propias.
    foreach ( array( 'nolantis_login_log', 'nolantis_login_lockouts', 'nolantis_mail_log' ) as $table ) {
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    // Ajustes.
    $options = array(
        'nolantis_plugin_version',
        'nolantis_limit_login_settings',
        'nolantis_ll_db_version',
        'nolantis_ll_cloudflare_ranges',
        'nolantis_smtp_settings',
        'nolantis_smtp_password',
        'nolantis_smtp_wizard_status',
        'nolantis_mail_log_db_version',
        'nolantis_admin_access_settings',
        'nolantis_general_settings',
        'nolantis_hardening_settings',
        'nolantis_integrity_results',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Temporales (bloqueos antiguos, avisos, resultados de pruebas...) y datos del actualizador.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_nolantis_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_nolantis_' ) . '%',
            $wpdb->esc_like( 'external_updates-nolantis-gestion-plugin' ) . '%'
        )
    );

    // Tareas programadas.
    foreach ( array( 'nolantis_ll_daily_purge', 'nolantis_ll_weekly_cloudflare_update', 'nolantis_mail_log_daily_purge', 'nolantis_integrity_daily_scan', 'puc_cron_check_updates-nolantis-gestion-plugin' ) as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }

    // Reglas de .htaccess: ruta de acceso (raiz de la web) y bloqueo de PHP (uploads).
    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    nolantis_uninstall_remove_htaccess_block( get_home_path() . '.htaccess', 'Nolantis Admin Access' );

    $uploads = wp_get_upload_dir();
    nolantis_uninstall_remove_htaccess_block( trailingslashit( $uploads['basedir'] ) . '.htaccess', 'Nolantis Hardening' );
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $nolantis_site_id ) {
        switch_to_blog( $nolantis_site_id );
        nolantis_uninstall_site();
        restore_current_blog();
    }
} else {
    nolantis_uninstall_site();
}

// Datos de usuarios (IPs desde las que han entrado) y temporales de red.
delete_metadata( 'user', 0, '_nolantis_known_ips', '', true );

if ( is_multisite() ) {
    $GLOBALS['wpdb']->query(
        $GLOBALS['wpdb']->prepare(
            "DELETE FROM {$GLOBALS['wpdb']->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
            $GLOBALS['wpdb']->esc_like( '_site_transient_nolantis_' ) . '%',
            $GLOBALS['wpdb']->esc_like( '_site_transient_timeout_nolantis_' ) . '%'
        )
    );
}
