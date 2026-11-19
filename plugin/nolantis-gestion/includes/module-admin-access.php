<?php
/**
 * Ruta de acceso personalizada (ocultar wp-login.php y wp-admin).
 *
 * Como funciona:
 * - La ruta secreta (/mi-ruta/) muestra el formulario de acceso de WordPress.
 * - wp-login.php y wp-admin devuelven 404 a quien no ha iniciado sesion.
 *
 * La ruta secreta se sirve de dos formas, para que funcione en cualquier servidor:
 * 1. Regla en .htaccess (Apache/LiteSpeed), colocada ANTES del bloque de WordPress: si fuera
 *    despues, la regla de WordPress enviaria la ruta a index.php y nunca se aplicaria.
 * 2. Respaldo en PHP: si la peticion llega a index.php (Nginx, o Apache sin la regla), el plugin
 *    carga el formulario de acceso igualmente.
 *
 * Al guardar, el plugin abre la ruta nueva y, si no responde con el formulario de acceso, NO la
 * activa: asi no puede dejar a nadie fuera.
 *
 * Emergencia: define( 'NOLANTIS_ADMIN_ACCESS_DISABLE', true ); en wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_ADMIN_ACCESS_OPTION', 'nolantis_admin_access_settings' );
define( 'NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT', 'nolantis_admin_access_notice' );
define( 'NOLANTIS_ADMIN_ACCESS_HTACCESS_MARKER', 'Nolantis Admin Access' );

/* -------------------------------------------------------------------------
 * Ajustes
 * ---------------------------------------------------------------------- */

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
    echo '<p>Define una ruta privada para acceder al login de WordPress. Cuando este ajuste está activo, <code>wp-login.php</code> y <code>wp-admin</code> devuelven un error 404 a quien no ha iniciado sesión.</p>';
    echo '<p>Al guardar, el plugin comprueba que la ruta nueva muestra el formulario de acceso. Si no es así, <strong>no la activa</strong>, para que nadie se quede fuera.</p>';
}

function nolantis_render_admin_access_enabled_field() {
    $settings = nolantis_get_admin_access_settings();

    printf(
        '<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
        esc_attr( NOLANTIS_ADMIN_ACCESS_OPTION ),
        checked( ! empty( $settings['enabled'] ), true, false ),
        esc_html( 'Ocultar el acceso público por las rutas por defecto de WordPress' )
    );
}

function nolantis_render_admin_access_slug_field() {
    $settings = nolantis_get_admin_access_settings();
    $slug     = isset( $settings['slug'] ) ? $settings['slug'] : '';

    printf(
        '<input type="text" class="regular-text" name="%1$s[slug]" value="%2$s" placeholder="%3$s" autocomplete="off" />',
        esc_attr( NOLANTIS_ADMIN_ACCESS_OPTION ),
        esc_attr( $slug ),
        esc_attr( 'mi-ruta-secreta' )
    );

    echo '<p class="description">Usa solo letras, números y guiones. Guarda esta ruta en un lugar seguro y pruébala en una ventana de incógnito antes de cerrar tu sesión actual.</p>';

    if ( $slug ) {
        printf(
            '<p class="description"><strong>URL de acceso:</strong> <a href="%1$s" target="_blank" rel="noreferrer">%2$s</a></p>',
            esc_url( nolantis_get_custom_login_url() ),
            esc_html( nolantis_get_custom_login_url() )
        );
    }
}

function nolantis_get_admin_access_reserved_slugs() {
    return array( 'admin', 'login', 'dashboard', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-login-php', 'wp-signup', 'wp-activate', 'wp-cron', 'xmlrpc', 'xmlrpc-php', 'feed', 'comments', 'search', 'page', 'author', 'category', 'tag', 'embed', 'sitemap', 'wp-sitemap' );
}

/**
 * Indica si el slug ya lo usa una pagina, entrada o archivo/carpeta real de la web.
 */
function nolantis_admin_access_slug_is_taken( $slug ) {
    if ( url_to_postid( home_url( trailingslashit( $slug ) ) ) || get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) ) ) {
        return true;
    }

    return file_exists( ABSPATH . $slug );
}

