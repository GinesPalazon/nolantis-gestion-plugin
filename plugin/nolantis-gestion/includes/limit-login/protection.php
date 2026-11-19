<?php
/**
 * Limit Login: contador de fallos, bloqueos y corte temprano de la autenticacion.
 *
 * Cubre las tres vias de login de WordPress:
 * - wp-login.php (formulario normal o ruta personalizada) y formularios que usan wp_signon (WooCommerce).
 * - XML-RPC (cada intento dispara wp_login_failed).
 * - REST API con contrasenas de aplicacion.
 *
 * Orden de comprobaciones antes de verificar la contrasena:
 * 1. IP bloqueada.
 * 2. Usuario tipico de bot que no existe => bloqueo inmediato de la IP.
 * 3. CAPTCHA (Turnstile), si esta configurado y toca.
 * 4. Cuenta en modo protegido (ataque desde muchas IPs) => solo IPs conocidas, lista blanca o CAPTCHA.
 *
 * Los intentos de una misma IP se serializan con un bloqueo de MySQL (GET_LOCK): sin esto, un bot
 * que lanza decenas de peticiones a la vez probaria muchas mas contrasenas que el limite.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LL_CONCURRENCY_TIMEOUT', 5 );

/**
 * Codigos de error propios que no deben contarse como un fallo nuevo.
 */
function nolantis_ll_get_own_error_codes() {
    return array( 'nolantis_ip_blocked', 'nolantis_too_many_concurrent' );
}

function nolantis_ll_get_request_source() {
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
        return 'xmlrpc';
    }

    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return 'rest';
    }

    return 'login';
}

function nolantis_ll_is_ip_whitelisted( $ip ) {
    return nolantis_ll_ip_in_list( $ip, (array) nolantis_ll_get_setting( 'whitelist' ) );
}

/**
 * Datos del visitante para esta peticion.
 *
 * - ip:          IP detectada.
 * - key:         clave con la que se cuenta y se bloquea (IPv4 o bloque /64 de IPv6).
 * - whitelisted: si esta en la lista blanca.
 * - trackable:   si se le puede bloquear. No se bloquean IPs en lista blanca ni IPs internas
 *                (si todas las visitas llegan con la IP interna de un proxy, bloquearla dejaria
 *                fuera a todo el mundo).
 */
function nolantis_ll_get_client() {
    $ip          = nolantis_get_request_ip();
    $whitelisted = $ip && nolantis_ll_is_ip_whitelisted( $ip );

    return array(
        'ip'          => $ip,
        'key'         => $ip ? nolantis_ll_get_ip_key( $ip ) : '',
        'whitelisted' => $whitelisted,
        'trackable'   => $ip && ! $whitelisted && nolantis_ll_is_enabled() && nolantis_ll_is_public_ip( $ip ),
    );
}

function nolantis_ll_is_user_protection_enabled() {
    return nolantis_ll_is_enabled() && ! empty( nolantis_ll_get_setting( 'user_protection_enabled' ) );
}

/* -------------------------------------------------------------------------
 * Bloqueos con nombre de MySQL/MariaDB (GET_LOCK)
 * ---------------------------------------------------------------------- */

/**
 * @return bool|null true si se obtiene, false si se agota la espera, null si la BD no lo soporta.
 */
function nolantis_ll_get_named_lock( $name, $timeout ) {
    global $wpdb;

    $suppress = $wpdb->suppress_errors( true );
    $result   = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
    $wpdb->suppress_errors( $suppress );

    if ( '1' === (string) $result ) {
        return true;
    }

    return '0' === (string) $result ? false : null;
}

function nolantis_ll_release_named_lock( $name ) {
    global $wpdb;

    $suppress = $wpdb->suppress_errors( true );
    $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    $wpdb->suppress_errors( $suppress );
}

/**
 * Los nombres de GET_LOCK son globales al servidor MySQL: se incluye la BD y el prefijo.
 */
function nolantis_ll_lock_name( $type, $value ) {
    global $wpdb;

    return 'nll' . $type . '_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $value );
}

/**
 * Adquiere el bloqueo de la IP y lo mantiene mientras se verifica la contrasena.
 * Devuelve false solo si se agota la espera.
 */
