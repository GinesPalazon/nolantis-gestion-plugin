<?php
// Utilidad de pruebas: se ejecuta dentro del contenedor con `php /dev/stdin <comando> [args]`.
$_SERVER['HTTP_HOST'] = 'localhost:8090';
require '/var/www/html/wp-load.php';

global $wpdb;
$cmd  = isset( $argv[1] ) ? $argv[1] : '';
$args = array_slice( $argv, 2 );

switch ( $cmd ) {
    case 'reset':
        $wpdb->query( 'DELETE FROM ' . nolantis_ll_log_table() );
        $wpdb->query( 'DELETE FROM ' . nolantis_ll_lockouts_table() );
        delete_option( NOLANTIS_LL_OPTION );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%nolantis\\_ll\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%nolantis\\_ll\\_%'" );
        echo "reset ok\n";
        break;

    case 'set': // set clave valor  (valor "a,b" para listas)
        $s = nolantis_ll_get_settings();
        $s[ $args[0] ] = in_array( $args[0], array( 'whitelist', 'trusted_proxies' ), true ) ? array_filter( explode( ',', $args[1] ) ) : $args[1];
        update_option( NOLANTIS_LL_OPTION, $s );
        echo "set {$args[0]}\n";
        break;

    case 'mkuser':
        $id = wp_create_user( 'nolantis_test', $args[0], 'test@example.invalid' );
        echo is_wp_error( $id ) ? $id->get_error_message() . "\n" : "user $id\n";
        break;

    case 'deluser':
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( array( 'nolantis_test', 'nolantis_test_admin', 'demo' ) as $login ) {
            $u = get_user_by( 'login', $login );
            if ( $u ) { wp_delete_user( $u->ID ); }
        }
        echo "deleted\n";
        break;

    case 'mkuser2': // mkuser2 login pass rol
        $id = wp_insert_user( array( 'user_login' => $args[0], 'user_pass' => $args[1], 'user_email' => $args[0] . '@example.invalid', 'role' => isset( $args[2] ) ? $args[2] : 'subscriber', 'display_name' => 'Persona ' . $args[0] ) );
        echo is_wp_error( $id ) ? $id->get_error_message() . "\n" : "user $id\n";
        break;

    case 'uid':
        $u = get_user_by( 'login', $args[0] );
        echo $u ? $u->ID : 0;
        break;

    case 'meta':
        $u = get_user_by( 'login', $args[0] );
        echo var_export( get_user_meta( $u->ID, $args[1], true ), true );
        break;

    case 'userlock': // estado del modo protegido de una cuenta
        $p = nolantis_ll_get_active_user_protection( $args[0] );
        echo $p ? 'active' : 'none';
        break;

    case 'expire': // simula que pasa el tiempo hasta que el bloqueo activo de una IP termina (desplaza todo hacia atras)
        $active = nolantis_ll_get_active_lockout( $args[0] );
        $d = $active ? ( (int) $active->locked_until - time() + 60 ) : 0;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . nolantis_ll_log_table() . ' SET created_at = created_at - %d WHERE ip = %s', $d, $args[0] ) );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . nolantis_ll_lockouts_table() . ' SET locked_at = locked_at - %d, locked_until = locked_until - %d WHERE ip = %s', $d, $d, $args[0] ) );
        echo "expired (shift {$d}s)\n";
        break;

    case 'unlock':
        echo 'unlocked rows: ' . nolantis_ll_unlock_ip( $args[0] ) . "\n";
        break;

    case 'show':
        foreach ( $wpdb->get_results( 'SELECT ip, level, username, CAST(locked_until AS SIGNED) - CAST(locked_at AS SIGNED) AS secs, blocked_attempts, unlocked, locked_until > UNIX_TIMESTAMP() AS active FROM ' . nolantis_ll_lockouts_table() . ' ORDER BY id' ) as $r ) {
            echo "LOCK ip={$r->ip} level={$r->level} user={$r->username} dur={$r->secs}s blocked={$r->blocked_attempts} unlocked={$r->unlocked} active={$r->active}\n";
        }
        foreach ( $wpdb->get_results( 'SELECT ip, event, source, COUNT(*) n FROM ' . nolantis_ll_log_table() . ' GROUP BY ip, event, source ORDER BY ip' ) as $r ) {
            echo "LOG ip={$r->ip} event={$r->event} source={$r->source} n={$r->n}\n";
        }
        break;

    case 'ipdetect': // ipdetect ip_source remote_addr [cf_header] [xff_header] [trusted]
        $s = nolantis_ll_get_settings();
        $s['ip_source'] = $args[0];
        $s['trusted_proxies'] = isset( $args[4] ) ? explode( ',', $args[4] ) : array();
        update_option( NOLANTIS_LL_OPTION, $s );
        $_SERVER['REMOTE_ADDR'] = $args[1];
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
        if ( ! empty( $args[2] ) ) { $_SERVER['HTTP_CF_CONNECTING_IP'] = $args[2]; }
        if ( ! empty( $args[3] ) ) { $_SERVER['HTTP_X_FORWARDED_FOR'] = $args[3]; }
        echo nolantis_get_request_ip() . "\n";
        break;

    case 'apppass': // prueba REST con contrasenas de aplicacion: apppass <ip> <intentos_malos>
        add_filter( 'wp_is_application_passwords_available', '__return_true' );
        define( 'REST_REQUEST', true );
        $_SERVER['REMOTE_ADDR'] = $args[0];
        $u = get_user_by( 'login', 'nolantis_test' );
        list( $good ) = WP_Application_Passwords::create_new_application_password( $u->ID, array( 'name' => 'test' ) );
        $_SERVER['PHP_AUTH_USER'] = 'nolantis_test';
        for ( $i = 1; $i <= (int) $args[1]; $i++ ) {
            $_SERVER['PHP_AUTH_PW'] = 'mala' . $i;
            $r = wp_validate_application_password( null );
            echo "bad#$i -> " . var_export( $r, true ) . "\n";
        }
        $_SERVER['PHP_AUTH_PW'] = $good;
        $r = wp_validate_application_password( null );
        echo 'good (while locked?) -> ' . var_export( $r, true ) . "\n";
        WP_Application_Passwords::delete_all_application_passwords( $u->ID );
        break;

    case 'render': // render <tab>
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        wp_set_current_user( 1 );
        $_GET['tab'] = $args[0];
        set_current_screen( 'toplevel_page_nolantis-limit-login' );
        ob_start();
        nolantis_render_limit_login_page();
        $html = ob_get_clean();
        echo 'rendered ' . strlen( $html ) . " bytes\n";
        echo strip_tags( preg_replace( '/<style.*?<\/style>/s', '', $html ) ) ? '' : '';
        if ( isset( $args[1] ) ) { echo $html; }
        break;

    case 'sitemaps':
        echo 'blog_public=' . get_option( 'blog_public' ) . ' providers=' . implode( ',', array_keys( wp_sitemaps_get_server()->registry->get_providers() ) ) . "\n";
        break;

    case 'purge':
        nolantis_ll_purge_old_records();
        echo "purged\n";
        break;
}
