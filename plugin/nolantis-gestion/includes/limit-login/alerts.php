<?php
/**
 * Limit Login: avisos por email.
 *
 * - Cuenta bajo ataque (entra en modo protegido).
 * - Bloqueo de nivel 3 (el mas largo).
 * - Un administrador entra desde una IP desde la que nunca habia entrado.
 *
 * Se envian con wp_mail(), asi que usan el SMTP del plugin si esta configurado. Para no saturar
 * el buzon durante un ataque, cada tipo de aviso se envia como maximo una vez por hora y objetivo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LL_ALERT_THROTTLE', HOUR_IN_SECONDS );

function nolantis_ll_get_alert_recipient() {
    $email = (string) nolantis_ll_get_setting( 'alert_email' );

    return is_email( $email ) ? $email : get_option( 'admin_email' );
}

/**
 * Envia un aviso si no se ha enviado otro igual en la ultima hora.
 *
 * @param string $type     Tipo de aviso (para el limite de frecuencia).
 * @param string $target   Objetivo (usuario, IP...) para el limite de frecuencia.
 * @param string $subject  Asunto sin el prefijo del sitio.
 * @param string $body     Cuerpo en texto plano.
 */
function nolantis_ll_send_alert( $type, $target, $subject, $body ) {
    $throttle_key = 'nolantis_ll_alert_' . md5( $type . '|' . $target );

    if ( get_transient( $throttle_key ) ) {
        return false;
    }

    set_transient( $throttle_key, 1, NOLANTIS_LL_ALERT_THROTTLE );

    $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $panel_url = admin_url( 'admin.php?page=nolantis-limit-login' );
    $footer    = "\n\n--\nPanel de seguridad: {$panel_url}\nSitio: " . home_url( '/' ) . "\nAviso automático del plugin WP-Gestion-integral Nolantis. Puedes desactivar estos avisos en WP-Nolantis > Seguridad de acceso > CAPTCHA y avisos.";

    return wp_mail( nolantis_ll_get_alert_recipient(), sprintf( '[%1$s] %2$s', $site_name, $subject ), $body . $footer );
}

function nolantis_ll_format_alert_date( $timestamp ) {
    return wp_date( 'd/m/Y H:i', (int) $timestamp );
}

function nolantis_ll_alert_user_protection( $username, $failures, $distinct_ips, $until ) {
    if ( empty( nolantis_ll_get_setting( 'alert_user_attack' ) ) ) {
        return;
    }

    $exists = (bool) nolantis_ll_find_user( $username );
    $body   = sprintf(
        "La cuenta \"%1\$s\" está recibiendo un ataque de fuerza bruta: %2\$d intentos fallidos desde %3\$d IPs distintas.\n\n" .
        "Se ha activado el modo protegido hasta el %4\$s: durante ese tiempo solo se puede entrar en esa cuenta desde dispositivos y redes desde los que ya se había accedido antes%5\$s. El modo protegido se renueva solo mientras siga el ataque.\n\n" .
        "%6\$s",
        $username,
        (int) $failures,
        (int) $distinct_ips,
        nolantis_ll_format_alert_date( $until ),
        nolantis_ll_turnstile_is_configured() ? ' o completando el CAPTCHA' : '',
        $exists
            ? 'Esa cuenta existe en la web. Si no reconoces esta actividad, asegúrate de que tiene una contraseña larga y única.'
            : 'Esa cuenta NO existe en la web: el atacante está probando nombres al azar. No tienes que hacer nada.'
    );

    nolantis_ll_send_alert( 'user_attack', $username, sprintf( 'Ataque contra la cuenta "%s"', $username ), $body );
}
add_action( 'nolantis_ll_user_protection_created', 'nolantis_ll_alert_user_protection', 10, 4 );

function nolantis_ll_alert_long_lockout( $ip, $level, $until, $username, $reason = 'attempts' ) {
    if ( 3 !== (int) $level || empty( nolantis_ll_get_setting( 'alert_long_lockout' ) ) ) {
        return;
    }

    $body = sprintf(
        "La IP %1\$s ha sido bloqueada hasta el %2\$s (nivel 3, el bloqueo más largo) por reincidir en intentos de acceso fallidos.\n\nÚltimo usuario probado: %3\$s\n\nNo tienes que hacer nada: la IP ya está bloqueada. Si es una IP tuya, puedes desbloquearla desde el panel.",
        $ip,
        nolantis_ll_format_alert_date( $until ),
        '' !== (string) $username ? $username : '—'
    );

    // Un solo aviso por hora aunque se bloqueen varias IPs: el detalle esta en el panel.
    nolantis_ll_send_alert( 'long_lockout', 'all', 'IP bloqueada por reincidencia', $body );
}
add_action( 'nolantis_ll_lockout_created', 'nolantis_ll_alert_long_lockout', 10, 5 );

/**
 * Se ejecuta antes de que el login correcto guarde la IP como conocida (prioridad 10).
 */
function nolantis_ll_alert_admin_new_ip( $user_login, $user = null ) {
    if ( empty( nolantis_ll_get_setting( 'alert_admin_new_ip' ) ) || ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) ) {
        return;
    }

    $client = nolantis_ll_get_client();
    $known  = nolantis_ll_get_known_ips( $user->ID );

    // Sin historial (primer acceso tras instalar el plugin) no se avisa: todo seria "nuevo".
    if ( empty( $known ) || '' === $client['key'] || in_array( $client['key'], $known, true ) ) {
        return;
    }

    $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '—';
    $body  = sprintf(
        "El administrador \"%1\$s\" ha iniciado sesión desde una IP desde la que nunca había entrado.\n\nIP: %2\$s\nFecha: %3\$s\nNavegador: %4\$s\n\nSi has sido tú, ignora este mensaje. Si no, cambia la contraseña de esa cuenta inmediatamente, y cierra sus sesiones abiertas desde su perfil.",
        $user_login,
        $client['ip'],
        nolantis_ll_format_alert_date( time() ),
        $agent
    );

    nolantis_ll_send_alert( 'admin_new_ip', $user->ID . '|' . $client['key'], sprintf( 'Nuevo acceso de administrador: %s', $user_login ), $body );
}
add_action( 'wp_login', 'nolantis_ll_alert_admin_new_ip', 5, 2 );