function nolantis_ll_acquire_ip_lock( $key ) {
    global $nolantis_ll_held_lock;

    if ( ! empty( $nolantis_ll_held_lock ) ) {
        return true;
    }

    $name   = nolantis_ll_lock_name( 'i', $key );
    $result = nolantis_ll_get_named_lock( $name, NOLANTIS_LL_CONCURRENCY_TIMEOUT );

    if ( true === $result ) {
        $nolantis_ll_held_lock = $name;
    }

    return false !== $result;
}

function nolantis_ll_release_ip_lock() {
    global $nolantis_ll_held_lock;

    if ( empty( $nolantis_ll_held_lock ) ) {
        return;
    }

    nolantis_ll_release_named_lock( $nolantis_ll_held_lock );
    $nolantis_ll_held_lock = null;
}
add_action( 'shutdown', 'nolantis_ll_release_ip_lock' );

/* -------------------------------------------------------------------------
 * Mensajes
 * ---------------------------------------------------------------------- */

function nolantis_ll_get_locked_message( $lockout ) {
    $until = isset( $lockout->locked_until ) ? (int) $lockout->locked_until : time();

    return sprintf(
        '<strong>Error:</strong> Demasiados intentos fallidos. Tu IP está bloqueada hasta el %s.',
        esc_html( wp_date( 'd/m/Y \a \l\a\s H:i', $until ) )
    );
}

function nolantis_ll_get_concurrency_message() {
    return '<strong>Error:</strong> Demasiados intentos simultáneos desde tu conexión. Espera unos segundos y vuelve a intentarlo.';
}

function nolantis_ll_get_user_protected_message() {
    $message = '<strong>Error:</strong> Esta cuenta está recibiendo muchos intentos de acceso y está protegida temporalmente. Solo se permite entrar desde dispositivos y redes desde los que ya se haya accedido antes';

    if ( function_exists( 'nolantis_ll_turnstile_is_configured' ) && nolantis_ll_turnstile_is_configured() && nolantis_ll_is_interactive_login_request() ) {
        return $message . ' o completando la verificación de seguridad.';
    }

    return $message . '. Inténtalo de nuevo más tarde.';
}

/* -------------------------------------------------------------------------
 * Corte temprano
 * ---------------------------------------------------------------------- */

/**
 * Comprueba, antes de verificar la contrasena, si el visitante puede intentar el acceso.
 * Deja adquirido el bloqueo de la IP cuando el intento sigue adelante.
 *
 * @param string $login        Usuario o email escrito.
 * @param bool   $allow_captcha Si en esta via se puede pedir CAPTCHA (no en XML-RPC/REST).
 * @return true|WP_Error
 */
function nolantis_ll_check_attempt_allowed( $login, $allow_captcha = true ) {
    $client = nolantis_ll_get_client();
    $source = nolantis_ll_get_request_source();

    // 1. IP bloqueada.
    if ( $client['trackable'] ) {
        if ( ! nolantis_ll_acquire_ip_lock( $client['key'] ) ) {
            return new WP_Error( 'nolantis_too_many_concurrent', nolantis_ll_get_concurrency_message() );
        }

        $lockout = nolantis_ll_get_active_lockout( $client['key'] );

        if ( $lockout ) {
            nolantis_ll_increment_blocked_attempts( $lockout->id );
            nolantis_ll_release_ip_lock();

            return new WP_Error( 'nolantis_ip_blocked', nolantis_ll_get_locked_message( $lockout ) );
        }

        // 2. Usuario tipico de bot que no existe: bloqueo inmediato.
        if ( nolantis_ll_is_bot_username( $login ) ) {
            nolantis_ll_log_event( $client['key'], 'failed', nolantis_ll_get_login_subject( $login ), $source );
            $lockout = nolantis_ll_create_lockout( $client['key'], $login, $source, 'bot_user' );
            nolantis_ll_release_ip_lock();

            return new WP_Error( 'nolantis_ip_blocked', nolantis_ll_get_locked_message( $lockout ) );
        }
    }

    // 3. CAPTCHA.
    $captcha_passed = false;

    if ( $allow_captcha && ! $client['whitelisted'] && function_exists( 'nolantis_ll_turnstile_applies' ) && nolantis_ll_turnstile_applies() ) {
        if ( nolantis_ll_turnstile_is_required( $client ) ) {
            if ( ! nolantis_ll_turnstile_verify_request( $client['ip'] ) ) {
                return new WP_Error( 'nolantis_captcha_failed', '<strong>Error:</strong> Completa la verificación de seguridad para continuar.' );
            }

            $captcha_passed = true;
        }
    }

    // 4. Cuenta en modo protegido.
    if ( nolantis_ll_is_user_protection_enabled() && ! $client['whitelisted'] && ! $captcha_passed ) {
        $protection = nolantis_ll_get_active_user_protection( nolantis_ll_get_login_subject( $login ) );

        if ( $protection && ! nolantis_ll_is_known_ip_for_login( $login, $client['key'] ) ) {
            nolantis_ll_increment_blocked_attempts( $protection->id );

            if ( $allow_captcha && function_exists( 'nolantis_ll_turnstile_flag_client' ) ) {
                nolantis_ll_turnstile_flag_client( $client );
            }

            return new WP_Error( 'nolantis_user_protected', nolantis_ll_get_user_protected_message() );
        }
    }

    return true;
}

