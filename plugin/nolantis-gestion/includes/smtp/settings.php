<?php
/**
 * SMTP: ajustes, contrasena cifrada y proveedores predefinidos.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_SMTP_OPTION', 'nolantis_smtp_settings' );
define( 'NOLANTIS_SMTP_PASSWORD_OPTION', 'nolantis_smtp_password' );
define( 'NOLANTIS_SMTP_PASSWORD_PREFIX', 'nolantis_enc_v2:' );
define( 'NOLANTIS_SMTP_PASSWORD_PREFIX_V1', 'nolantis_enc_v1:' );

function nolantis_get_default_smtp_settings() {
    return array(
        'enabled'            => 0,
        'host'               => '',
        'port'               => 587,
        'encryption'         => 'tls',
        'auth'               => 1,
        'username'           => '',
        'from_email'         => '',
        'force_from_email'   => 1,
        'from_name'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        'reply_to_original'  => 1,
        'log_mode'           => 'all',
        'log_retention_days' => 30,
    );
}

/**
 * Valores habituales de proveedores conocidos. Solo rellenan el formulario: hay que confirmarlos
 * con el proveedor y escribir el usuario y la contrasena.
 */
function nolantis_get_smtp_presets() {
    return array(
        'gmail'     => array( 'Gmail / Google Workspace', 'smtp.gmail.com', 587, 'tls', 'Usuario: la dirección de Gmail. Contraseña: una contraseña de aplicación (Cuenta de Google > Seguridad > Verificación en dos pasos > Contraseñas de aplicaciones), no la contraseña normal.' ),
        'microsoft' => array( 'Microsoft 365 / Exchange Online', 'smtp.office365.com', 587, 'tls', 'El buzón debe tener activado "SMTP autenticado" en el centro de administración de Microsoft 365. Si la cuenta tiene verificación en dos pasos, usa una contraseña de aplicación.' ),
        'outlook'   => array( 'Outlook.com / Hotmail', 'smtp-mail.outlook.com', 587, 'tls', 'Usuario: la dirección completa de Outlook.' ),
        'ionos'     => array( 'IONOS (España)', 'smtp.ionos.es', 587, 'tls', 'Usuario: la dirección de email completa del buzón de IONOS.' ),
        'hostinger' => array( 'Hostinger', 'smtp.hostinger.com', 465, 'ssl', 'Usuario: la dirección de email completa creada en Hostinger.' ),
        'zoho'      => array( 'Zoho Mail (Europa)', 'smtp.zoho.eu', 465, 'ssl', 'Si la cuenta tiene verificación en dos pasos, usa una contraseña específica de aplicación.' ),
        'brevo'     => array( 'Brevo (Sendinblue)', 'smtp-relay.brevo.com', 587, 'tls', 'Usuario y contraseña: los de "SMTP y API" en tu cuenta de Brevo (no los de acceso a la web).' ),
        'sendgrid'  => array( 'SendGrid', 'smtp.sendgrid.net', 587, 'tls', 'Usuario: literalmente "apikey". Contraseña: tu API key de SendGrid.' ),
        'mailgun'   => array( 'Mailgun (Europa)', 'smtp.eu.mailgun.org', 587, 'tls', 'Usuario y contraseña: las credenciales SMTP del dominio en Mailgun.' ),
        'ses'       => array( 'Amazon SES (Irlanda)', 'email-smtp.eu-west-1.amazonaws.com', 587, 'tls', 'Usuario y contraseña: credenciales SMTP de SES (no las claves de AWS). Cambia la región del servidor si tu SES está en otra.' ),
        'cpanel'    => array( 'Hosting con cPanel / Plesk (Raiola, Webempresa, SiteGround…)', 'mail.tudominio.com', 465, 'ssl', 'Sustituye "tudominio.com" por tu dominio. Usuario: la dirección de email completa del buzón creado en el hosting.' ),
    );
}

/* -------------------------------------------------------------------------
 * Contrasena cifrada
 *
 * v2: AES-256-CBC + HMAC-SHA256 (detecta si no se puede descifrar, p. ej. porque han cambiado
 *     las claves de wp-config.php). v1 (versiones anteriores): solo AES; se migra a v2 al leerla.
 * ---------------------------------------------------------------------- */

