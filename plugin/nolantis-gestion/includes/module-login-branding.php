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

function nolantis_login_branding_styles() {
    $site_logo_url     = nolantis_get_site_logo_url();
    $nolantis_logo_url = file_exists( NOLANTIS_PLUGIN_LOGO_WHITE_SF_PATH ) ? NOLANTIS_PLUGIN_LOGO_WHITE_SF_URL : '';
    $site_name         = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    ?>
    <style>
        body.login {
            background: #063259;
            color: #ffffff;
        }

        body.login div#login {
            width: 380px;
            padding-top: 5vh;
        }

        body.login div#login h1 {
            margin-bottom: 18px;
            text-align: center;
        }

        body.login div#login h1::before {
            content: <?php echo wp_json_encode( $site_name ); ?>;
            display: block;
            margin-bottom: 18px;
            color: #ffffff;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: 0.01em;
        }

        body.login div#login h1 a {
            width: 220px;
            height: 92px;
            margin: 0 auto;
            background-image: <?php echo $site_logo_url ? "url('" . esc_url_raw( $site_logo_url ) . "')" : 'none'; ?>;
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            pointer-events: <?php echo $site_logo_url ? 'auto' : 'none'; ?>;
        }

        body.login #loginform,
        body.login #registerform,
        body.login #lostpasswordform {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: none;
            border-radius: 16px;
        }

        body.login label,
        body.login .forgetmenot,
        body.login .message,
        body.login #nav a,
        body.login #backtoblog a,
        body.login .privacy-policy-page-link a {
            color: #ffffff !important;
        }

        body.login form .input,
        body.login input[type="text"],
        body.login input[type="password"] {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #0b1f33;
            border-radius: 10px;
            box-shadow: none;
        }

        body.login .button.wp-hide-pw .dashicons {
            color: #063259;
        }

        body.login .button-primary {
            background: #ffffff;
            border-color: #ffffff;
            color: #063259;
            border-radius: 10px;
            box-shadow: none;
            text-shadow: none;
            font-weight: 700;
        }

        body.login .button-primary:hover,
        body.login .button-primary:focus {
            background: #d9e6f2;
            border-color: #d9e6f2;
            color: #063259;
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
    <?php if ( $nolantis_logo_url ) : ?>
        <script>
            window.nolantisLoginLogoUrl = '<?php echo esc_js( $nolantis_logo_url ); ?>';
        </script>
    <?php endif; ?>
    <?php
}
add_action( 'login_enqueue_scripts', 'nolantis_login_branding_styles' );

function nolantis_login_header_url() {
    return home_url( '/' );
}
add_filter( 'login_headerurl', 'nolantis_login_header_url' );

function nolantis_login_header_text() {
    return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}
add_filter( 'login_headertext', 'nolantis_login_header_text' );

function nolantis_login_footer_credit() {
    $logo_url = file_exists( NOLANTIS_PLUGIN_LOGO_WHITE_SF_PATH ) ? NOLANTIS_PLUGIN_LOGO_WHITE_SF_URL : '';
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