/**
 * Prioridad 1: si el intento no esta permitido se retiran los autenticadores del core para esta
 * peticion, asi no se gasta CPU en verificar hashes y una contrasena correcta tampoco entra.
 */
function nolantis_ll_authenticate_block( $user, $username, $password ) {
    if ( $user instanceof WP_User ) {
        return $user;
    }

    // wp-login.php llama a wp_signon() incluso al mostrar el formulario vacio: eso no es un intento.
    if ( empty( $username ) && empty( $password ) ) {
        return $user;
    }

    $allowed = nolantis_ll_check_attempt_allowed( $username, nolantis_ll_is_interactive_login_request() );

    if ( true === $allowed ) {
        return $user;
    }

    remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
    remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
    remove_filter( 'authenticate', 'wp_authenticate_application_password', 20 );

    return $allowed;
}
add_filter( 'authenticate', 'nolantis_ll_authenticate_block', 1, 3 );

/**
 * REST API: las contrasenas de aplicacion se validan fuera del filtro 'authenticate'.
 * Solo se aplica el bloqueo por IP: una contrasena de aplicacion son 24 caracteres aleatorios y no
 * se puede adivinar por fuerza bruta, asi que el modo protegido por usuario no hace falta aqui.
 */
function nolantis_ll_block_application_password( $is_api_request ) {
    if ( ! $is_api_request ) {
        return $is_api_request;
    }

    $client = nolantis_ll_get_client();

    if ( ! $client['trackable'] ) {
        return $is_api_request;
    }

    if ( ! nolantis_ll_acquire_ip_lock( $client['key'] ) ) {
        return false;
    }

    $lockout = nolantis_ll_get_active_lockout( $client['key'] );

    if ( $lockout ) {
        nolantis_ll_increment_blocked_attempts( $lockout->id );
        nolantis_ll_release_ip_lock();

        return false;
    }

    return $is_api_request;
}
add_filter( 'application_password_is_api_request', 'nolantis_ll_block_application_password', 99 );

/* -------------------------------------------------------------------------
 * Registro de fallos y creacion de bloqueos
 * ---------------------------------------------------------------------- */

/**
 * Registra un fallo y crea el bloqueo de IP o el modo protegido de la cuenta si toca.
 *
 * @param string $source login | xmlrpc | rest
 */
function nolantis_ll_register_failure( $login, $source ) {
    if ( ! nolantis_ll_is_enabled() ) {
        return;
    }

    $client  = nolantis_ll_get_client();
    $subject = nolantis_ll_get_login_subject( $login );

    nolantis_ll_log_event( $client['key'], 'failed', $subject, $source );

    // Bloqueo por IP. Normalmente el bloqueo de la IP ya se adquirio antes de comprobar la contrasena.
    if ( $client['trackable'] && nolantis_ll_acquire_ip_lock( $client['key'] ) ) {
        if ( ! nolantis_ll_get_active_lockout( $client['key'] )
            && nolantis_ll_count_recent_failures( $client['key'] ) >= (int) nolantis_ll_get_setting( 'max_attempts' ) ) {
            nolantis_ll_create_lockout( $client['key'], $subject, $source );
        }
    }

    nolantis_ll_release_ip_lock();

    // Modo protegido de la cuenta (ataques repartidos entre muchas IPs).
    if ( nolantis_ll_is_user_protection_enabled() && '' !== $subject ) {
        nolantis_ll_update_user_protection( $subject, $client, $source );
    }
}

