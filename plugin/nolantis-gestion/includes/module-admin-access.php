<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_ADMIN_ACCESS_OPTION', 'nolantis_admin_access_settings' );
define( 'NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT', 'nolantis_admin_access_notice' );

function nolantis_get_default_admin_access_settings() {
    return array(
        'enabled' => 0,
        'slug'    => '',
    );
}

function nolantis_get_admin_access_settings() {
    $settings = get_option( NOLANTIS_ADMIN_ACCESS_OPTION, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    return wp_parse_args( $settings, nolantis_get_default_admin_access_settings() );
}

function nolantis_register_admin_access_settings() {
    register_setting(
        'nolantis_admin_access_group',
        NOLANTIS_ADMIN_ACCESS_OPTION,
        array(
            'sanitize_callback' => 'nolantis_sanitize_admin_access_settings',
            'default'           => nolantis_get_default_admin_access_settings(),
        )
    );

    add_settings_section(
        'nolantis_admin_access_section',
        'Ruta personalizada de acceso',
        'nolantis_render_admin_access_section',
        'nolantis-admin-access'
    );

    add_settings_field(
        'nolantis_admin_access_enabled',
        'Activar ruta personalizada',
        'nolantis_render_admin_access_enabled_field',
        'nolantis-admin-access',
        'nolantis_admin_access_section'
    );

    add_settings_field(
        'nolantis_admin_access_slug',
        'Slug de acceso',
        'nolantis_render_admin_access_slug_field',
        'nolantis-admin-access',
        'nolantis_admin_access_section'
    );
}
add_action( 'admin_init', 'nolantis_register_admin_access_settings' );

function nolantis_render_admin_access_section() {
    echo '<p>Define una ruta privada para acceder al login de WordPress. Cuando este ajuste este activo, el acceso publico por <code>wp-login.php</code> y <code>wp-admin</code> se ocultara para usuarios no autenticados.</p>';
}

function nolantis_render_admin_access_enabled_field() {
    $settings = nolantis_get_admin_access_settings();
    $checked  = ! empty( $settings['enabled'] ) ? 'checked' : '';

    printf(
        '<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
        esc_attr( NOLANTIS_ADMIN_ACCESS_OPTION ),
        esc_attr( $checked ),
        esc_html( 'Ocultar el acceso publico por las rutas por defecto de WordPress' )
    );
}

function nolantis_render_admin_access_slug_field() {
    $settings = nolantis_get_admin_access_settings();
    $slug     = isset( $settings['slug'] ) ? $settings['slug'] : '';
    $url      = $slug ? home_url( trailingslashit( $slug ) ) : '';

    printf(
        '<input type="text" class="regular-text" name="%1$s[slug]" value="%2$s" placeholder="%3$s" autocomplete="off" />',
        esc_attr( NOLANTIS_ADMIN_ACCESS_OPTION ),
        esc_attr( $slug ),
        esc_attr( 'mi-ruta-secreta' )
    );

    echo '<p class="description">Usa solo letras, numeros y guiones. Guarda esta ruta en un lugar seguro y pruebala en una ventana de incognito antes de cerrar tu sesion actual.</p>';

    if ( $url ) {
        printf(
            '<p class="description"><strong>URL de acceso actual:</strong> <a href="%1$s" target="_blank" rel="noreferrer">%2$s</a></p>',
            esc_url( $url ),
            esc_html( $url )
        );
    }
}

function nolantis_sanitize_admin_access_settings( $input ) {
    $defaults  = nolantis_get_default_admin_access_settings();
    $sanitized = array(
        'enabled' => ! empty( $input['enabled'] ) ? 1 : 0,
        'slug'    => isset( $input['slug'] ) ? sanitize_title( wp_unslash( $input['slug'] ) ) : '',
    );

    if ( $sanitized['enabled'] && empty( $sanitized['slug'] ) ) {
        add_settings_error(
            NOLANTIS_ADMIN_ACCESS_OPTION,
            'nolantis_admin_access_missing_slug',
            'Debes indicar un slug para activar la ruta personalizada.'
        );
        $sanitized['enabled'] = 0;
    }

    if ( in_array( $sanitized['slug'], array( 'wp-admin', 'wp-login', 'wp-login-php', 'login' ), true ) ) {
        add_settings_error(
            NOLANTIS_ADMIN_ACCESS_OPTION,
            'nolantis_admin_access_reserved_slug',
            'Ese slug entra en conflicto con rutas reservadas de WordPress. Elige otro.'
        );
        $sanitized['slug']    = '';
        $sanitized['enabled'] = 0;
    }

    return wp_parse_args( $sanitized, $defaults );
}

function nolantis_is_admin_access_enabled() {
    $settings = nolantis_get_admin_access_settings();

    return ! empty( $settings['enabled'] ) && ! empty( $settings['slug'] );
}

function nolantis_get_custom_login_slug() {
    $settings = nolantis_get_admin_access_settings();

    return ! empty( $settings['slug'] ) ? trim( $settings['slug'], '/' ) : '';
}

function nolantis_get_custom_login_url() {
    $slug = nolantis_get_custom_login_slug();

    if ( empty( $slug ) ) {
        return wp_login_url();
    }

    return home_url( trailingslashit( $slug ) );
}

function nolantis_is_custom_login_request() {
    if ( isset( $_GET['nolantis_admin_access'] ) && '1' === (string) wp_unslash( $_GET['nolantis_admin_access'] ) ) {
        return true;
    }

    if ( ! nolantis_is_admin_access_enabled() ) {
        return false;
    }

    return nolantis_get_current_request_path() === nolantis_get_custom_login_slug();
}

function nolantis_filter_login_url( $login_url, $redirect, $force_reauth ) {
    if ( ! nolantis_is_admin_access_enabled() ) {
        return $login_url;
    }

    $custom_url = nolantis_get_custom_login_url();

    if ( ! empty( $redirect ) ) {
        $custom_url = add_query_arg( 'redirect_to', $redirect, $custom_url );
    }

    if ( $force_reauth ) {
        $custom_url = add_query_arg( 'reauth', '1', $custom_url );
    }

    return $custom_url;
}
add_filter( 'login_url', 'nolantis_filter_login_url', 10, 3 );

function nolantis_filter_site_login_url( $url, $path, $scheme, $blog_id = null ) {
    if ( ! nolantis_is_admin_access_enabled() ) {
        return $url;
    }

    if ( false === strpos( $url, 'wp-login.php' ) ) {
        return $url;
    }

    $parts = wp_parse_url( $url );

    if ( empty( $parts['path'] ) || false === strpos( $parts['path'], 'wp-login.php' ) ) {
        return $url;
    }

    $custom_url = nolantis_get_custom_login_url();

    if ( ! empty( $parts['query'] ) ) {
        parse_str( $parts['query'], $query_args );
        $custom_url = add_query_arg( $query_args, $custom_url );
    }

    if ( ! empty( $parts['fragment'] ) ) {
        $custom_url .= '#' . $parts['fragment'];
    }

    return $custom_url;
}
add_filter( 'site_url', 'nolantis_filter_site_login_url', 10, 4 );
add_filter( 'network_site_url', 'nolantis_filter_site_login_url', 10, 3 );

function nolantis_get_admin_access_htaccess_rules() {
    $slug = nolantis_get_custom_login_slug();

    if ( empty( $slug ) ) {
        return array();
    }

    return array(
        '<IfModule mod_rewrite.c>',
        'RewriteEngine On',
        'RewriteRule ^' . preg_quote( $slug, '/' ) . '/?$ wp-login.php?nolantis_admin_access=1 [QSA,L]',
        '</IfModule>',
    );
}

function nolantis_sync_admin_access_htaccess() {
    if ( ! function_exists( 'insert_with_markers' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    $rules = nolantis_is_admin_access_enabled() ? nolantis_get_admin_access_htaccess_rules() : array();
    $result = insert_with_markers( ABSPATH . '.htaccess', 'Nolantis Admin Access', $rules );

    if ( false === $result ) {
        set_transient(
            NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT,
            array(
                'class'   => 'notice notice-error',
                'message' => 'No se pudo actualizar la regla de acceso en .htaccess. Revisa permisos de escritura en la raiz de WordPress.',
            ),
            MINUTE_IN_SECONDS
        );
    } else {
        delete_transient( NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT );
    }

    return $result;
}

function nolantis_remove_admin_access_htaccess_rules() {
    if ( ! function_exists( 'insert_with_markers' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    $result = insert_with_markers( ABSPATH . '.htaccess', 'Nolantis Admin Access', array() );

    if ( false !== $result ) {
        delete_transient( NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT );
    }

    return $result;
}

function nolantis_handle_admin_access_settings_update( $old_value, $value, $option ) {
    nolantis_sync_admin_access_htaccess();
}
add_action( 'update_option_' . NOLANTIS_ADMIN_ACCESS_OPTION, 'nolantis_handle_admin_access_settings_update', 10, 3 );

function nolantis_get_admin_access_notice() {
    $notice = get_transient( NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT );

    if ( ! is_array( $notice ) ) {
        return false;
    }

    return $notice;
}

function nolantis_render_hidden_admin_access_response() {
    status_header( 404 );
    nocache_headers();
    wp_die( '404', '404', array( 'response' => 404 ) );
}

function nolantis_block_default_login_access() {
    if ( ! nolantis_is_admin_access_enabled() ) {
        return;
    }

    if ( nolantis_is_custom_login_request() ) {
        return;
    }

    if ( is_user_logged_in() ) {
        return;
    }

    nolantis_render_hidden_admin_access_response();
}
add_action( 'login_init', 'nolantis_block_default_login_access' );

function nolantis_should_bypass_admin_access_block() {
    $script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
    $script_file = basename( $script_name );

    return in_array( $script_file, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true );
}

function nolantis_is_wp_admin_request_path() {
    $request_path = nolantis_get_current_request_path();

    return 'wp-admin' === $request_path || 0 === strpos( $request_path, 'wp-admin/' );
}

function nolantis_block_default_wp_admin_access_early() {
    if ( ! nolantis_is_admin_access_enabled() ) {
        return;
    }

    if ( is_user_logged_in() ) {
        return;
    }

    if ( nolantis_should_bypass_admin_access_block() ) {
        return;
    }

    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
        return;
    }

    if ( ! nolantis_is_wp_admin_request_path() ) {
        return;
    }

    nolantis_render_hidden_admin_access_response();
}
add_action( 'init', 'nolantis_block_default_wp_admin_access_early', 0 );
