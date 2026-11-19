<?php
/**
 * Limit Login: proteccion de "¿Has olvidado tu contrasena?".
 *
 * - Limite de solicitudes por IP y por usuario, para que no se pueda usar para enviar correos masivos.
 * - Misma respuesta exista o no la cuenta, para que no sirva para descubrir usuarios.
 *
 * Las dos cosas se deciden en el mismo sitio: si se hicieran por separado, el limite podria
 * responder distinto a un usuario que existe y a uno que no, y delatarlo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nolantis_ll_is_lostpassword_limit_enabled() {
    return ! nolantis_ll_is_emergency_disabled() && ! empty( nolantis_ll_get_setting( 'lostpw_limit_enabled' ) );
}

/**
 * Indica si la solicitud supera alguno de los limites.
 */
function nolantis_ll_is_lostpassword_limited( $client, $subject ) {
    $now = time();

    if ( $client['trackable'] ) {
        $ip_since = $now - ( (int) nolantis_ll_get_setting( 'lostpw_ip_window_minutes' ) * MINUTE_IN_SECONDS );

        if ( nolantis_ll_count_events( 'reset', 'ip', $client['key'], $ip_since ) >= (int) nolantis_ll_get_setting( 'lostpw_ip_max' ) ) {
            return true;
        }
    }

    if ( '' !== $subject ) {
        $user_since = $now - ( (int) nolantis_ll_get_setting( 'lostpw_user_window_minutes' ) * MINUTE_IN_SECONDS );

        if ( nolantis_ll_count_events( 'reset', 'username', $subject, $user_since ) >= (int) nolantis_ll_get_setting( 'lostpw_user_max' ) ) {
            return true;
        }
    }

    return false;
}

/**
 * @param WP_Error      $errors
 * @param WP_User|false $user_data
 */
function nolantis_ll_lostpassword_guard( $errors, $user_data ) {
    if ( ! is_wp_error( $errors ) || in_array( 'empty_username', $errors->get_error_codes(), true ) ) {
        return;
    }

    // Un administrador enviando el enlace desde la lista de usuarios no tiene limite.
    if ( is_admin() && current_user_can( 'edit_users' ) ) {
        return;
    }

    $typed   = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
    $subject = $user_data ? $user_data->user_login : nolantis_ll_get_login_subject( $typed );

    if ( nolantis_ll_is_lostpassword_limit_enabled() ) {
        $client = nolantis_ll_get_client();

        if ( nolantis_ll_is_lostpassword_limited( $client, $subject ) ) {
            // Se quitan los avisos de "no existe" para que la respuesta sea igual exista o no la cuenta.
            $errors->remove( 'invalid_email' );
            $errors->remove( 'invalidcombo' );
            $errors->add( 'nolantis_lostpw_limit', '<strong>Error:</strong> Se han solicitado demasiados restablecimientos de contraseña. Inténtalo de nuevo más tarde.' );
            return;
        }

        nolantis_ll_log_event( $client['key'], 'reset', $subject, 'login' );
    }

    nolantis_ll_hide_lostpassword_user_existence( $user_data );
}
add_action( 'lostpassword_post', 'nolantis_ll_lostpassword_guard', 10, 2 );

/**
 * En "¿Has olvidado tu contrasena?" WordPress avisa si el usuario o email no existe.
 * Se responde igual que cuando existe, para que no sirva para descubrir cuentas.
 */
function nolantis_ll_hide_lostpassword_user_existence( $user_data ) {
    if ( $user_data || empty( nolantis_ll_get_setting( 'generic_login_errors' ) ) ) {
        return;
    }

    // Solo el formulario de wp-login.php (o la ruta personalizada), no otros usos de retrieve_password().
    if ( ! did_action( 'login_init' ) ) {
        return;
    }

    $redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : site_url( 'wp-login.php?checkemail=confirm', 'login' );

    wp_safe_redirect( apply_filters( 'lostpassword_redirect', $redirect_to ) );
    exit;
}