function nolantis_sanitize_admin_access_settings( $input ) {
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    $sanitized = array(
        'enabled' => ! empty( $input['enabled'] ) ? 1 : 0,
        'slug'    => isset( $input['slug'] ) ? substr( sanitize_title( wp_unslash( $input['slug'] ) ), 0, 80 ) : '',
    );

    if ( '' !== $sanitized['slug'] && in_array( $sanitized['slug'], nolantis_get_admin_access_reserved_slugs(), true ) ) {
        nolantis_admin_access_settings_error( 'reserved_slug', 'Ese slug entra en conflicto con rutas reservadas de WordPress. Elige otro.' );
        $sanitized['slug'] = '';
    } elseif ( '' !== $sanitized['slug'] && nolantis_admin_access_slug_is_taken( $sanitized['slug'] ) ) {
        nolantis_admin_access_settings_error( 'taken_slug', 'Ese slug ya lo usa una página, entrada o carpeta de la web. Elige otro.' );
        $sanitized['slug'] = '';
    }

    if ( $sanitized['enabled'] && '' === $sanitized['slug'] ) {
        nolantis_admin_access_settings_error( 'missing_slug', 'Debes indicar un slug válido para activar la ruta personalizada.' );
        $sanitized['enabled'] = 0;
    }

    return $sanitized;
}

function nolantis_admin_access_settings_error( $code, $message ) {
    if ( function_exists( 'add_settings_error' ) ) {
        add_settings_error( NOLANTIS_ADMIN_ACCESS_OPTION, 'nolantis_admin_access_' . $code, $message );
    }
}

/* -------------------------------------------------------------------------
 * Estado y URLs
 * ---------------------------------------------------------------------- */

function nolantis_admin_access_is_emergency_disabled() {
    return defined( 'NOLANTIS_ADMIN_ACCESS_DISABLE' ) && NOLANTIS_ADMIN_ACCESS_DISABLE;
}

function nolantis_is_admin_access_enabled() {
    if ( nolantis_admin_access_is_emergency_disabled() ) {
        return false;
    }

    $settings = nolantis_get_admin_access_settings();

    return ! empty( $settings['enabled'] ) && ! empty( $settings['slug'] );
}

function nolantis_get_custom_login_slug() {
    $settings = nolantis_get_admin_access_settings();

    return ! empty( $settings['slug'] ) ? trim( $settings['slug'], '/' ) : '';
}

function nolantis_get_custom_login_url( $scheme = null ) {
    $slug = nolantis_get_custom_login_slug();

    if ( empty( $slug ) ) {
        return wp_login_url();
    }

    return home_url( trailingslashit( $slug ), $scheme );
}

function nolantis_is_custom_login_request() {
    if ( ! nolantis_is_admin_access_enabled() ) {
        return false;
    }

    return hash_equals( nolantis_get_custom_login_slug(), nolantis_get_current_request_path() );
}

function nolantis_get_current_script_name() {
    $script = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';

    return basename( (string) $script );
}

/**
 * Convierte cualquier URL de wp-login.php de esta web en la ruta personalizada, conservando
 * los parametros tal cual (sin decodificarlos, para no romper redirect_to ni las claves).
 *
 * El formulario de entradas protegidas con contrasena (action=postpass) se deja en wp-login.php:
 * lo ven los visitantes y no debe revelar la ruta secreta.
 */
function nolantis_admin_access_rewrite_login_url( $url ) {
    if ( ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
        return $url;
    }

    $parts = wp_parse_url( $url );

    if ( false === $parts || empty( $parts['path'] ) || 'wp-login.php' !== basename( $parts['path'] ) ) {
        return $url;
    }

    if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
        return $url;
    }

    $query = isset( $parts['query'] ) ? $parts['query'] : '';
    parse_str( $query, $query_args );

    if ( isset( $query_args['action'] ) && 'postpass' === $query_args['action'] ) {
        return $url;
    }

    $scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : null;
    $custom = nolantis_get_custom_login_url( $scheme );

    if ( '' !== $query ) {
        $custom .= '?' . $query;
    }

    if ( ! empty( $parts['fragment'] ) ) {
        $custom .= '#' . $parts['fragment'];
    }

    return $custom;
}

