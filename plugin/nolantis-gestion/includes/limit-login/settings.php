<?php
/**
 * Limit Login: ajustes, valores por defecto y saneado.
 *
 * Todos los ajustes del modulo viven en una sola opcion. Cada pestana del panel
 * envia solo sus campos y el saneado actualiza unicamente las claves recibidas, asi guardar una
 * pestana no borra los ajustes de las demas.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LL_OPTION', 'nolantis_limit_login_settings' );

/**
 * Indica si la proteccion esta desactivada de emergencia desde wp-config.php.
 */
function nolantis_ll_is_emergency_disabled() {
    return defined( 'NOLANTIS_LIMIT_LOGIN_DISABLE' ) && NOLANTIS_LIMIT_LOGIN_DISABLE;
}

function nolantis_ll_get_default_bot_usernames() {
    return array( 'admin', 'administrator', 'administrador', 'root', 'test', 'tester', 'demo', 'user', 'usuario', 'guest', 'wpadmin', 'wp-admin', 'webmaster', 'support', 'info', 'manager', 'adm', 'sysadmin', 'superadmin', 'backup' );
}

function nolantis_ll_get_default_settings() {
    return array(
        // Proteccion por IP.
        'enabled'                    => 1,
        'max_attempts'               => 5,
        'window_minutes'             => 15,
        'lockout_1_minutes'          => 30,
        'lockout_2_hours'            => 24,
        'lockout_3_days'             => 7,
        'reincidence_hours'          => 24,
        // Proteccion por usuario (botnets).
        'user_protection_enabled'    => 1,
        'user_max_failures'          => 10,
        'user_window_minutes'        => 60,
        'user_protection_minutes'    => 60,
        // Usuarios tipicos de bots.
        'bot_usernames_enabled'      => 1,
        'bot_usernames'              => nolantis_ll_get_default_bot_usernames(),
        // Recuperacion de contrasena.
        'lostpw_limit_enabled'       => 1,
        'lostpw_ip_max'              => 3,
        'lostpw_ip_window_minutes'   => 15,
        'lostpw_user_max'            => 3,
        'lostpw_user_window_minutes' => 60,
        // Deteccion de IP.
        'ip_source'                  => 'auto',
        'trusted_proxies'            => array(),
        'whitelist'                  => array(),
        // Endurecimiento.
        'enable_xmlrpc'              => 0,
        'block_user_enumeration'     => 1,
        'generic_login_errors'       => 1,
        // Registro.
        'log_retention_days'         => 30,
        // Avisos por email (desactivados por defecto: se activan a mano en el panel).
        'alert_email'                => '',
        'alert_user_attack'          => 0,
        'alert_long_lockout'         => 0,
        'alert_admin_new_ip'         => 0,
        'alert_integrity'            => 0,
        // Cloudflare Turnstile.
        'turnstile_site_key'         => '',
        'turnstile_secret_key'       => '',
        'turnstile_after_failures'   => 2,
    );
}

function nolantis_ll_get_checkbox_keys() {
    return array(
        'enabled',
        'user_protection_enabled',
        'bot_usernames_enabled',
        'lostpw_limit_enabled',
        'enable_xmlrpc',
        'block_user_enumeration',
        'generic_login_errors',
        'alert_user_attack',
        'alert_long_lockout',
        'alert_admin_new_ip',
        'alert_integrity',
    );
}

/**
 * Limites validos de cada ajuste numerico: array( minimo, maximo ).
 */