function nolantis_smtp_get_keys() {
    $base = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

    return array(
        'enc' => hash_hmac( 'sha256', 'nolantis-smtp-enc', $base, true ),
        'mac' => hash_hmac( 'sha256', 'nolantis-smtp-mac', $base, true ),
    );
}

function nolantis_encrypt_smtp_password( $password ) {
    $password = (string) $password;

    if ( '' === $password || ! function_exists( 'openssl_encrypt' ) ) {
        return $password;
    }

    $keys   = nolantis_smtp_get_keys();
    $iv     = random_bytes( 16 );
    $cipher = openssl_encrypt( $password, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv );

    if ( false === $cipher ) {
        return $password;
    }

    $mac = hash_hmac( 'sha256', $iv . $cipher, $keys['mac'], true );

    return NOLANTIS_SMTP_PASSWORD_PREFIX . base64_encode( $iv . $mac . $cipher );
}

/**
 * @return string|false La contrasena, '' si no hay, o false si existe pero no se puede descifrar.
 */
function nolantis_decrypt_smtp_password( $stored ) {
    if ( ! is_string( $stored ) || '' === $stored ) {
        return '';
    }

    if ( 0 === strpos( $stored, NOLANTIS_SMTP_PASSWORD_PREFIX ) ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }

        $raw = base64_decode( substr( $stored, strlen( NOLANTIS_SMTP_PASSWORD_PREFIX ) ), true );

        if ( false === $raw || strlen( $raw ) < 49 ) {
            return false;
        }

        $keys   = nolantis_smtp_get_keys();
        $iv     = substr( $raw, 0, 16 );
        $mac    = substr( $raw, 16, 32 );
        $cipher = substr( $raw, 48 );

        if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $keys['mac'], true ), $mac ) ) {
            return false;
        }

        $plain = openssl_decrypt( $cipher, 'aes-256-cbc', $keys['enc'], OPENSSL_RAW_DATA, $iv );

        return false === $plain ? false : $plain;
    }

    if ( 0 === strpos( $stored, NOLANTIS_SMTP_PASSWORD_PREFIX_V1 ) ) {
        return nolantis_decrypt_smtp_password_v1( $stored );
    }

    // Contrasena antigua en claro (versiones muy antiguas): se migra al cifrado en el admin.
    return $stored;
}

/**
 * Formato de las versiones <= 1.0.17 (sin comprobacion de integridad).
 *
 * @return string|false
 */
function nolantis_decrypt_smtp_password_v1( $stored ) {
    if ( ! function_exists( 'openssl_decrypt' ) ) {
        return false;
    }

    $parts = explode( ':', substr( $stored, strlen( NOLANTIS_SMTP_PASSWORD_PREFIX_V1 ) ), 2 );

    if ( 2 !== count( $parts ) ) {
        return false;
    }

    $iv        = base64_decode( $parts[0], true );
    $encrypted = base64_decode( $parts[1], true );

    if ( false === $iv || false === $encrypted ) {
        return false;
    }

    $plain = openssl_decrypt( $encrypted, 'aes-256-cbc', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv );

    return is_string( $plain ) ? $plain : false;
}

function nolantis_update_smtp_password( $password ) {
    update_option( NOLANTIS_SMTP_PASSWORD_OPTION, nolantis_encrypt_smtp_password( (string) $password ), false );
}

/**
 * @return string Contrasena ('' si no hay o no se puede descifrar).
 */
function nolantis_get_smtp_password() {
    $password = nolantis_decrypt_smtp_password( get_option( NOLANTIS_SMTP_PASSWORD_OPTION, '' ) );

    return false === $password ? '' : $password;
}

/**
 * @return string 'empty' | 'ok' | 'unreadable'
 */
function nolantis_get_smtp_password_status() {
    $password = nolantis_decrypt_smtp_password( get_option( NOLANTIS_SMTP_PASSWORD_OPTION, '' ) );

    if ( false === $password ) {
        return 'unreadable';
    }

    return '' === $password ? 'empty' : 'ok';
}