function nolantis_filter_site_login_url( $url, $path = '', $scheme = null, $blog_id = null ) {
    // Se llama en cada enlace que genera WordPress: primero se descartan los que no son de acceso.
    if ( false === strpos( (string) $url, 'wp-login.php' ) || ! nolantis_is_admin_access_enabled() ) {
        return $url;
    }

    return nolantis_admin_access_rewrite_login_url( $url );
}
add_filter( 'site_url', 'nolantis_filter_site_login_url', 10, 4 );
add_filter( 'network_site_url', 'nolantis_filter_site_login_url', 10, 3 );

/**
 * WordPress tambien redirige a wp-login.php con URLs relativas (p. ej. "wp-login.php?checkemail=confirm"
 * tras pedir una contrasena nueva). Sin esto, esas redirecciones acabarian en un 404.
 */
function nolantis_filter_login_redirect( $location ) {
    if ( false === strpos( (string) $location, 'wp-login.php' ) || ! nolantis_is_admin_access_enabled() ) {
        return $location;
    }

    return nolantis_admin_access_rewrite_login_url( $location );
}
add_filter( 'wp_redirect', 'nolantis_filter_login_redirect', 10, 1 );

/* -------------------------------------------------------------------------
 * Servir la ruta secreta y bloquear las rutas por defecto
 * ---------------------------------------------------------------------- */

/**
 * Respaldo en PHP: si la ruta secreta llega a index.php (Nginx, o Apache sin la regla de .htaccess),
 * se carga el formulario de acceso de WordPress.
 */
function nolantis_admin_access_serve_login_fallback() {
    if ( ! nolantis_is_custom_login_request() || 'wp-login.php' === nolantis_get_current_script_name() ) {
        return;
    }

    // Variables globales que usan las funciones de wp-login.php (login_header, login_footer...).
    global $pagenow, $error, $interim_login, $action, $user_login;

    $pagenow = 'wp-login.php';

    require_once ABSPATH . 'wp-login.php';
    exit;
}
add_action( 'wp_loaded', 'nolantis_admin_access_serve_login_fallback', 1 );

function nolantis_render_hidden_admin_access_response() {
    status_header( 404 );
    nocache_headers();
    header( 'X-Robots-Tag: noindex, nofollow', true );
    wp_die( '404', '404', array( 'response' => 404 ) );
}

/**
 * wp-login.php: solo se puede usar a traves de la ruta secreta, con sesion iniciada, o para
 * el formulario de entradas protegidas con contrasena.
 */
function nolantis_block_default_login_access() {
    if ( ! nolantis_is_admin_access_enabled() || nolantis_is_custom_login_request() || is_user_logged_in() ) {
        return;
    }

    if ( isset( $_GET['action'] ) && 'postpass' === $_GET['action'] ) {
        return;
    }

    nolantis_render_hidden_admin_access_response();
}
add_action( 'login_init', 'nolantis_block_default_login_access', 0 );

function nolantis_should_bypass_admin_access_block() {
    return in_array( nolantis_get_current_script_name(), array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ), true );
}

function nolantis_is_wp_admin_request_path() {
    $request_path = nolantis_get_current_request_path();

    return 'wp-admin' === $request_path || 0 === strpos( $request_path, 'wp-admin/' );
}

/**
 * wp-admin, wp-signup.php y wp-activate.php: 404 a quien no ha iniciado sesion.
 * (wp-signup.php y wp-activate.php redirigen al registro y revelarian la ruta secreta).
 */
function nolantis_block_default_wp_admin_access_early() {
    if ( ! nolantis_is_admin_access_enabled() || is_user_logged_in() ) {
        return;
    }

    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
        return;
    }

    if ( ! is_multisite() && in_array( nolantis_get_current_script_name(), array( 'wp-signup.php', 'wp-activate.php' ), true ) ) {
        nolantis_render_hidden_admin_access_response();
    }

    if ( nolantis_should_bypass_admin_access_block() ) {
        return;
    }

    if ( is_admin() || nolantis_is_wp_admin_request_path() ) {
        nolantis_render_hidden_admin_access_response();
    }
}
add_action( 'init', 'nolantis_block_default_wp_admin_access_early', 0 );

