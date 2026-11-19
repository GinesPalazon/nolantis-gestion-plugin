<?php
/**
 * SMTP: configuracion de PHPMailer para todos los correos de WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Segundos maximos para conectar y esperar al servidor SMTP. PHPMailer usa 300 por defecto:
 * con un servidor caido, cualquier formulario que envie correo se quedaria colgado 5 minutos.
 */
define( 'NOLANTIS_SMTP_TIMEOUT', 15 );
define( 'NOLANTIS_SMTP_DEBUG_MAX_LINES', 60 );

/* -------------------------------------------------------------------------
 * Conversacion con el servidor (para saber por que falla un envio)
 * ---------------------------------------------------------------------- */

function nolantis_smtp_reset_debug() {
    $GLOBALS['nolantis_smtp_debug'] = array();
}

/**
 * Se empieza de cero en cada correo: si uno falla antes de conectar (p. ej. una direccion no valida),
 * su registro no debe llevar la conversacion del correo anterior.
 */
function nolantis_smtp_reset_debug_per_mail( $short_circuit ) {
    nolantis_smtp_reset_debug();

    return $short_circuit;
}
add_filter( 'pre_wp_mail', 'nolantis_smtp_reset_debug_per_mail', 1 );

/**
 * @return string[]
 */
function nolantis_smtp_get_debug() {
    return isset( $GLOBALS['nolantis_smtp_debug'] ) && is_array( $GLOBALS['nolantis_smtp_debug'] ) ? $GLOBALS['nolantis_smtp_debug'] : array();
}

/**
 * Recoge la conversacion SMTP sin datos sensibles: de lo que envia WordPress solo se guardan los
 * comandos SMTP (EHLO, MAIL FROM, RCPT TO...). Nunca credenciales ni el contenido del mensaje,
 * que tambien viajan como "CLIENT -> SERVER".
 */
function nolantis_smtp_capture_debug( $message, $level = 0 ) {
    if ( ! isset( $GLOBALS['nolantis_smtp_debug'] ) || ! is_array( $GLOBALS['nolantis_smtp_debug'] ) ) {
        nolantis_smtp_reset_debug();
    }

    if ( count( $GLOBALS['nolantis_smtp_debug'] ) >= NOLANTIS_SMTP_DEBUG_MAX_LINES ) {
        return;
    }

    $line = trim( preg_replace( '/\s+/', ' ', (string) $message ) );

    if ( '' === $line ) {
        return;
    }

    if ( 0 === strpos( $line, 'CLIENT -> SERVER:' ) ) {
        $command = trim( substr( $line, strlen( 'CLIENT -> SERVER:' ) ) );

        if ( ! preg_match( '/^(EHLO|HELO|STARTTLS|AUTH|MAIL FROM|RCPT TO|DATA|QUIT|RSET|\[credentials hidden\])\b/i', $command ) ) {
            return;
        }

        // "AUTH PLAIN <credenciales>" en una sola linea: se oculta lo que va detras del mecanismo.
        if ( preg_match( '/^AUTH\s+(\S+)\s+\S+/i', $command, $matches ) ) {
            $line = 'CLIENT -> SERVER: AUTH ' . $matches[1] . ' [oculto]';
        }
    }

    $GLOBALS['nolantis_smtp_debug'][] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 300 ) : substr( $line, 0, 300 );
}

/* -------------------------------------------------------------------------
 * Remitente
 * ---------------------------------------------------------------------- */

/**
 * Se guarda el remitente que pidio quien envia el correo (p. ej. un formulario de contacto con el
 * email del visitante) antes de sustituirlo, para ponerlo como "Responder a".
 */
function nolantis_filter_mail_from( $from_email ) {
    $GLOBALS['nolantis_smtp_original_from'] = $from_email;
    $settings                               = nolantis_get_smtp_settings();

    if ( ! empty( $settings['enabled'] ) && ! empty( $settings['force_from_email'] ) && ! empty( $settings['from_email'] ) ) {
        return $settings['from_email'];
    }

    return $from_email;
}
add_filter( 'wp_mail_from', 'nolantis_filter_mail_from', 99 );