/**
 * Migraciones de versiones anteriores (solo en el admin):
 * - contrasena guardada dentro de los ajustes o en claro, o con el cifrado v1 => cifrado v2;
 * - opcion del asistente antiguo, que ya no existe.
 */
function nolantis_smtp_migrate_legacy_data() {
    $settings = get_option( NOLANTIS_SMTP_OPTION, array() );

    if ( is_array( $settings ) && ! empty( $settings['password'] ) ) {
        if ( '' === nolantis_get_smtp_password() ) {
            nolantis_update_smtp_password( (string) $settings['password'] );
        }

        unset( $settings['password'] );
        update_option( NOLANTIS_SMTP_OPTION, $settings, false );
    }

    $stored = get_option( NOLANTIS_SMTP_PASSWORD_OPTION, '' );

    if ( is_string( $stored ) && '' !== $stored && 0 !== strpos( $stored, NOLANTIS_SMTP_PASSWORD_PREFIX ) ) {
        $plain = nolantis_decrypt_smtp_password( $stored );

        if ( is_string( $plain ) && '' !== $plain ) {
            nolantis_update_smtp_password( $plain );
        }
    }

    if ( false !== get_option( 'nolantis_smtp_wizard_status', false ) ) {
        delete_option( 'nolantis_smtp_wizard_status' );
    }
}
// Se ejecuta una sola vez, al activar o actualizar el plugin (nolantis_maybe_upgrade), no en cada pagina.

/* -------------------------------------------------------------------------
 * Ajustes
 * ---------------------------------------------------------------------- */

function nolantis_get_smtp_settings() {
    $settings = get_option( NOLANTIS_SMTP_OPTION, array() );
    $defaults = nolantis_get_default_smtp_settings();

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    unset( $settings['password'] );
    $settings = wp_parse_args( $settings, $defaults );

    if ( '' === trim( (string) $settings['from_name'] ) ) {
        $settings['from_name'] = $defaults['from_name'];
    }

    $settings['password'] = nolantis_get_smtp_password();

    return $settings;
}

function nolantis_is_smtp_active() {
    $settings = nolantis_get_smtp_settings();

    return ! empty( $settings['enabled'] ) && '' !== $settings['host'];
}

function nolantis_register_smtp_settings() {
    register_setting(
        'nolantis_settings_group',
        NOLANTIS_SMTP_OPTION,
        array(
            'sanitize_callback' => 'nolantis_sanitize_smtp_settings',
            'default'           => nolantis_get_default_smtp_settings(),
        )
    );
}
add_action( 'admin_init', 'nolantis_register_smtp_settings' );

/**
 * WordPress sanea dos veces la primera vez que se guarda una opcion: se evita repetir avisos.
 */
function nolantis_smtp_settings_notice( $code, $message, $type = 'error' ) {
    static $added = array();

    if ( isset( $added[ $code ] ) ) {
        return;
    }

    $added[ $code ] = true;

    if ( function_exists( 'add_settings_error' ) ) {
        add_settings_error( NOLANTIS_SMTP_OPTION, 'nolantis_smtp_' . $code, $message, $type );
    }
}

/**
 * Limpia el servidor: quita "ssl://", "smtp://", "https://", barras y espacios que se pegan por error.
 */
function nolantis_smtp_clean_host( $host ) {
    $host = strtolower( trim( sanitize_text_field( (string) $host ) ) );
    $host = preg_replace( '#^(ssl|tls|smtps?|https?)://#', '', $host );

    return trim( $host, " /\t" );
}

