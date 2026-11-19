<?php
/**
 * Limit Login: endurecimiento contra fuerza bruta.
 *
 * - XML-RPC desactivado salvo que se active expresamente.
 * - Impedir la enumeracion de usuarios (?author=N, /wp-json/wp/v2/users, oEmbed, sitemap de usuarios).
 * - Mensajes de error genericos que no revelan si un usuario existe.
 *
 * Estos ajustes son independientes del contador de bloqueos y siguen activos aunque se use
 * NOLANTIS_LIMIT_LOGIN_DISABLE.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * XML-RPC
 * ---------------------------------------------------------------------- */

function nolantis_ll_maybe_disable_xmlrpc() {
    if ( ! empty( nolantis_ll_get_setting( 'enable_xmlrpc' ) ) ) {
        return;
    }

    add_filter( 'xmlrpc_enabled', '__return_false' );
    add_filter( 'xmlrpc_methods', '__return_empty_array' );
    add_filter( 'wp_headers', 'nolantis_ll_remove_pingback_header' );
    remove_action( 'wp_head', 'rsd_link' );

    // Se corta la peticion lo antes posible para que un ataque contra xmlrpc.php no consuma recursos.
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
        status_header( 403 );
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'XML-RPC está desactivado en esta web.';
        exit;
    }
}
add_action( 'plugins_loaded', 'nolantis_ll_maybe_disable_xmlrpc', 1 );

function nolantis_ll_remove_pingback_header( $headers ) {
    unset( $headers['X-Pingback'] );

    return $headers;
}

/* -------------------------------------------------------------------------
 * Enumeracion de usuarios
 * ---------------------------------------------------------------------- */

function nolantis_ll_is_user_enumeration_blocked() {
    return ! empty( nolantis_ll_get_setting( 'block_user_enumeration' ) ) && ! is_user_logged_in();
}

/**
 * /?author=1 redirige a /author/nombre-de-usuario/ y revela el login. Se responde con un 404 del tema.
 */
function nolantis_ll_block_author_query( $query_vars ) {
    if ( is_admin() || ! isset( $_GET['author'] ) || ! nolantis_ll_is_user_enumeration_blocked() ) {
        return $query_vars;
    }

    return array( 'error' => '404' );
}
add_filter( 'request', 'nolantis_ll_block_author_query' );

/**
 * Oculta los endpoints de usuarios de la REST API a quien no ha iniciado sesion.
 * El editor de bloques sigue funcionando porque se usa con sesion iniciada.
 */
function nolantis_ll_filter_rest_user_endpoints( $endpoints ) {
    if ( ! nolantis_ll_is_user_enumeration_blocked() ) {
        return $endpoints;
    }

    foreach ( array_keys( $endpoints ) as $route ) {
        if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
            unset( $endpoints[ $route ] );
        }
    }

    return $endpoints;
}
add_filter( 'rest_endpoints', 'nolantis_ll_filter_rest_user_endpoints' );

/**
 * oEmbed (/wp-json/oembed/1.0/embed?url=...) devuelve author_url con el slug del autor, que
 * suele coincidir con su nombre de usuario. Se elimina para visitantes sin sesion.
 */
function nolantis_ll_filter_oembed_author( $data ) {
    if ( nolantis_ll_is_user_enumeration_blocked() ) {
        unset( $data['author_name'], $data['author_url'] );
    }

    return $data;
}
add_filter( 'oembed_response_data', 'nolantis_ll_filter_oembed_author', 20 );

function nolantis_ll_remove_users_sitemap( $provider, $name ) {
    if ( 'users' === $name && ! empty( nolantis_ll_get_setting( 'block_user_enumeration' ) ) ) {
        return false;
    }

    return $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'nolantis_ll_remove_users_sitemap', 10, 2 );

/* -------------------------------------------------------------------------
 * Errores genericos
 * ---------------------------------------------------------------------- */

/**
 * Sustituye "el usuario no existe" / "la contrasena es incorrecta" por un unico mensaje.
 * Prioridad 25: despues de los autenticadores del core (20).
 */
function nolantis_ll_generic_login_error( $user ) {
    if ( ! is_wp_error( $user ) || empty( nolantis_ll_get_setting( 'generic_login_errors' ) ) ) {
        return $user;
    }

    if ( ! array_intersect( array( 'invalid_username', 'invalid_email', 'incorrect_password' ), $user->get_error_codes() ) ) {
        return $user;
    }

    return new WP_Error(
        'nolantis_invalid_credentials',
        sprintf(
            '<strong>Error:</strong> Usuario o contraseña incorrectos. <a href="%s">¿Has olvidado tu contraseña?</a>',
            esc_url( wp_lostpassword_url() )
        )
    );
}
add_filter( 'authenticate', 'nolantis_ll_generic_login_error', 25 );

// La respuesta generica de "¿Has olvidado tu contraseña?" esta en lostpassword.php.

