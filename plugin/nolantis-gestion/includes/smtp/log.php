<?php
/**
 * SMTP: registro de envios y explicacion de los errores.
 *
 * Por cada correo se guarda fecha, estado, metodo, destinatario y asunto (nunca el contenido).
 * En los fallos se guarda ademas el error y la respuesta del servidor, sin credenciales.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_MAIL_LOG_DB_VERSION', '1' );
define( 'NOLANTIS_MAIL_LOG_DB_VERSION_OPTION', 'nolantis_mail_log_db_version' );
define( 'NOLANTIS_MAIL_LOG_PURGE_HOOK', 'nolantis_mail_log_daily_purge' );
define( 'NOLANTIS_MAIL_LOG_MAX_ROWS', 20000 );

function nolantis_mail_log_table() {
    global $wpdb;

    return $wpdb->prefix . 'nolantis_mail_log';
}

/* -------------------------------------------------------------------------
 * Instalacion y limpieza
 * ---------------------------------------------------------------------- */

function nolantis_mail_log_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = nolantis_mail_log_table();

    dbDelta(
        "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at int(10) unsigned NOT NULL,
  status varchar(10) NOT NULL,
  mailer varchar(10) NOT NULL DEFAULT 'smtp',
  recipients varchar(255) NOT NULL DEFAULT '',
  subject varchar(190) NOT NULL DEFAULT '',
  error text NULL,
  debug text NULL,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY created_at (created_at)
) {$wpdb->get_charset_collate()};"
    );

    update_option( NOLANTIS_MAIL_LOG_DB_VERSION_OPTION, NOLANTIS_MAIL_LOG_DB_VERSION, true );
}

/**
 * Al actualizar el plugin desde GitHub no se ejecuta el hook de activacion: se instala al cargar.
 */
function nolantis_mail_log_maybe_install() {
    if ( NOLANTIS_MAIL_LOG_DB_VERSION !== get_option( NOLANTIS_MAIL_LOG_DB_VERSION_OPTION ) ) {
        nolantis_mail_log_install();
    }

    if ( ! wp_next_scheduled( NOLANTIS_MAIL_LOG_PURGE_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NOLANTIS_MAIL_LOG_PURGE_HOOK );
    }
}
add_action( 'plugins_loaded', 'nolantis_mail_log_maybe_install', 5 );

function nolantis_mail_log_unschedule_purge() {
    wp_clear_scheduled_hook( NOLANTIS_MAIL_LOG_PURGE_HOOK );
}

/**
 * Borra los registros antiguos y, si una web envia muchisimo, se queda solo con los mas recientes.
 */
function nolantis_mail_log_purge() {
    global $wpdb;

    $table    = nolantis_mail_log_table();
    $settings = nolantis_get_smtp_settings();

    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", time() - (int) $settings['log_retention_days'] * DAY_IN_SECONDS ) );

    $cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", NOLANTIS_MAIL_LOG_MAX_ROWS ) );

    if ( $cutoff > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
    }
}
add_action( NOLANTIS_MAIL_LOG_PURGE_HOOK, 'nolantis_mail_log_purge' );

function nolantis_mail_log_clear() {
    global $wpdb;

    $wpdb->query( 'DELETE FROM ' . nolantis_mail_log_table() );
}

/* -------------------------------------------------------------------------
 * Registro
 * ---------------------------------------------------------------------- */

/**
 * Limpia un texto para guardarlo: UTF-8 valido, sin caracteres de control y con longitud maxima.
 * No se usa sanitize_text_field() porque borraria lo que va entre < > ("Nombre <email>",
 * "MAIL FROM:<email>"). Al mostrarlo en el panel siempre se escapa.
 */
