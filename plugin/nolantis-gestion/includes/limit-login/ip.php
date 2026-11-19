<?php
/**
 * Limit Login: deteccion de la IP real del visitante y utilidades de rangos IP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normaliza una IP (IPv4 o IPv6). Las IPv4 mapeadas en IPv6 (::ffff:1.2.3.4) se devuelven como IPv4.
 *
 * @return string|false
 */
function nolantis_ll_normalize_ip( $ip ) {
    $ip = trim( (string) $ip );

    if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return false;
    }

    $packed = inet_pton( $ip );

    if ( false === $packed ) {
        return false;
    }

    $normalized = inet_ntop( $packed );

    if ( 0 === stripos( $normalized, '::ffff:' ) && false !== filter_var( substr( $normalized, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
        return substr( $normalized, 7 );
    }

    return $normalized;
}

/**
 * Valida y normaliza una IP suelta o un rango CIDR (p. ej. 192.168.1.0/24 o 2001:db8::/32).
 *
 * @return string|false
 */
function nolantis_ll_normalize_ip_or_range( $entry ) {
    $entry = trim( (string) $entry );

    if ( false === strpos( $entry, '/' ) ) {
        return nolantis_ll_normalize_ip( $entry );
    }

    list( $subnet, $bits ) = explode( '/', $entry, 2 );

    $subnet = nolantis_ll_normalize_ip( $subnet );

    if ( false === $subnet || ! ctype_digit( $bits ) ) {
        return false;
    }

    $bits     = (int) $bits;
    $max_bits = false !== strpos( $subnet, ':' ) ? 128 : 32;

    if ( $bits > $max_bits ) {
        return false;
    }

    return $subnet . '/' . $bits;
}

/**
 * Comprueba si una IP coincide con una IP suelta o con un rango CIDR.
 */
function nolantis_ll_ip_matches( $ip, $range ) {
    $ip_bin = @inet_pton( (string) $ip );

    if ( false === $ip_bin ) {
        return false;
    }

    if ( false === strpos( $range, '/' ) ) {
        return $ip_bin === @inet_pton( $range );
    }

    list( $subnet, $bits ) = explode( '/', $range, 2 );

    $subnet_bin = @inet_pton( $subnet );

    if ( false === $subnet_bin || strlen( $subnet_bin ) !== strlen( $ip_bin ) ) {
        return false;
    }

    $bits  = (int) $bits;
    $bytes = intdiv( $bits, 8 );
    $rest  = $bits % 8;

    if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
        return false;
    }

    if ( 0 === $rest ) {
        return true;
    }

    $mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );

    return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
}

/**
 * Indica si la IP es publica (no privada, loopback, link-local ni reservada).
 */