/**
 * WordPress redirige /login, /admin y /dashboard a wp-login.php o wp-admin. Con la ruta oculta,
 * /login acabaria redirigiendo a la ruta secreta y la revelaria a cualquiera.
 */
function nolantis_disable_admin_location_redirects() {
    if ( nolantis_is_admin_access_enabled() ) {
        remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
    }
}
add_action( 'init', 'nolantis_disable_admin_location_redirects' );

/* -------------------------------------------------------------------------
 * .htaccess
 * ---------------------------------------------------------------------- */

function nolantis_admin_access_load_admin_helpers() {
    if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'got_mod_rewrite' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
}

function nolantis_admin_access_get_htaccess_path() {
    nolantis_admin_access_load_admin_helpers();

    return get_home_path() . '.htaccess';
}

function nolantis_get_admin_access_htaccess_rules() {
    $slug = nolantis_get_custom_login_slug();

    if ( empty( $slug ) ) {
        return array();
    }

    // Ruta de wp-login.php relativa a la carpeta de la web (por si WordPress esta en un subdirectorio).
    // Se usa site_url( '/' ) y no site_url( 'wp-login.php' ), que pasaria por el propio filtro del plugin.
    $home_path  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
    $login_path = (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ) . 'wp-login.php';
    $target     = ( '' !== $home_path && 0 === strpos( $login_path, $home_path ) ) ? substr( $login_path, strlen( $home_path ) ) : 'wp-login.php';

    return array(
        '<IfModule mod_rewrite.c>',
        'RewriteEngine On',
        'RewriteRule ^' . preg_quote( $slug, '/' ) . '/?$ ' . ltrim( $target, '/' ) . ' [QSA,L]',
        '</IfModule>',
    );
}

/**
 * Escribe el bloque en .htaccess y se asegura de que queda ANTES del bloque de WordPress.
 */
function nolantis_admin_access_write_htaccess( $lines ) {
    nolantis_admin_access_load_admin_helpers();

    $file = nolantis_admin_access_get_htaccess_path();

    if ( file_exists( $file ) ? ! is_writable( $file ) : ( empty( $lines ) || ! is_writable( dirname( $file ) ) ) ) {
        return empty( $lines );
    }

    if ( ! insert_with_markers( $file, NOLANTIS_ADMIN_ACCESS_HTACCESS_MARKER, $lines ) ) {
        return false;
    }

    if ( empty( $lines ) ) {
        return true;
    }

    $content = (string) file_get_contents( $file );
    $begin   = '# BEGIN ' . NOLANTIS_ADMIN_ACCESS_HTACCESS_MARKER;
    $end     = '# END ' . NOLANTIS_ADMIN_ACCESS_HTACCESS_MARKER;
    $ours    = strpos( $content, $begin );
    $wp      = strpos( $content, '# BEGIN WordPress' );

    if ( false === $ours || false === $wp || $ours < $wp ) {
        return true;
    }

    $stop  = strpos( $content, $end, $ours );
    $stop  = false === $stop ? strlen( $content ) : $stop + strlen( $end );
    $block = substr( $content, $ours, $stop - $ours );
    $rest  = substr( $content, 0, $ours ) . substr( $content, $stop );
    $rest  = preg_replace( "/\n{3,}/", "\n\n", $rest );

    return false !== file_put_contents( $file, $block . "\n\n" . ltrim( $rest, "\n" ), LOCK_EX );
}

/**
 * Sincroniza .htaccess con los ajustes. En servidores sin mod_rewrite (Nginx) no hace nada:
 * ahi la ruta funciona con el respaldo en PHP.
 *
 * @return bool false si habia que escribir y no se pudo.
 */
function nolantis_sync_admin_access_htaccess() {
    nolantis_admin_access_load_admin_helpers();

    $rules = nolantis_is_admin_access_enabled() ? nolantis_get_admin_access_htaccess_rules() : array();

    if ( ! got_mod_rewrite() ) {
        return true;
    }

    return nolantis_admin_access_write_htaccess( $rules );
}