function nolantis_ll_get_numeric_bounds() {
    return array(
        'max_attempts'               => array( 2, 50 ),
        'window_minutes'             => array( 1, 1440 ),
        'lockout_1_minutes'          => array( 1, 1440 ),
        'lockout_2_hours'            => array( 1, 720 ),
        'lockout_3_days'             => array( 1, 365 ),
        'reincidence_hours'          => array( 1, 720 ),
        'user_max_failures'          => array( 3, 500 ),
        'user_window_minutes'        => array( 5, 1440 ),
        'user_protection_minutes'    => array( 5, 1440 ),
        'lostpw_ip_max'              => array( 1, 50 ),
        'lostpw_ip_window_minutes'   => array( 1, 1440 ),
        'lostpw_user_max'            => array( 1, 50 ),
        'lostpw_user_window_minutes' => array( 1, 1440 ),
        'log_retention_days'         => array( 7, 365 ),
        'turnstile_after_failures'   => array( 0, 50 ),
    );
}

function nolantis_ll_get_settings() {
    $settings = get_option( NOLANTIS_LL_OPTION, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    return wp_parse_args( $settings, nolantis_ll_get_default_settings() );
}

function nolantis_ll_get_setting( $key ) {
    $settings = nolantis_ll_get_settings();

    return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * Indica si la proteccion contra fuerza bruta esta activa.
 */
function nolantis_ll_is_enabled() {
    if ( nolantis_ll_is_emergency_disabled() ) {
        return false;
    }

    return ! empty( nolantis_ll_get_setting( 'enabled' ) );
}

/**
 * Duracion en segundos del bloqueo segun el nivel (1, 2 o 3).
 */
function nolantis_ll_get_lockout_duration( $level ) {
    $settings = nolantis_ll_get_settings();

    if ( $level >= 3 ) {
        return (int) $settings['lockout_3_days'] * DAY_IN_SECONDS;
    }

    if ( 2 === $level ) {
        return (int) $settings['lockout_2_hours'] * HOUR_IN_SECONDS;
    }

    return (int) $settings['lockout_1_minutes'] * MINUTE_IN_SECONDS;
}

/**
 * Convierte un textarea (una entrada por linea, admite comas) en una lista de IPs/rangos validos.
 *
 * @param string $raw     Texto introducido.
 * @param array  $invalid Recibe las entradas que no son validas.
 * @return string[]
 */
function nolantis_ll_parse_ip_list( $raw, &$invalid = array() ) {
    $invalid = array();
    $result  = array();
    $entries = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

    foreach ( $entries as $entry ) {
        $entry = trim( $entry );

        if ( '' === $entry || 0 === strpos( $entry, '#' ) ) {
            continue;
        }

        $normalized = nolantis_ll_normalize_ip_or_range( $entry );

        if ( false === $normalized ) {
            $invalid[] = $entry;
            continue;
        }

        $result[] = $normalized;
    }

    return array_values( array_unique( $result ) );
}

/**
 * Convierte un textarea en una lista de nombres de usuario (minusculas, sin duplicados).
 *
 * @return string[]
 */
function nolantis_ll_parse_username_list( $raw ) {
    $result = array();

    foreach ( preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
        $entry = strtolower( sanitize_user( $entry, true ) );

        if ( '' !== $entry ) {
            $result[] = $entry;
        }
    }

    return array_values( array_unique( $result ) );
}

/**
 * add_settings_error() solo existe en el admin; asi el saneado no rompe si se llama desde otro contexto.
 */
function nolantis_ll_add_settings_error( $setting, $code, $message, $type = 'error' ) {
    if ( function_exists( 'add_settings_error' ) ) {
        add_settings_error( $setting, $code, $message, $type );
    }
}

function nolantis_ll_sanitize_settings( $input ) {
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    // Se parte de los ajustes actuales y solo se actualizan las claves recibidas.
    $sanitized = nolantis_ll_get_settings();

    foreach ( nolantis_ll_get_checkbox_keys() as $key ) {
        if ( array_key_exists( $key, $input ) ) {
            $sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
        }
    }

    if ( array_key_exists( 'ip_source', $input ) ) {
        $ip_source              = sanitize_key( $input['ip_source'] );
        $sanitized['ip_source'] = in_array( $ip_source, array( 'auto', 'remote_addr', 'proxy' ), true ) ? $ip_source : 'auto';
    }

    foreach ( nolantis_ll_get_numeric_bounds() as $key => $bounds ) {
        if ( ! array_key_exists( $key, $input ) ) {
            continue;
        }

        $value = absint( $input[ $key ] );

        if ( $value < $bounds[0] || $value > $bounds[1] ) {
            nolantis_ll_add_settings_error(
                NOLANTIS_LL_OPTION,
                'nolantis_ll_out_of_range_' . $key,
                sprintf( 'Un valor numérico estaba fuera de rango (%1$d-%2$d) y se ha ajustado.', $bounds[0], $bounds[1] ),
                'warning'
            );
            $value = min( max( $value, $bounds[0] ), $bounds[1] );
        }

        $sanitized[ $key ] = $value;
    }

    foreach ( array( 'whitelist' => 'Lista blanca', 'trusted_proxies' => 'Proxies de confianza' ) as $key => $label ) {
        if ( ! array_key_exists( $key, $input ) ) {
            continue;
        }

        $raw     = wp_unslash( $input[ $key ] );
        $raw     = is_array( $raw ) ? implode( "\n", $raw ) : $raw;
        $invalid = array();

        $sanitized[ $key ] = nolantis_ll_parse_ip_list( $raw, $invalid );

        if ( ! empty( $invalid ) ) {
            nolantis_ll_add_settings_error(
                NOLANTIS_LL_OPTION,
                'nolantis_ll_invalid_' . $key,
                sprintf( '%1$s: se han descartado entradas no válidas: %2$s', $label, implode( ', ', array_map( 'sanitize_text_field', $invalid ) ) ),
                'warning'
            );
        }
    }

    if ( array_key_exists( 'bot_usernames', $input ) ) {
        $raw                        = wp_unslash( $input['bot_usernames'] );
        $sanitized['bot_usernames'] = nolantis_ll_parse_username_list( is_array( $raw ) ? implode( "\n", $raw ) : $raw );
    }

    if ( array_key_exists( 'alert_email', $input ) ) {
        $email = sanitize_email( wp_unslash( $input['alert_email'] ) );

        if ( '' !== trim( (string) $input['alert_email'] ) && ! is_email( $email ) ) {
            nolantis_ll_add_settings_error( NOLANTIS_LL_OPTION, 'nolantis_ll_invalid_alert_email', 'El email para avisos no es válido; se usará el email del administrador.', 'warning' );
            $email = '';
        }

        $sanitized['alert_email'] = $email;
    }

    foreach ( array( 'turnstile_site_key', 'turnstile_secret_key' ) as $key ) {
        if ( array_key_exists( $key, $input ) ) {
            $sanitized[ $key ] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) wp_unslash( $input[ $key ] ) );
        }
    }

    if ( ( '' === $sanitized['turnstile_site_key'] ) !== ( '' === $sanitized['turnstile_secret_key'] ) ) {
        nolantis_ll_add_settings_error( NOLANTIS_LL_OPTION, 'nolantis_ll_turnstile_incomplete', 'Turnstile necesita las dos claves (de sitio y secreta). Mientras falte una, no se mostrará el CAPTCHA.', 'warning' );
    }

    if ( 'proxy' === $sanitized['ip_source'] && empty( $sanitized['trusted_proxies'] ) ) {
        nolantis_ll_add_settings_error(
            NOLANTIS_LL_OPTION,
            'nolantis_ll_proxy_without_list',
            'Has elegido "Proxy de confianza" sin indicar ninguna IP de proxy. Hasta que lo hagas se usará la detección automática.',
            'warning'
        );
    }

    return $sanitized;
}

function nolantis_ll_register_settings() {
    register_setting(
        'nolantis_limit_login_group',
        NOLANTIS_LL_OPTION,
        array(
            'sanitize_callback' => 'nolantis_ll_sanitize_settings',
            'default'           => nolantis_ll_get_default_settings(),
        )
    );
}
add_action( 'admin_init', 'nolantis_ll_register_settings' );