function nolantis_filter_mail_from_name( $from_name ) {
    $GLOBALS['nolantis_smtp_original_from_name'] = $from_name;
    $settings                                    = nolantis_get_smtp_settings();

    if ( ! empty( $settings['enabled'] ) && ! empty( $settings['from_name'] ) ) {
        return $settings['from_name'];
    }

    return $from_name;
}
add_filter( 'wp_mail_from_name', 'nolantis_filter_mail_from_name', 99 );

/**
 * Remitente por defecto de WordPress (wordpress@dominio), que no tiene sentido como "Responder a".
 */
function nolantis_smtp_is_default_wp_sender( $email ) {
    return 0 === stripos( (string) $email, 'wordpress@' );
}

/* -------------------------------------------------------------------------
 * PHPMailer
 * ---------------------------------------------------------------------- */

/**
 * Aplica los ajustes SMTP a una instancia de PHPMailer (la de wp_mail o la de la prueba de conexion).
 */
function nolantis_apply_smtp_settings( $phpmailer, $settings ) {
    $phpmailer->isSMTP();
    $phpmailer->Host     = $settings['host'];
    $phpmailer->Port     = (int) $settings['port'];
    $phpmailer->SMTPAuth = ! empty( $settings['auth'] );
    $phpmailer->Timeout  = NOLANTIS_SMTP_TIMEOUT;

    if ( $phpmailer->SMTPAuth ) {
        $phpmailer->Username = (string) $settings['username'];
        $phpmailer->Password = (string) $settings['password'];
    }

    if ( 'none' === $settings['encryption'] ) {
        // "Sin cifrado" es sin cifrado: sin esto PHPMailer intenta STARTTLS por su cuenta y falla
        // con servidores internos que anuncian TLS con certificados no validos.
        $phpmailer->SMTPSecure  = '';
        $phpmailer->SMTPAutoTLS = false;
    } else {
        $phpmailer->SMTPSecure = $settings['encryption'];
    }

    if ( 'off' !== $settings['log_mode'] ) {
        $phpmailer->SMTPDebug   = 2;
        $phpmailer->Debugoutput = 'nolantis_smtp_capture_debug';
    }
}

function nolantis_configure_phpmailer( $phpmailer ) {
    nolantis_smtp_reset_debug();

    $settings = nolantis_get_smtp_settings();

    if ( empty( $settings['enabled'] ) || empty( $settings['host'] ) ) {
        return;
    }

    nolantis_apply_smtp_settings( $phpmailer, $settings );

    if ( ! empty( $settings['force_from_email'] ) && ! empty( $settings['from_email'] ) ) {
        $original      = isset( $GLOBALS['nolantis_smtp_original_from'] ) ? (string) $GLOBALS['nolantis_smtp_original_from'] : '';
        $original_name = isset( $GLOBALS['nolantis_smtp_original_from_name'] ) ? (string) $GLOBALS['nolantis_smtp_original_from_name'] : '';

        $phpmailer->From = $settings['from_email'];
        // Remitente del sobre (Return-Path) igual al visible: mejora SPF/DMARC y la entrega.
        $phpmailer->Sender = $settings['from_email'];

        if ( ! empty( $settings['reply_to_original'] )
            && is_email( $original )
            && strtolower( $original ) !== strtolower( $settings['from_email'] )
            && ! nolantis_smtp_is_default_wp_sender( $original )
            && ! $phpmailer->getReplyToAddresses() ) {
            $phpmailer->addReplyTo( $original, $original_name );
        }
    }

    if ( ! empty( $settings['from_name'] ) ) {
        $phpmailer->FromName = $settings['from_name'];
    }
}
add_action( 'phpmailer_init', 'nolantis_configure_phpmailer' );
