<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LIMIT_LOGIN_MAX_ATTEMPTS', 3 );
define( 'NOLANTIS_LIMIT_LOGIN_FIRST_LOCK', DAY_IN_SECONDS );
define( 'NOLANTIS_LIMIT_LOGIN_SECOND_LOCK', 7 * DAY_IN_SECONDS );
define( 'NOLANTIS_LIMIT_LOGIN_REPEAT_WINDOW', 8 * DAY_IN_SECONDS );
define( 'NOLANTIS_LIMIT_LOGIN_NOTICE_TTL', 5 * MINUTE_IN_SECONDS );

function nolantis_get_limit_login_ip_hash( $ip ) {
    return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
}

function nolantis_get_limit_login_attempts_key( $ip ) {
    return 'nolantis_ll_attempts_' . nolantis_get_limit_login_ip_hash( $ip );
}

function nolantis_get_limit_login_lock_key( $ip ) {
    return 'nolantis_ll_lock_' . nolantis_get_limit_login_ip_hash( $ip );
}

function nolantis_get_limit_login_repeat_key( $ip ) {
    return 'nolantis_ll_repeat_' . nolantis_get_limit_login_ip_hash( $ip );
}

function nolantis_get_limit_login_notice_key( $ip ) {
    return 'nolantis_ll_notice_' . nolantis_get_limit_login_ip_hash( $ip );
}

function nolantis_get_limit_login_attempts( $ip ) {
    if ( empty( $ip ) ) {
        return 0;
    }

    return (int) get_transient( nolantis_get_limit_login_attempts_key( $ip ) );
}

function nolantis_get_limit_login_remaining_attempts( $ip ) {
    return max( 0, NOLANTIS_LIMIT_LOGIN_MAX_ATTEMPTS - nolantis_get_limit_login_attempts( $ip ) );
}

function nolantis_set_limit_login_notice( $ip, $message, $type = 'error' ) {
    if ( empty( $ip ) ) {
        return;
    }

    set_transient(
        nolantis_get_limit_login_notice_key( $ip ),
        array(
            'message' => wp_kses_post( $message ),
            'type'    => sanitize_key( $type ),
        ),
        NOLANTIS_LIMIT_LOGIN_NOTICE_TTL
    );
}

function nolantis_get_limit_login_lock( $ip ) {
    if ( empty( $ip ) ) {
        return false;
    }

    $lock = get_transient( nolantis_get_limit_login_lock_key( $ip ) );

    if ( ! is_array( $lock ) || empty( $lock['locked_until'] ) ) {
        return false;
    }

    return $lock;
}

function nolantis_limit_login_is_locked( $ip ) {
    return (bool) nolantis_get_limit_login_lock( $ip );
}

function nolantis_limit_login_block_authentication( $user ) {
    $ip   = nolantis_get_request_ip();
    $lock = nolantis_get_limit_login_lock( $ip );

    if ( ! $lock ) {
        return $user;
    }

    return new WP_Error( 'nolantis_ip_blocked', nolantis_get_limit_login_locked_message( $lock ) );
}
add_filter( 'authenticate', 'nolantis_limit_login_block_authentication', 999 );

function nolantis_get_limit_login_locked_message( $lock ) {
    $unlock_at = isset( $lock['locked_until'] ) ? (int) $lock['locked_until'] : time();

    return sprintf(
        'Demasiados intentos fallidos. Tu IP esta bloqueada hasta %s.',
        wp_date( 'Y-m-d H:i:s', $unlock_at )
    );
}

function nolantis_register_failed_login() {
    $ip = nolantis_get_request_ip();

    if ( empty( $ip ) || nolantis_limit_login_is_locked( $ip ) ) {
        return;
    }

    $attempts_key = nolantis_get_limit_login_attempts_key( $ip );
    $repeat_key   = nolantis_get_limit_login_repeat_key( $ip );
    $lock_key     = nolantis_get_limit_login_lock_key( $ip );
    $attempts     = (int) get_transient( $attempts_key );

    $attempts++;
    set_transient( $attempts_key, $attempts, DAY_IN_SECONDS );

    if ( $attempts < NOLANTIS_LIMIT_LOGIN_MAX_ATTEMPTS ) {
        $remaining = max( 0, NOLANTIS_LIMIT_LOGIN_MAX_ATTEMPTS - $attempts );

        nolantis_set_limit_login_notice(
            $ip,
            sprintf(
                'Usuario o contrasena incorrectos. Te %s %d %s antes del bloqueo.',
                1 === $remaining ? 'queda' : 'quedan',
                $remaining,
                1 === $remaining ? 'intento' : 'intentos'
            )
        );

        return;
    }

    $is_repeat    = (bool) get_transient( $repeat_key );
    $lock_seconds = $is_repeat ? NOLANTIS_LIMIT_LOGIN_SECOND_LOCK : NOLANTIS_LIMIT_LOGIN_FIRST_LOCK;

    set_transient(
        $lock_key,
        array(
            'locked_until' => time() + $lock_seconds,
            'lock_seconds' => $lock_seconds,
        ),
        $lock_seconds
    );

    delete_transient( $attempts_key );

    nolantis_set_limit_login_notice(
        $ip,
        nolantis_get_limit_login_locked_message(
            array(
                'locked_until' => time() + $lock_seconds,
                'lock_seconds' => $lock_seconds,
            )
        )
    );

    if ( $is_repeat ) {
        delete_transient( $repeat_key );
        return;
    }

    set_transient( $repeat_key, 1, NOLANTIS_LIMIT_LOGIN_REPEAT_WINDOW );
}
add_action( 'wp_login_failed', 'nolantis_register_failed_login' );

function nolantis_limit_login_errors( $errors ) {
    $ip = nolantis_get_request_ip();

    if ( empty( $ip ) ) {
        return $errors;
    }

    $lock = nolantis_get_limit_login_lock( $ip );

    if ( $lock ) {
        $errors->remove( 'invalid_username' );
        $errors->remove( 'incorrect_password' );
        $errors->add( 'nolantis_ip_blocked', nolantis_get_limit_login_locked_message( $lock ) );
        return $errors;
    }

    $notice = get_transient( nolantis_get_limit_login_notice_key( $ip ) );

    if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
        return $errors;
    }

    delete_transient( nolantis_get_limit_login_notice_key( $ip ) );
    $errors->add( 'nolantis_attempts_remaining', $notice['message'] );

    return $errors;
}
add_filter( 'wp_login_errors', 'nolantis_limit_login_errors' );

function nolantis_clear_failed_login_attempts() {
    $ip = nolantis_get_request_ip();

    if ( empty( $ip ) ) {
        return;
    }

    delete_transient( nolantis_get_limit_login_attempts_key( $ip ) );
    delete_transient( nolantis_get_limit_login_notice_key( $ip ) );
}
add_action( 'wp_login', 'nolantis_clear_failed_login_attempts' );