function nolantis_mail_log_clean( $text, $max, $multiline = false ) {
    $text = wp_check_invalid_utf8( (string) $text, true );
    $text = preg_replace( $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', ' ', $text );
    $text = trim( $text );

    return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

function nolantis_mail_log_format_recipients( $to ) {
    if ( is_array( $to ) ) {
        $to = implode( ', ', $to );
    }

    return nolantis_mail_log_clean( $to, 255 );
}

function nolantis_mail_log_insert( $status, $to, $subject, $error = '', $debug = array() ) {
    global $wpdb;

    $wpdb->insert(
        nolantis_mail_log_table(),
        array(
            'created_at' => time(),
            'status'     => $status,
            'mailer'     => nolantis_is_smtp_active() ? 'smtp' : 'mail',
            'recipients' => nolantis_mail_log_format_recipients( $to ),
            'subject'    => nolantis_mail_log_clean( $subject, 190 ),
            'error'      => nolantis_mail_log_clean( $error, 2000, true ),
            'debug'      => nolantis_mail_log_clean( implode( "\n", (array) $debug ), 20000, true ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
}

function nolantis_mail_log_on_success( $mail_data ) {
    $settings = nolantis_get_smtp_settings();

    if ( 'all' === $settings['log_mode'] ) {
        nolantis_mail_log_insert( 'sent', isset( $mail_data['to'] ) ? $mail_data['to'] : '', isset( $mail_data['subject'] ) ? $mail_data['subject'] : '' );
    }
}
add_action( 'wp_mail_succeeded', 'nolantis_mail_log_on_success' );

/**
 * @param WP_Error $error
 */
function nolantis_mail_log_on_failure( $error ) {
    $settings = nolantis_get_smtp_settings();

    if ( 'off' === $settings['log_mode'] || ! is_wp_error( $error ) ) {
        return;
    }

    $data = (array) $error->get_error_data();

    nolantis_mail_log_insert(
        'failed',
        isset( $data['to'] ) ? $data['to'] : '',
        isset( $data['subject'] ) ? $data['subject'] : '',
        $error->get_error_message(),
        array_slice( nolantis_smtp_get_debug(), -40 )
    );
}
add_action( 'wp_mail_failed', 'nolantis_mail_log_on_failure' );

/* -------------------------------------------------------------------------
 * Consultas para el panel
 * ---------------------------------------------------------------------- */

/**
 * @return object[]
 */
function nolantis_mail_log_get_entries( $limit = 100, $status = '' ) {
    global $wpdb;

    $table = nolantis_mail_log_table();

    if ( '' !== $status ) {
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ) );
    }

    return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
}

/**
 * @return array{sent: int, failed: int}
 */
function nolantis_mail_log_stats( $since ) {
    global $wpdb;

    $rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM ' . nolantis_mail_log_table() . ' WHERE created_at >= %d GROUP BY status', $since ), OBJECT_K );
    $stats = array(
        'sent'   => 0,
        'failed' => 0,
    );

    foreach ( $stats as $status => $total ) {
        if ( isset( $rows[ $status ] ) ) {
            $stats[ $status ] = (int) $rows[ $status ]->total;
        }
    }

    return $stats;
}

/* -------------------------------------------------------------------------
 * Explicacion de los errores
 * ---------------------------------------------------------------------- */

/**
 * Reglas para explicar los errores, de la mas concreta a la mas generica (gana la primera que
 * coincide). Las agujas que empiezan por "/" son expresiones regulares: los codigos de respuesta
 * solo cuentan al principio de una respuesta del servidor ("250-SIZE 42100000" no es un error 421).
 *
 * @return array<string, array{0: string[], 1: string}>
 */
function nolantis_smtp_error_rules() {
    return array(
        'microsoft'   => array( array( '5.7.139', 'smtpclientauthentication is disabled', 'smtp auth is disabled' ), 'Microsoft 365 tiene desactivado el "SMTP autenticado" para este buzón. Actívalo en el centro de administración de Microsoft 365 (Usuarios > buzón > Correo > Aplicaciones de correo electrónico).' ),
        'gmail'       => array( array( 'application-specific password', '534-5.7.9', '5.7.9 application' ), 'Gmail exige una contraseña de aplicación: créala en tu cuenta de Google (Seguridad > Verificación en dos pasos > Contraseñas de aplicaciones) y úsala como contraseña SMTP.' ),
        'auth'        => array( array( 'could not authenticate', '/server -> client: 535[ -]/', 'authentication failed', 'username and password not accepted', 'invalid credentials', 'authentication unsuccessful' ), 'El servidor rechaza el usuario o la contraseña. Revisa ambos; algunos proveedores (Gmail, Microsoft 365, Zoho) exigen una contraseña de aplicación en lugar de la normal.' ),
        'dns'         => array( array( 'getaddrinfo', 'php_network_getaddresses', 'name or service not known', 'no such host' ), 'El nombre del servidor SMTP no existe. Revisa que esté bien escrito (por ejemplo, smtp.tudominio.com).' ),
        'refused'     => array( array( 'connection refused' ), 'El servidor rechaza la conexión en ese puerto. Revisa el puerto (465 con SSL, 587 con TLS) o si el servidor SMTP está bien indicado.' ),
        'certificate' => array( array( 'certificate', 'ssl routines', 'ssl operation failed', 'tls negotiation failed', 'crypto enabling timeout', 'unable to connect to ssl' ), 'Falla la conexión segura (certificado SSL/TLS). Prueba la otra combinación de puerto y cifrado (465 con SSL o 587 con TLS) y comprueba que el nombre del servidor coincide con su certificado.' ),
        'timeout'     => array( array( 'timed out', 'connection timeout', 'network is unreachable' ), 'El servidor SMTP no responde. Comprueba el servidor y el puerto; muchos hostings bloquean las conexiones salientes a los puertos 25, 465 o 587 y hay que pedirles que los abran.' ),
        'sender'      => array( array( 'sender address rejected', 'not owned by user', 'sender verify failed', 'relay access denied', 'relaying denied', 'not permitted to relay', '5.7.60', 'send mail as', 'unauthenticated sender', 'the following from address failed' ), 'El servidor no permite enviar con ese remitente. El "Email remitente" debe ser la misma cuenta SMTP o un alias/dominio autorizado en el proveedor.' ),
        'limit'       => array( array( 'quota', 'sending limit', 'rate limit', 'too many messages', '5.4.5', '/server -> client: (421|451|452)[ -]/' ), 'El servidor ha rechazado el envío temporalmente por un límite de envíos o porque está ocupado. Espera y vuelve a intentarlo; si pasa a menudo, revisa el límite de tu plan.' ),
        'address'     => array( array( 'invalid address', 'you must provide at least one recipient' ), 'Alguna dirección de email (destinatario o remitente) no es válida.' ),
        'mail'        => array( array( 'could not instantiate mail function', 'mail() function' ), 'La función mail() del servidor no funciona. Activa y configura el envío por SMTP.' ),
        'rejected'    => array( array( '/server -> client: 55[034][ -]/', 'data not accepted', 'message rejected', 'the following recipients failed' ), 'El servidor ha rechazado el mensaje. La respuesta del servidor indica el motivo: destinatario inexistente, remitente no autorizado o mensaje considerado spam.' ),
        'connect'     => array( array( 'failed to connect', 'could not connect to smtp host', 'smtp connect() failed' ), 'No se puede conectar con el servidor SMTP. Comprueba el servidor y el puerto; muchos hostings bloquean las conexiones salientes a los puertos 25, 465 o 587 y hay que pedirles que los abran.' ),
    );
}

/**
 * WordPress traduce los mensajes de PHPMailer al idioma de la web ("Error SMTP: no se pudo
 * identificar."): ademas del texto original se reconoce su traduccion al idioma activo.
 *
 * @return array<string, string[]> Clave de regla => mensajes originales de PHPMailer.
 */
function nolantis_smtp_translated_messages() {
    return array(
        'auth'     => array( 'SMTP Error: Could not authenticate.' ),
        'sender'   => array( 'The following From address failed: ' ),
        'address'  => array( 'Invalid address: ', 'You must provide at least one recipient email address.' ),
        'mail'     => array( 'Could not instantiate mail function.' ),
        'rejected' => array( 'SMTP Error: Data not accepted.', 'SMTP Error: The following recipients failed: ' ),
        'connect'  => array( 'SMTP Error: Could not connect to SMTP host.', 'SMTP connect() failed.' ),
    );
}

/**
 * Traduce los errores tipicos de SMTP a una explicacion y una solucion en espanol.
 *
 * @param string          $error Mensaje de error de PHPMailer.
 * @param string|string[] $debug Conversacion con el servidor.
 */
function nolantis_smtp_explain_error( $error, $debug = '' ) {
    $text  = strtolower( $error . "\n" . ( is_array( $debug ) ? implode( "\n", $debug ) : (string) $debug ) );
    $rules = nolantis_smtp_error_rules();

    foreach ( nolantis_smtp_translated_messages() as $key => $messages ) {
        foreach ( $messages as $message ) {
            // Cadenas del propio WordPress (dominio por defecto), traducidas al idioma activo.
            $needle = strtolower( trim( __( $message ), " :.\t" ) ); // phpcs:ignore WordPress.WP.I18n

            if ( '' !== $needle ) {
                $rules[ $key ][0][] = $needle;
            }
        }
    }

    foreach ( $rules as $rule ) {
        foreach ( $rule[0] as $needle ) {
            $found = 0 === strpos( $needle, '/' ) ? preg_match( $needle, $text ) : false !== strpos( $text, $needle );

            if ( $found ) {
                return $rule[1];
            }
        }
    }

    return '';
}

/* -------------------------------------------------------------------------
 * Aviso en el escritorio si fallan correos
 * ---------------------------------------------------------------------- */

function nolantis_mail_log_failure_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    if ( ! $screen || 'dashboard' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $stats = nolantis_mail_log_stats( time() - DAY_IN_SECONDS );

    if ( $stats['failed'] < 1 ) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">Ver el registro de envíos</a></p></div>',
        esc_html( sprintf( 1 === $stats['failed'] ? '%d correo no se ha podido enviar en las últimas 24 horas.' : '%d correos no se han podido enviar en las últimas 24 horas.', $stats['failed'] ) ),
        esc_html( 'Los formularios y avisos de la web pueden no estar llegando.' ),
        esc_url( admin_url( 'admin.php?page=nolantis-smtp&tab=registro&status=failed' ) )
    );
}
add_action( 'admin_notices', 'nolantis_mail_log_failure_notice' );