function nolantis_remove_admin_access_htaccess_rules() {
    nolantis_admin_access_load_admin_helpers();

    if ( ! got_mod_rewrite() ) {
        return true;
    }

    return nolantis_admin_access_write_htaccess( array() );
}

/* -------------------------------------------------------------------------
 * Guardar: sincronizar y comprobar que la ruta funciona
 * ---------------------------------------------------------------------- */

/**
 * Abre la ruta secreta como lo haria un visitante.
 *
 * @return array{ok: bool|null, detail: string} ok = true (funciona), false (no funciona) o null (no se pudo comprobar).
 */
function nolantis_admin_access_check_route() {
    $response = wp_remote_get(
        add_query_arg( 'nolantis_check', wp_generate_password( 8, false ), nolantis_get_custom_login_url() ),
        array(
            'timeout'     => 10,
            'redirection' => 0,
            'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
            'headers'     => array( 'Cache-Control' => 'no-cache' ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return array(
            'ok'     => null,
            'detail' => $response->get_error_message(),
        );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    return array(
        'ok'     => 200 === $code && false !== strpos( wp_remote_retrieve_body( $response ), 'loginform' ),
        'detail' => 'HTTP ' . $code,
    );
}

function nolantis_set_admin_access_notice( $class, $message ) {
    set_transient(
        NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT,
        array(
            'class'   => $class,
            'message' => $message,
        ),
        5 * MINUTE_IN_SECONDS
    );
}

function nolantis_handle_admin_access_settings_update() {
    static $running = false;

    if ( $running ) {
        return;
    }

    $running  = true;
    $htaccess = nolantis_sync_admin_access_htaccess()
        ? ''
        : ' Aviso: no se pudo escribir la regla en .htaccess (revisa sus permisos); la ruta funciona mediante el respaldo del plugin.';

    if ( ! nolantis_is_admin_access_enabled() ) {
        if ( '' !== $htaccess ) {
            nolantis_set_admin_access_notice( 'notice notice-warning', trim( $htaccess ) );
        }

        $running = false;
        return;
    }

    $check = nolantis_admin_access_check_route();
    $url   = nolantis_get_custom_login_url();

    if ( false === $check['ok'] ) {
        $settings            = nolantis_get_admin_access_settings();
        $settings['enabled'] = 0;
        update_option( NOLANTIS_ADMIN_ACCESS_OPTION, $settings );
        nolantis_sync_admin_access_htaccess();

        nolantis_set_admin_access_notice( 'notice notice-error', sprintf( 'La ruta %1$s no muestra el formulario de acceso (%2$s). Por seguridad NO se ha activado y el acceso sigue por wp-login.php. Prueba con otro slug o revisa la configuración del servidor.', $url, $check['detail'] ) );
    } elseif ( null === $check['ok'] ) {
        nolantis_set_admin_access_notice( 'notice notice-warning', sprintf( 'Ruta activada, pero el servidor no permite comprobarla automáticamente (%1$s). Antes de cerrar sesión, abre %2$s en una ventana de incógnito y comprueba que ves el formulario de acceso. Si no funciona, desactiva la opción o añade define( \'NOLANTIS_ADMIN_ACCESS_DISABLE\', true ); en wp-config.php.', $check['detail'], $url ) . $htaccess );
    } else {
        nolantis_set_admin_access_notice( '' === $htaccess ? 'notice notice-success' : 'notice notice-warning', sprintf( 'Ruta comprobada: el formulario de acceso responde en %s. Guárdala en un lugar seguro.', $url ) . $htaccess );
    }

    $running = false;
}
// add_option_* cubre la primera vez que se guarda (cuando la opcion aun no existe).
add_action( 'add_option_' . NOLANTIS_ADMIN_ACCESS_OPTION, 'nolantis_handle_admin_access_settings_update' );
add_action( 'update_option_' . NOLANTIS_ADMIN_ACCESS_OPTION, 'nolantis_handle_admin_access_settings_update' );

function nolantis_get_admin_access_notice() {
    $notice = get_transient( NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT );

    if ( ! is_array( $notice ) ) {
        return false;
    }

    delete_transient( NOLANTIS_ADMIN_ACCESS_NOTICE_TRANSIENT );

    return $notice;
}