function nolantis_ll_is_public_ip( $ip ) {
    return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Clave con la que se cuentan fallos y se bloquea.
 *
 * IPv4: la propia IP. IPv6: su bloque /64, porque un solo atacante suele disponer de un /64 completo
 * (miles de millones de direcciones) y podria usar una distinta en cada intento.
 */
function nolantis_ll_get_ip_key( $ip ) {
    if ( false === strpos( (string) $ip, ':' ) ) {
        return (string) $ip;
    }

    $packed = @inet_pton( $ip );

    if ( false === $packed || 16 !== strlen( $packed ) ) {
        return (string) $ip;
    }

    return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
}

function nolantis_ll_ip_in_list( $ip, $list ) {
    if ( empty( $ip ) || empty( $list ) || ! is_array( $list ) ) {
        return false;
    }

    foreach ( $list as $range ) {
        if ( nolantis_ll_ip_matches( $ip, $range ) ) {
            return true;
        }
    }

    return false;
}

define( 'NOLANTIS_LL_CF_OPTION', 'nolantis_ll_cloudflare_ranges' );
define( 'NOLANTIS_LL_CF_HOOK', 'nolantis_ll_weekly_cloudflare_update' );
define( 'NOLANTIS_LL_CF_API', 'https://api.cloudflare.com/client/v4/ips' );

/**
 * Rangos de Cloudflare incluidos en el plugin (https://www.cloudflare.com/ips/). Se usan si
 * todavia no se ha descargado la lista actualizada o si la descargada no es valida.
 */
function nolantis_ll_get_builtin_cloudflare_ranges() {
    return array(
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    );
}

/**
 * Valida una lista de rangos de Cloudflare antes de fiarse de ella: todos deben ser rangos CIDR
 * validos, ninguno demasiado amplio (un 0.0.0.0/0 haria que cualquiera pudiera falsificar su IP)
 * y debe haber un minimo de rangos IPv4 e IPv6.
 */
function nolantis_ll_cloudflare_ranges_are_valid( $ranges ) {
    if ( ! is_array( $ranges ) || count( $ranges ) > 200 ) {
        return false;
    }

    $ipv4 = 0;
    $ipv6 = 0;

    foreach ( $ranges as $range ) {
        $normalized = nolantis_ll_normalize_ip_or_range( $range );

        if ( false === $normalized || false === strpos( $normalized, '/' ) ) {
            return false;
        }

        list( $ip, $bits ) = explode( '/', $normalized, 2 );

        if ( false !== strpos( $ip, ':' ) ) {
            if ( (int) $bits < 28 ) {
                return false;
            }
            $ipv6++;
        } else {
            if ( (int) $bits < 11 ) {
                return false;
            }
            $ipv4++;
        }
    }

    return $ipv4 >= 5 && $ipv6 >= 3;
}

/**
 * Rangos de Cloudflare en uso: los descargados si son validos; si no, los incluidos en el plugin.
 */
function nolantis_ll_get_cloudflare_ranges() {
    $stored = get_option( NOLANTIS_LL_CF_OPTION );
    $ranges = ( is_array( $stored ) && ! empty( $stored['ranges'] ) && nolantis_ll_cloudflare_ranges_are_valid( $stored['ranges'] ) )
        ? $stored['ranges']
        : nolantis_ll_get_builtin_cloudflare_ranges();

    return apply_filters( 'nolantis_cloudflare_ip_ranges', $ranges );
}

/**
 * Descarga la lista oficial de rangos de Cloudflare. Si falla o no es valida, se conservan los rangos
 * que habia y se guarda el motivo para mostrarlo en el panel.
 *
 * @return bool
 */
function nolantis_ll_update_cloudflare_ranges() {
    $stored   = get_option( NOLANTIS_LL_CF_OPTION );
    $stored   = is_array( $stored ) ? $stored : array();
    $response = wp_remote_get( NOLANTIS_LL_CF_API, array( 'timeout' => 15 ) );
    $error    = '';
    $ranges   = array();

    if ( is_wp_error( $response ) ) {
        $error = $response->get_error_message();
    } elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        $error = 'Cloudflare respondió con el código HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '.';
    } else {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) || empty( $body['result']['ipv4_cidrs'] ) || empty( $body['result']['ipv6_cidrs'] ) ) {
            $error = 'La respuesta de Cloudflare no tiene el formato esperado.';
        } else {
            foreach ( array_merge( (array) $body['result']['ipv4_cidrs'], (array) $body['result']['ipv6_cidrs'] ) as $range ) {
                $ranges[] = nolantis_ll_normalize_ip_or_range( $range );
            }

            if ( ! nolantis_ll_cloudflare_ranges_are_valid( $ranges ) ) {
                $error  = 'La lista recibida de Cloudflare no supera las comprobaciones de seguridad; se mantiene la anterior.';
                $ranges = array();
            }
        }
    }

    $stored['last_attempt'] = time();
    $stored['last_error']   = $error;

    if ( '' === $error ) {
        $stored['ranges']     = array_values( array_unique( $ranges ) );
        $stored['updated_at'] = time();
    }

    update_option( NOLANTIS_LL_CF_OPTION, $stored, true );

    return '' === $error;
}
add_action( NOLANTIS_LL_CF_HOOK, 'nolantis_ll_update_cloudflare_ranges' );

/**
 * Estado para el panel.
 *
 * @return array{source: string, count: int, updated_at: int, last_attempt: int, last_error: string}
 */
