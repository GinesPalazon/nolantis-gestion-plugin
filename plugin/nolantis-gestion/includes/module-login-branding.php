<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nolantis_get_site_logo_url() {
    $custom_logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;

    if ( $custom_logo_id ) {
        $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );

        if ( $logo_url ) {
            return $logo_url;
        }
    }

    $site_icon_url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 192 ) : '';

    return is_string( $site_icon_url ) ? $site_icon_url : '';
}

/**
 * Cadena valida para CSS (content: "..."; url("...")).
 *
 * Se escapan con la sintaxis de CSS (\\HEX ) las comillas, la barra invertida, los saltos de linea
 * y los caracteres < y >, asi el texto no puede cerrar la etiqueta <style>. Las tildes y la n se
 * dejan tal cual (la pagina es UTF-8); con json_encode saldrian como \\u00ed y CSS no las entiende.
 */
function nolantis_css_string( $value ) {
    $escaped = preg_replace_callback(
        '/[\\\\"\'<>\x00-\x1f\x7f]/',
        function ( $match ) {
            return '\\' . dechex( ord( $match[0] ) ) . ' ';
        },
        (string) $value
    );

    return '"' . $escaped . '"';
}

function nolantis_login_branding_styles() {
    $site_logo_url     = nolantis_get_site_logo_url();
    $site_name         = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    ?>
    <style>
        body.login {
            background: #080a0f;
            color: #ffffff;
        }

        body.login div#login {
            width: 380px;
            padding-top: 5vh;
        }

        /* Solo el logo de arriba ("> h1"): otras pantallas tienen su propio h1 dentro del formulario. */
        body.login div#login > h1 {
            margin-bottom: 18px;
            text-align: center;
        }

        body.login div#login > h1::before {
            content: <?php echo nolantis_css_string( $site_name ); ?>;
            display: block;
            margin-bottom: 18px;
            color: #ffffff;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: 0.01em;
        }

        body.login div#login > h1 a {
            width: 220px;
            height: 92px;
            margin: 0 auto;
            background-image: <?php echo $site_logo_url ? 'url(' . nolantis_css_string( esc_url_raw( $site_logo_url ) ) . ')' : 'none'; ?>;
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            pointer-events: <?php echo $site_logo_url ? 'auto' : 'none'; ?>;
        }

        /* Todos los formularios de la pantalla de acceso: acceso, registro, contrasena olvidada,
           contrasena nueva, confirmar el correo del administrador... */
        body.login div#login form {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: none;
            border-radius: 16px;
            color: #ffffff;
        }

        body.login label,
        body.login .forgetmenot,
        body.login .message,
        body.login #nav a,
        body.login #backtoblog a,
        body.login .privacy-policy-page-link a {
            color: #ffffff !important;
        }

        body.login div#login form a:not(.button) {
            color: #ffffff;
            text-decoration: underline;
        }

        /* Pantalla "Verificación del correo electrónico de administración". */
        body.login .admin-email__heading {
            color: #ffffff;
            border-bottom-color: rgba(255, 255, 255, 0.16);
        }

        body.login form .input,
        body.login input[type="text"],
        body.login input[type="password"] {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #080a0f;
            border-radius: 10px;
            box-shadow: none;
        }

        body.login .button.wp-hide-pw .dashicons {
            color: #080a0f;
        }

        body.login .button-primary {
            background: #ffffff;
            border-color: #ffffff;
            color: #080a0f;
            border-radius: 10px;
            box-shadow: none;
            text-shadow: none;
            font-weight: 700;
        }

        body.login .button-primary:hover,
        body.login .button-primary:focus {
            background: #e6e8ec;
            border-color: #e6e8ec;
            color: #080a0f;
        }

        /* Botones secundarios dentro de los formularios (p. ej. "Actualizar" en la verificacion del correo). */
        body.login div#login form .button:not(.button-primary):not(.wp-hide-pw) {
            background: transparent;
            border-color: rgba(255, 255, 255, 0.6);
            color: #ffffff;
            border-radius: 10px;
        }

        body.login div#login form .button:not(.button-primary):not(.wp-hide-pw):hover,
        body.login div#login form .button:not(.button-primary):not(.wp-hide-pw):focus {
            background: rgba(255, 255, 255, 0.12);
            border-color: #ffffff;
            color: #ffffff;
        }

        body.login .message,
        body.login .notice,
        body.login .success {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #ffffff;
            color: #ffffff;
            box-shadow: none;
        }

        body.login #backtoblog,
        body.login #nav {
            text-align: center;
        }

        .nolantis-login-credit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 18px;
            text-align: center;
            color: #ffffff;
            font-size: 13px;
            line-height: 1.5;
        }

        .nolantis-login-credit a {
            color: #ffffff;
            font-weight: 700;
            text-decoration: underline;
        }

        .nolantis-login-credit img {
            width: 18px;
            height: 18px;
            object-fit: contain;
        }
    </style>
    <?php
}
add_action( 'login_enqueue_scripts', 'nolantis_login_branding_styles' );

function nolantis_login_header_url() {
    return home_url( '/' );
}
add_filter( 'login_headerurl', 'nolantis_login_header_url' );

/**
 * WordPress imprime este texto sin escapar dentro del enlace del logo.
 */
function nolantis_login_header_text() {
    return esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
}
add_filter( 'login_headertext', 'nolantis_login_header_text' );

function nolantis_login_footer_credit() {
    $logo_url = file_exists( NOLANTIS_PLUGIN_ICON_PATH ) ? NOLANTIS_PLUGIN_ICON_URL : '';
    ?>
    <p class="nolantis-login-credit">
        <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="Nolantis" />
        <?php endif; ?>
        <span>Web Gestionada por <a href="https://nolantis.com" target="_blank" rel="noreferrer">Nolantis</a></span>
    </p>
    <?php
}
add_action( 'login_footer', 'nolantis_login_footer_credit' );
