<?php
/**
 * Limit Login: CAPTCHA con Cloudflare Turnstile (opcional).
 *
 * Solo actua si se han configurado las dos claves. Se muestra:
 * - siempre, si "Mostrar a partir de N fallos" es 0;
 * - a partir de N fallos recientes desde la IP del visitante;
 * - si el visitante ha intentado entrar en una cuenta en modo protegido desde una IP desconocida.
 *
 * Se integra en wp-login.php (o la ruta personalizada) y en el login de WooCommerce. En XML-RPC y
 * REST no hay CAPTCHA posible: ahi siguen aplicando el bloqueo por IP y el modo protegido.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LL_TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify' );
define( 'NOLANTIS_LL_TURNSTILE_SCRIPT_URL', 'https://challenges.cloudflare.com/turnstile/v0/api.js' );
define( 'NOLANTIS_LL_TURNSTILE_FLAG_TTL', 15 * MINUTE_IN_SECONDS );

function nolantis_ll_turnstile_is_configured() {
    return '' !== (string) nolantis_ll_get_setting( 'turnstile_site_key' ) && '' !== (string) nolantis_ll_get_setting( 'turnstile_secret_key' );
}

/**
 * Peticion de login hecha por una persona desde un formulario (no XML-RPC, REST ni AJAX).
 */
function nolantis_ll_is_interactive_login_request() {
    if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
        return false;
    }

    return did_action( 'login_init' ) || isset( $_POST['woocommerce-login-nonce'] );
}

function nolantis_ll_turnstile_applies() {
    return nolantis_ll_turnstile_is_configured() && nolantis_ll_is_interactive_login_request();
}

function nolantis_ll_turnstile_flag_key( $client ) {
    return 'nolantis_ll_ts_' . md5( (string) $client['key'] );
}

/**
 * Marca al visitante para que se le muestre el CAPTCHA durante un rato.
 */
function nolantis_ll_turnstile_flag_client( $client ) {
    if ( nolantis_ll_turnstile_is_configured() && ! empty( $client['key'] ) ) {
        set_transient( nolantis_ll_turnstile_flag_key( $client ), 1, NOLANTIS_LL_TURNSTILE_FLAG_TTL );
    }
}

/**
 * Indica si a este visitante hay que pedirle el CAPTCHA.
 */
function nolantis_ll_turnstile_is_required( $client ) {
    if ( ! nolantis_ll_turnstile_is_configured() || ! empty( $client['whitelisted'] ) || empty( $client['key'] ) ) {
        return false;
    }

    $after = (int) nolantis_ll_get_setting( 'turnstile_after_failures' );

    if ( 0 === $after || get_transient( nolantis_ll_turnstile_flag_key( $client ) ) ) {
        return true;
    }

    return nolantis_ll_count_recent_failures( $client['key'] ) >= $after;
}

/**
 * Verifica la respuesta del widget con Cloudflare.
 *
 * Si Cloudflare no responde (caida o timeout) se deja pasar: el CAPTCHA es una capa extra y el
 * bloqueo por IP y el modo protegido siguen actuando. Bloquear a todo el mundo seria peor.
 */
function nolantis_ll_turnstile_verify_request( $remote_ip ) {
    static $result = null;

    if ( null !== $result ) {
        return $result;
    }

    $token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';

    if ( '' === $token ) {
        $result = false;
        return $result;
    }

    $response = wp_remote_post(
        NOLANTIS_LL_TURNSTILE_VERIFY_URL,
        array(
            'timeout' => 5,
            'body'    => array(
                'secret'   => nolantis_ll_get_setting( 'turnstile_secret_key' ),
                'response' => $token,
                'remoteip' => $remote_ip,
            ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        $result = true;
        return $result;
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $result = is_array( $body ) && ! empty( $body['success'] );

    return $result;
}

/**
 * Pinta el widget en el formulario si toca.
 */
function nolantis_ll_turnstile_render_widget() {
    static $rendered = false;

    if ( $rendered || ! nolantis_ll_turnstile_is_configured() ) {
        return;
    }

    if ( ! nolantis_ll_turnstile_is_required( nolantis_ll_get_client() ) ) {
        return;
    }

    $rendered = true;
    ?>
    <script src="<?php echo esc_url( NOLANTIS_LL_TURNSTILE_SCRIPT_URL ); ?>" async defer></script>
    <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( nolantis_ll_get_setting( 'turnstile_site_key' ) ); ?>" data-language="es" style="margin: 0 0 16px;"></div>
    <?php
}
add_action( 'login_form', 'nolantis_ll_turnstile_render_widget' );
add_action( 'woocommerce_login_form', 'nolantis_ll_turnstile_render_widget' );

/**
 * El formulario estandar de wp-login.php mide 320px; el widget de Turnstile mide 300px mas margenes.
 */
function nolantis_ll_turnstile_login_styles() {
    if ( nolantis_ll_turnstile_is_configured() ) {
        echo '<style>#loginform .cf-turnstile{display:flex;justify-content:center;}</style>';
    }
}
add_action( 'login_head', 'nolantis_ll_turnstile_login_styles' );