function nolantis_ll_update_user_protection( $subject, $client, $source ) {
    $lock_name = nolantis_ll_lock_name( 'u', $subject );
    $locked    = nolantis_ll_get_named_lock( $lock_name, 2 );

    if ( false === $locked ) {
        return;
    }

    $active = nolantis_ll_get_active_user_protection( $subject );

    if ( $active ) {
        nolantis_ll_extend_user_protection( $active->id );
    } elseif ( nolantis_ll_count_recent_user_failures( $subject ) >= (int) nolantis_ll_get_setting( 'user_max_failures' ) ) {
        nolantis_ll_create_user_protection( $subject, $client['key'], $source );
    }

    if ( true === $locked ) {
        nolantis_ll_release_named_lock( $lock_name );
    }
}

function nolantis_ll_handle_failed_login( $username, $error = null ) {
    // Los intentos rechazados por el propio plugin no cuentan como fallos nuevos.
    if ( is_wp_error( $error ) && in_array( $error->get_error_code(), nolantis_ll_get_own_error_codes(), true ) ) {
        nolantis_ll_release_ip_lock();
        return;
    }

    nolantis_ll_register_failure( $username, nolantis_ll_get_request_source() );
}
add_action( 'wp_login_failed', 'nolantis_ll_handle_failed_login', 10, 2 );

function nolantis_ll_handle_failed_application_password( $error ) {
    // En XML-RPC el fallo ya lo registra wp_login_failed; asi no se cuenta dos veces.
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
        return;
    }

    $username = isset( $_SERVER['PHP_AUTH_USER'] ) ? wp_unslash( $_SERVER['PHP_AUTH_USER'] ) : '';

    nolantis_ll_register_failure( $username, 'rest' );
}
add_action( 'application_password_failed_authentication', 'nolantis_ll_handle_failed_application_password' );

/**
 * Registra los accesos correctos y recuerda la IP como conocida para ese usuario.
 *
 * No reinicia el contador de fallos a proposito: si lo hiciera, un atacante con una cuenta propia
 * (p. ej. cliente de una tienda) podria intercalar logins validos entre sus intentos.
 */
function nolantis_ll_handle_successful_login( $user_login, $user = null ) {
    $client = nolantis_ll_get_client();

    if ( nolantis_ll_is_enabled() ) {
        nolantis_ll_log_event( $client['key'], 'success', $user_login, nolantis_ll_get_request_source() );
    }

    if ( $user instanceof WP_User ) {
        nolantis_ll_remember_ip( $user->ID, $client['key'] );
    }

    nolantis_ll_release_ip_lock();
}
add_action( 'wp_login', 'nolantis_ll_handle_successful_login', 10, 2 );

/* -------------------------------------------------------------------------
 * Mensajes del formulario
 * ---------------------------------------------------------------------- */

function nolantis_ll_login_errors( $errors ) {
    if ( ! is_wp_error( $errors ) ) {
        return $errors;
    }

    $client = nolantis_ll_get_client();

    if ( ! $client['trackable'] ) {
        return $errors;
    }

    $credential_codes = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'nolantis_invalid_credentials', 'authentication_failed' );
    $lockout          = nolantis_ll_get_active_lockout( $client['key'] );

    if ( $lockout ) {
        foreach ( array_merge( $credential_codes, array( 'nolantis_captcha_failed', 'nolantis_user_protected' ) ) as $code ) {
            $errors->remove( $code );
        }

        if ( ! in_array( 'nolantis_ip_blocked', $errors->get_error_codes(), true ) ) {
            $errors->add( 'nolantis_ip_blocked', nolantis_ll_get_locked_message( $lockout ) );
        }

        return $errors;
    }

    if ( ! array_intersect( $credential_codes, $errors->get_error_codes() ) ) {
        return $errors;
    }

    $remaining = (int) nolantis_ll_get_setting( 'max_attempts' ) - nolantis_ll_count_recent_failures( $client['key'] );

    if ( $remaining > 0 ) {
        $errors->add(
            'nolantis_attempts_remaining',
            sprintf(
                1 === $remaining ? 'Te queda %d intento antes de que tu IP quede bloqueada temporalmente.' : 'Te quedan %d intentos antes de que tu IP quede bloqueada temporalmente.',
                $remaining
            )
        );
    }

    return $errors;
}
add_filter( 'wp_login_errors', 'nolantis_ll_login_errors' );

function nolantis_ll_shake_error_codes( $codes ) {
    return array_merge( $codes, nolantis_ll_get_own_error_codes(), array( 'nolantis_invalid_credentials', 'nolantis_captcha_failed', 'nolantis_user_protected' ) );
}
add_filter( 'shake_error_codes', 'nolantis_ll_shake_error_codes' );