function nolantis_sanitize_smtp_settings( $input ) {
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    $defaults = nolantis_get_default_smtp_settings();
    $port     = isset( $input['port'] ) ? absint( $input['port'] ) : $defaults['port'];

    if ( $port < 1 || $port > 65535 ) {
        nolantis_smtp_settings_notice( 'invalid_port', 'El puerto no es válido; se ha puesto 587.' );
        $port = $defaults['port'];
    }

    $sanitized = array(
        'enabled'            => ! empty( $input['enabled'] ) ? 1 : 0,
        'host'               => isset( $input['host'] ) ? nolantis_smtp_clean_host( $input['host'] ) : '',
        'port'               => $port,
        'encryption'         => isset( $input['encryption'] ) ? sanitize_key( $input['encryption'] ) : $defaults['encryption'],
        'auth'               => ! empty( $input['auth'] ) ? 1 : 0,
        'username'           => isset( $input['username'] ) ? trim( sanitize_text_field( wp_unslash( $input['username'] ) ) ) : '',
        'from_email'         => isset( $input['from_email'] ) ? sanitize_email( wp_unslash( $input['from_email'] ) ) : '',
        'force_from_email'   => ! empty( $input['force_from_email'] ) ? 1 : 0,
        'from_name'          => isset( $input['from_name'] ) ? sanitize_text_field( wp_unslash( $input['from_name'] ) ) : '',
        'reply_to_original'  => ! empty( $input['reply_to_original'] ) ? 1 : 0,
        'log_mode'           => isset( $input['log_mode'] ) ? sanitize_key( $input['log_mode'] ) : $defaults['log_mode'],
        'log_retention_days' => isset( $input['log_retention_days'] ) ? min( 365, max( 1, absint( $input['log_retention_days'] ) ) ) : $defaults['log_retention_days'],
    );

    if ( ! in_array( $sanitized['encryption'], array( 'none', 'ssl', 'tls' ), true ) ) {
        $sanitized['encryption'] = $defaults['encryption'];
    }

    if ( ! in_array( $sanitized['log_mode'], array( 'all', 'errors', 'off' ), true ) ) {
        $sanitized['log_mode'] = $defaults['log_mode'];
    }

    // La contrasena se guarda aparte y cifrada. Vacia = mantener la actual; si se marca "borrar"
    // y a la vez se escribe una nueva, se queda la nueva.
    if ( ! empty( $input['clear_password'] ) ) {
        update_option( NOLANTIS_SMTP_PASSWORD_OPTION, '', false );
    }

    if ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
        nolantis_update_smtp_password( (string) wp_unslash( $input['password'] ) );
    }

    if ( isset( $input['from_email'] ) && '' !== trim( (string) $input['from_email'] ) && ! is_email( $sanitized['from_email'] ) ) {
        nolantis_smtp_settings_notice( 'invalid_from_email', 'El email remitente no es válido.' );
        $sanitized['from_email'] = '';
    }

    if ( $sanitized['enabled'] ) {
        if ( '' === $sanitized['host'] ) {
            nolantis_smtp_settings_notice( 'missing_host', 'Debes indicar el servidor SMTP para activar el envío. SMTP se ha guardado desactivado.' );
            $sanitized['enabled'] = 0;
        }

        if ( 465 === $port && 'tls' === $sanitized['encryption'] ) {
            nolantis_smtp_settings_notice( 'port_mismatch', 'El puerto 465 normalmente usa cifrado SSL, no TLS. Si el envío falla, cambia el cifrado a SSL.', 'warning' );
        } elseif ( 587 === $port && 'ssl' === $sanitized['encryption'] ) {
            nolantis_smtp_settings_notice( 'port_mismatch', 'El puerto 587 normalmente usa cifrado TLS, no SSL. Si el envío falla, cambia el cifrado a TLS.', 'warning' );
        }

        if ( $sanitized['auth'] && '' === $sanitized['username'] ) {
            nolantis_smtp_settings_notice( 'missing_username', 'Has activado la autenticación pero no has indicado el usuario.', 'warning' );
        }

        if ( $sanitized['auth'] && 'ok' !== nolantis_get_smtp_password_status() ) {
            nolantis_smtp_settings_notice( 'missing_password', 'Has activado la autenticación pero no hay ninguna contraseña guardada que se pueda usar.', 'warning' );
        }

        if ( $sanitized['force_from_email'] && '' === $sanitized['from_email'] ) {
            nolantis_smtp_settings_notice( 'missing_from', 'Has marcado "Forzar email remitente" pero no has indicado el email remitente.', 'warning' );
        }
    }

    return $sanitized;
}
