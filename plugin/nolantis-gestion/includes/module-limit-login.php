<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LIMIT_LOGIN_MAX_ATTEMPTS', 3 );
define( 'NOLANTIS_LIMIT_LOGIN_FIRST_LOCK', DAY_IN_SECONDS );
define( 'NOLANTIS_LIMIT_LOGIN_SECOND_LOCK', 7 * DAY_IN_SECONDS );
define( 'NOLANTIS_LIMIT_LOGIN_REPEAT_WINDOW', 8 * DAY_IN_SECONDS );

function nolantis_get_limit_login_ip_hash( $ip ) {
    return md5( $ip );
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

function nolantis_limit_login_before_authenticate( $user ) {
    $ip   = nolantis_get_request_ip();
    $lock = nolantis_get_limit_login_lock( $ip );

    if ( ! $lock ) {
        return $user;
    }

    return new WP_Error(
        'nolantis_ip_blocked',
        sprintf(
            'Acceso bloqueado temporalmente para esta IP hasta %s.',
            wp_date( 'Y-m-d H:i:s', (int) $lock['locked_until'] )
        )
    );
}
add_filter( 'authenticate', 'nolantis_limit_login_before_authenticate', 5 );

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

    if ( $is_repeat ) {
        delete_transient( $repeat_key );
        return;
    }

    set_transient( $repeat_key, 1, NOLANTIS_LIMIT_LOGIN_REPEAT_WINDOW );
}
add_action( 'wp_login_failed', 'nolantis_register_failed_login' );

function nolantis_clear_failed_login_attempts() {
    $ip = nolantis_get_request_ip();

    if ( empty( $ip ) ) {
        return;
    }

    delete_transient( nolantis_get_limit_login_attempts_key( $ip ) );
}
add_action( 'wp_login', 'nolantis_clear_failed_login_attempts' );