function nolantis_ll_get_cloudflare_ranges_status() {
    $stored = get_option( NOLANTIS_LL_CF_OPTION );
    $stored = is_array( $stored ) ? $stored : array();
    $valid  = ! empty( $stored['ranges'] ) && nolantis_ll_cloudflare_ranges_are_valid( $stored['ranges'] );

    return array(
        'source'       => $valid ? 'downloaded' : 'builtin',
        'count'        => count( $valid ? $stored['ranges'] : nolantis_ll_get_builtin_cloudflare_ranges() ),
        'updated_at'   => isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0,
        'last_attempt' => isset( $stored['last_attempt'] ) ? (int) $stored['last_attempt'] : 0,
        'last_error'   => isset( $stored['last_error'] ) ? (string) $stored['last_error'] : '',
    );
}

function nolantis_ll_get_remote_addr() {
    $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
    $remote = nolantis_ll_normalize_ip( $remote );

    return false === $remote ? '' : $remote;
}

/**
 * IP real de Cloudflare si la peticion llega desde un rango oficial de Cloudflare; si no, false.
 */
function nolantis_ll_get_cloudflare_client_ip( $remote ) {
    if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! nolantis_ll_ip_in_list( $remote, nolantis_ll_get_cloudflare_ranges() ) ) {
        return false;
    }

    return nolantis_ll_normalize_ip( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
}

/**
 * Devuelve la IP del visitante segun el "Origen de la IP" configurado.
 *
 * Solo se confia en cabeceras de proxy (CF-Connecting-IP, X-Forwarded-For) cuando la conexion
 * llega realmente desde ese proxy; si no, cualquiera podria falsificar su IP y saltarse el bloqueo.
 *
 * - auto:        conexion directa, salvo que la peticion venga de Cloudflare (verificado por rangos).
 * - remote_addr: solo conexion directa, ignora cualquier cabecera.
 * - proxy:       X-Forwarded-For si la conexion llega de un proxy de confianza (o de Cloudflare).
 */
function nolantis_get_request_ip() {
    $remote = nolantis_ll_get_remote_addr();

    if ( '' === $remote ) {
        return '';
    }

    $source = nolantis_ll_get_setting( 'ip_source' );

    if ( 'remote_addr' === $source ) {
        return $remote;
    }

    if ( 'proxy' === $source ) {
        $trusted = array_merge( (array) nolantis_ll_get_setting( 'trusted_proxies' ), nolantis_ll_get_cloudflare_ranges() );

        if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! nolantis_ll_ip_in_list( $remote, $trusted ) ) {
            return $remote;
        }

        // Se recorre la cadena de derecha a izquierda saltando los proxies de confianza:
        // la primera IP que no es de confianza es la del cliente. Lo que haya mas a la izquierda
        // lo pudo escribir el propio atacante y se ignora.
        $chain = array_reverse( explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

        foreach ( $chain as $hop ) {
            $hop = nolantis_ll_normalize_ip( $hop );

            if ( false === $hop ) {
                break;
            }

            if ( ! nolantis_ll_ip_in_list( $hop, $trusted ) ) {
                return $hop;
            }
        }

        return $remote;
    }

    $cloudflare = nolantis_ll_get_cloudflare_client_ip( $remote );

    return $cloudflare ? $cloudflare : $remote;
}

/**
 * Pistas para el panel sobre como llega la conexion.
 *
 * @return string 'cloudflare' (detectado y verificado), 'proxy' (IP interna con X-Forwarded-For),
 *                'private' (IP interna sin cabeceras) o ''.
 */
function nolantis_ll_detect_proxy_hint() {
    $remote = nolantis_ll_get_remote_addr();

    if ( '' === $remote ) {
        return '';
    }

    if ( nolantis_ll_get_cloudflare_client_ip( $remote ) ) {
        return 'cloudflare';
    }

    if ( nolantis_ll_is_public_ip( $remote ) ) {
        return '';
    }

    return empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? 'private' : 'proxy';
}
