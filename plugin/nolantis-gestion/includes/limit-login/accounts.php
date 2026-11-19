<?php
/**
 * Limit Login: utilidades de cuentas.
 *
 * - Identificar el usuario atacado aunque se escriba su email.
 * - IPs conocidas de cada usuario (desde las que ya ha entrado correctamente).
 * - Usuarios tipicos de bots (admin, root, test...).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LL_KNOWN_IPS_META', '_nolantis_known_ips' );
define( 'NOLANTIS_LL_KNOWN_IPS_MAX', 10 );

/**
 * Busca un usuario por login o email, igual que hace el formulario de acceso.
 *
 * @return WP_User|false
 */
function nolantis_ll_find_user( $login ) {
    $login = trim( (string) $login );

    if ( '' === $login ) {
        return false;
    }

    $user = get_user_by( 'login', $login );

    if ( ! $user && is_email( $login ) ) {
        $user = get_user_by( 'email', $login );
    }

    return $user ? $user : false;
}

/**
 * Nombre con el que se cuentan los intentos contra una cuenta: el login real si existe
 * (asi da igual que se ataque por usuario o por email) o lo escrito, en minusculas, si no existe.
 */
function nolantis_ll_get_login_subject( $login ) {
    $user = nolantis_ll_find_user( $login );

    if ( $user ) {
        return $user->user_login;
    }

    return nolantis_ll_clean_username( strtolower( trim( (string) $login ) ) );
}

/* -------------------------------------------------------------------------
 * IPs conocidas
 * ---------------------------------------------------------------------- */

/**
 * @return string[] Claves de IP (IPv4 o bloque IPv6 /64), la mas reciente primero.
 */
function nolantis_ll_get_known_ips( $user_id ) {
    $ips = get_user_meta( $user_id, NOLANTIS_LL_KNOWN_IPS_META, true );

    return is_array( $ips ) ? $ips : array();
}

function nolantis_ll_is_known_ip( $user_id, $ip_key ) {
    return '' !== (string) $ip_key && in_array( $ip_key, nolantis_ll_get_known_ips( $user_id ), true );
}

function nolantis_ll_remember_ip( $user_id, $ip_key ) {
    if ( '' === (string) $ip_key ) {
        return;
    }

    $ips = array_values( array_diff( nolantis_ll_get_known_ips( $user_id ), array( $ip_key ) ) );
    array_unshift( $ips, $ip_key );

    update_user_meta( $user_id, NOLANTIS_LL_KNOWN_IPS_META, array_slice( $ips, 0, NOLANTIS_LL_KNOWN_IPS_MAX ) );
}

/**
 * Indica si la IP actual es de confianza para la cuenta indicada (lo escrito en el formulario).
 */
function nolantis_ll_is_known_ip_for_login( $login, $ip_key ) {
    $user = nolantis_ll_find_user( $login );

    return $user ? nolantis_ll_is_known_ip( $user->ID, $ip_key ) : false;
}

/* -------------------------------------------------------------------------
 * Usuarios tipicos de bots
 * ---------------------------------------------------------------------- */

/**
 * true si lo escrito es un usuario tipico de bot que NO existe en esta web.
 * Si existe un usuario real con ese nombre nunca se considera bot.
 */
function nolantis_ll_is_bot_username( $login ) {
    if ( empty( nolantis_ll_get_setting( 'bot_usernames_enabled' ) ) ) {
        return false;
    }

    $name = strtolower( trim( (string) $login ) );

    if ( '' === $name || ! in_array( $name, (array) nolantis_ll_get_setting( 'bot_usernames' ), true ) ) {
        return false;
    }

    return ! nolantis_ll_find_user( $login );
}

/**
 * Usuarios reales cuyo login esta en la lista de usuarios de bots (para avisar en el panel).
 *
 * @return string[]
 */
function nolantis_ll_get_existing_bot_usernames() {
    $found = array();

    foreach ( (array) nolantis_ll_get_setting( 'bot_usernames' ) as $name ) {
        if ( get_user_by( 'login', $name ) ) {
            $found[] = $name;
        }
    }

    return $found;
}
