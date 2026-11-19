<?php
/**
 * Limit Login: tablas propias, registro de intentos y gestion de bloqueos.
 *
 * Dos tipos de bloqueo en la misma tabla:
 * - kind = 'ip':   bloqueo de una IP (o bloque IPv6 /64), con escalado por niveles.
 * - kind = 'user': "modo protegido" de un nombre de usuario atacado desde muchas IPs (botnet).
 *
 * Todas las fechas se guardan como timestamp Unix (UTC) para evitar problemas de zona horaria.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_LL_DB_VERSION', '2' );
define( 'NOLANTIS_LL_DB_VERSION_OPTION', 'nolantis_ll_db_version' );
define( 'NOLANTIS_LL_PURGE_HOOK', 'nolantis_ll_daily_purge' );

// Tope de filas: ante un ataque enorme las tablas no crecen sin limite entre limpiezas diarias.
define( 'NOLANTIS_LL_LOG_MAX_ROWS', 100000 );
define( 'NOLANTIS_LL_LOCKOUTS_MAX_ROWS', 50000 );
define( 'NOLANTIS_LL_TRIM_BATCH', 10000 );
define( 'NOLANTIS_LL_TRIM_EVERY', 500 );

function nolantis_ll_log_table() {
    global $wpdb;

    return $wpdb->prefix . 'nolantis_login_log';
}

function nolantis_ll_lockouts_table() {
    global $wpdb;

    return $wpdb->prefix . 'nolantis_login_lockouts';
}

/**
 * Crea o actualiza las tablas. Se llama al activar y tambien en cada carga si cambia la version,
 * porque al actualizar el plugin desde GitHub WordPress no ejecuta el hook de activacion.
 */
function nolantis_ll_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $log_table       = nolantis_ll_log_table();
    $lockouts_table  = nolantis_ll_lockouts_table();

    dbDelta(
        "CREATE TABLE {$log_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at int(10) unsigned NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  username varchar(60) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'login',
  event varchar(10) NOT NULL,
  PRIMARY KEY  (id),
  KEY ip_event_created (ip,event,created_at),
  KEY username_event_created (username,event,created_at),
  KEY created_at (created_at)
) {$charset_collate};"
    );

    dbDelta(
        "CREATE TABLE {$lockouts_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL DEFAULT 'ip',
  ip varchar(45) NOT NULL DEFAULT '',
  level tinyint(3) unsigned NOT NULL DEFAULT 1,
  username varchar(60) NOT NULL DEFAULT '',
  reason varchar(20) NOT NULL DEFAULT 'attempts',
  locked_at int(10) unsigned NOT NULL,
  locked_until int(10) unsigned NOT NULL,
  blocked_attempts int(10) unsigned NOT NULL DEFAULT 0,
  unlocked tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY ip_until (ip,locked_until),
  KEY kind_user_until (kind,username,locked_until),
  KEY locked_until (locked_until)
) {$charset_collate};"
    );

    nolantis_ll_delete_legacy_transients();

    update_option( NOLANTIS_LL_DB_VERSION_OPTION, NOLANTIS_LL_DB_VERSION, true );
}

/**
 * Borra los transients del sistema de bloqueo anterior (version <= 1.0.17).
 */
function nolantis_ll_delete_legacy_transients() {
    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_nolantis_ll_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_nolantis_ll_' ) . '%'
        )
    );
}

function nolantis_ll_maybe_install() {
    if ( NOLANTIS_LL_DB_VERSION !== get_option( NOLANTIS_LL_DB_VERSION_OPTION ) ) {
        nolantis_ll_install();
    }

    if ( ! wp_next_scheduled( NOLANTIS_LL_PURGE_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NOLANTIS_LL_PURGE_HOOK );
    }

    // Rangos de Cloudflare: la primera descarga a los pocos minutos y despues cada semana.
    if ( ! wp_next_scheduled( NOLANTIS_LL_CF_HOOK ) ) {
        wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'weekly', NOLANTIS_LL_CF_HOOK );
    }
}
add_action( 'plugins_loaded', 'nolantis_ll_maybe_install', 5 );

/**
 * Al desactivar el plugin se quitan sus tareas programadas (purga diaria y rangos de Cloudflare).
 */
function nolantis_ll_unschedule_purge() {
    wp_clear_scheduled_hook( NOLANTIS_LL_PURGE_HOOK );
    wp_clear_scheduled_hook( NOLANTIS_LL_CF_HOOK );
}

/**
 * Borra el registro y el historial de bloqueos con mas antiguedad que la configurada.
 */
function nolantis_ll_purge_old_records() {
    global $wpdb;

    $cutoff = time() - ( (int) nolantis_ll_get_setting( 'log_retention_days' ) * DAY_IN_SECONDS );

    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . nolantis_ll_log_table() . ' WHERE created_at < %d', $cutoff ) );
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . nolantis_ll_lockouts_table() . ' WHERE locked_until < %d', $cutoff ) );

    nolantis_ll_enforce_row_caps();
}
add_action( NOLANTIS_LL_PURGE_HOOK, 'nolantis_ll_purge_old_records' );

/**
 * Mantiene las tablas por debajo de su tope borrando lo mas antiguo:
 * - registro de intentos: solo se conservan las filas mas recientes;
 * - historial de bloqueos: solo se recortan bloqueos ya terminados (los activos nunca se tocan).
 * Borra en tandas para no bloquear la tabla mucho tiempo.
 */
function nolantis_ll_enforce_row_caps() {
    global $wpdb;

    $log_max      = max( 1000, (int) apply_filters( 'nolantis_ll_log_max_rows', NOLANTIS_LL_LOG_MAX_ROWS ) );
    $lockouts_max = max( 1000, (int) apply_filters( 'nolantis_ll_lockouts_max_rows', NOLANTIS_LL_LOCKOUTS_MAX_ROWS ) );
    $log          = nolantis_ll_log_table();
    $lockouts     = nolantis_ll_lockouts_table();
    $now          = time();

    $cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$log} ORDER BY id DESC LIMIT 1 OFFSET %d", $log_max ) );

    if ( $cutoff > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE id <= %d ORDER BY id LIMIT %d", $cutoff, NOLANTIS_LL_TRIM_BATCH ) );
    }

    $cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$lockouts} WHERE locked_until < %d ORDER BY id DESC LIMIT 1 OFFSET %d", $now, $lockouts_max ) );

    if ( $cutoff > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$lockouts} WHERE id <= %d AND locked_until < %d ORDER BY id LIMIT %d", $cutoff, $now, NOLANTIS_LL_TRIM_BATCH ) );
    }
}

/* -------------------------------------------------------------------------
 * Registro
 * ---------------------------------------------------------------------- */

/**
 * Guarda un evento en el registro.
 *
 * @param string $ip     IP o clave de IP (bloque /64 en IPv6). Puede ir vacia en eventos de usuario.
 * @param string $event  failed | lockout | unlock | success | userlock | userunlock | reset
 * @param string $source login | xmlrpc | rest | admin
 */
function nolantis_ll_log_event( $ip, $event, $username = '', $source = 'login' ) {
    global $wpdb;

    if ( empty( $ip ) && '' === (string) $username ) {
        return;
    }

    $wpdb->insert(
        nolantis_ll_log_table(),
        array(
            'created_at' => time(),
            'ip'         => (string) $ip,
            'username'   => nolantis_ll_clean_username( $username ),
            'source'     => substr( sanitize_key( $source ), 0, 10 ),
            'event'      => substr( sanitize_key( $event ), 0, 10 ),
        ),
        array( '%d', '%s', '%s', '%s', '%s' )
    );

    // Durante un ataque se aplica el tope de vez en cuando, sin esperar a la limpieza diaria.
    if ( 1 === wp_rand( 1, NOLANTIS_LL_TRIM_EVERY ) ) {
        nolantis_ll_enforce_row_caps();
    }
}

function nolantis_ll_clean_username( $username ) {
    $username = is_string( $username ) ? $username : '';

    return mb_substr( sanitize_text_field( $username ), 0, 60 );
}

/**
 * Cuenta eventos de un tipo para una IP o un usuario desde un momento dado.
 *
 * @param string $field 'ip' o 'username'.
 */
function nolantis_ll_count_events( $event, $field, $value, $since ) {
    global $wpdb;

    $field = 'username' === $field ? 'username' : 'ip';

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . nolantis_ll_log_table() . " WHERE {$field} = %s AND event = %s AND created_at >= %d",
            $value,
            $event,
            $since
        )
    );
}

/**
 * Cuenta fallos dentro de una ventana ignorando los anteriores al ultimo evento de "reinicio".
 * Se usa el id autoincremental del registro (orden exacto de los eventos) en vez de la fecha,
 * porque la fecha tiene precision de segundos y un fallo y un desbloqueo pueden coincidir.
 *
 * @param string   $field        'ip' o 'username'.
 * @param string[] $reset_events Eventos que reinician el contador.
 */
function nolantis_ll_count_failures_since_reset( $field, $value, $window_seconds, $reset_events ) {
    global $wpdb;

    $table = nolantis_ll_log_table();
    $field = 'username' === $field ? 'username' : 'ip';
    $in    = implode( ',', array_fill( 0, count( $reset_events ), '%s' ) );

    $last_reset_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(id) FROM {$table} WHERE {$field} = %s AND event IN ({$in})",
            array_merge( array( $value ), $reset_events )
        )
    );

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$field} = %s AND event = 'failed' AND created_at >= %d AND id > %d",
            $value,
            time() - $window_seconds,
            $last_reset_id
        )
    );
}

function nolantis_ll_count_recent_failures( $ip ) {
    return nolantis_ll_count_failures_since_reset(
        'ip',
        $ip,
        (int) nolantis_ll_get_setting( 'window_minutes' ) * MINUTE_IN_SECONDS,
        array( 'lockout', 'unlock' )
    );
}

function nolantis_ll_count_recent_user_failures( $username ) {
    return nolantis_ll_count_failures_since_reset(
        'username',
        $username,
        (int) nolantis_ll_get_setting( 'user_window_minutes' ) * MINUTE_IN_SECONDS,
        array( 'userlock', 'userunlock' )
    );
}

function nolantis_ll_count_distinct_failed_ips( $username, $since ) {
    global $wpdb;

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(DISTINCT ip) FROM ' . nolantis_ll_log_table() . " WHERE username = %s AND event = 'failed' AND created_at >= %d",
            $username,
            $since
        )
    );
}

/* -------------------------------------------------------------------------
 * Bloqueos por IP
 * ---------------------------------------------------------------------- */

/**
 * Devuelve el bloqueo activo de una IP o null.
 *
 * @return object|null
 */
function nolantis_ll_get_active_lockout( $ip ) {
    global $wpdb;

    if ( empty( $ip ) ) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . nolantis_ll_lockouts_table() . " WHERE kind = 'ip' AND ip = %s AND locked_until > %d ORDER BY locked_until DESC LIMIT 1",
            $ip,
            time()
        )
    );
}

/**
 * Calcula el nivel del proximo bloqueo: si el bloqueo anterior de la IP termino dentro de la
 * ventana de reincidencia, se sube un nivel respecto a ese bloqueo (maximo 3); si no, nivel 1.
 * Los bloqueos desbloqueados a mano no cuentan, asi un desbloqueo manual reinicia el escalado.
 */
function nolantis_ll_get_next_lockout_level( $ip ) {
    global $wpdb;

    $previous = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT level, locked_until FROM ' . nolantis_ll_lockouts_table() . " WHERE kind = 'ip' AND ip = %s AND unlocked = 0 ORDER BY locked_until DESC LIMIT 1",
            $ip
        )
    );

    if ( ! $previous ) {
        return 1;
    }

    $window = (int) nolantis_ll_get_setting( 'reincidence_hours' ) * HOUR_IN_SECONDS;

    if ( (int) $previous->locked_until < time() - $window ) {
        return 1;
    }

    return min( 3, (int) $previous->level + 1 );
}

/**
 * Crea un bloqueo para la IP y devuelve el objeto creado.
 *
 * @param string $reason attempts (demasiados fallos) | bot_user (probo un usuario tipico de bot).
 */
function nolantis_ll_create_lockout( $ip, $username, $source, $reason = 'attempts' ) {
    global $wpdb;

    $level    = nolantis_ll_get_next_lockout_level( $ip );
    $now      = time();
    $duration = nolantis_ll_get_lockout_duration( $level );

    $wpdb->insert(
        nolantis_ll_lockouts_table(),
        array(
            'kind'         => 'ip',
            'ip'           => $ip,
            'level'        => $level,
            'username'     => nolantis_ll_clean_username( $username ),
            'reason'       => $reason,
            'locked_at'    => $now,
            'locked_until' => $now + $duration,
        ),
        array( '%s', '%s', '%d', '%s', '%s', '%d', '%d' )
    );

    nolantis_ll_log_event( $ip, 'lockout', $username, $source );

    do_action( 'nolantis_ll_lockout_created', $ip, $level, $now + $duration, $username, $reason );

    return nolantis_ll_get_active_lockout( $ip );
}

function nolantis_ll_increment_blocked_attempts( $lockout_id ) {
    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            'UPDATE ' . nolantis_ll_lockouts_table() . ' SET blocked_attempts = blocked_attempts + 1 WHERE id = %d',
            $lockout_id
        )
    );
}

function nolantis_ll_get_current_admin_login() {
    $user = wp_get_current_user();

    return ( $user && $user->exists() ) ? $user->user_login : '';
}

/**
 * Desbloquea manualmente una IP. El desbloqueo manual tambien reinicia el escalado de esa IP.
 */
function nolantis_ll_unlock_ip( $ip ) {
    global $wpdb;

    $now     = time();
    $updated = $wpdb->query(
        $wpdb->prepare(
            'UPDATE ' . nolantis_ll_lockouts_table() . " SET locked_until = LEAST(locked_until, %d), unlocked = 1 WHERE kind = 'ip' AND ip = %s AND unlocked = 0 AND locked_until > %d",
            $now,
            $ip,
            $now - ( (int) nolantis_ll_get_setting( 'reincidence_hours' ) * HOUR_IN_SECONDS )
        )
    );

    nolantis_ll_log_event( $ip, 'unlock', nolantis_ll_get_current_admin_login(), 'admin' );

    return (int) $updated;
}

/* -------------------------------------------------------------------------
 * Modo protegido por usuario
 * ---------------------------------------------------------------------- */

/**
 * @return object|null
 */
function nolantis_ll_get_active_user_protection( $username ) {
    global $wpdb;

    if ( '' === (string) $username ) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . nolantis_ll_lockouts_table() . " WHERE kind = 'user' AND username = %s AND locked_until > %d ORDER BY locked_until DESC LIMIT 1",
            nolantis_ll_clean_username( $username ),
            time()
        )
    );
}

function nolantis_ll_create_user_protection( $username, $ip, $source ) {
    global $wpdb;

    $now      = time();
    $duration = (int) nolantis_ll_get_setting( 'user_protection_minutes' ) * MINUTE_IN_SECONDS;

    $wpdb->insert(
        nolantis_ll_lockouts_table(),
        array(
            'kind'         => 'user',
            'ip'           => '',
            'username'     => nolantis_ll_clean_username( $username ),
            'reason'       => 'user_attack',
            'locked_at'    => $now,
            'locked_until' => $now + $duration,
        ),
        array( '%s', '%s', '%s', '%s', '%d', '%d' )
    );

    nolantis_ll_log_event( $ip, 'userlock', $username, $source );

    $since = $now - ( (int) nolantis_ll_get_setting( 'user_window_minutes' ) * MINUTE_IN_SECONDS );

    do_action(
        'nolantis_ll_user_protection_created',
        $username,
        nolantis_ll_count_events( 'failed', 'username', $username, $since ),
        nolantis_ll_count_distinct_failed_ips( $username, $since ),
        $now + $duration
    );

    return nolantis_ll_get_active_user_protection( $username );
}

/**
 * Mientras siga el ataque, el modo protegido se renueva.
 */
function nolantis_ll_extend_user_protection( $protection_id ) {
    global $wpdb;

    $until = time() + ( (int) nolantis_ll_get_setting( 'user_protection_minutes' ) * MINUTE_IN_SECONDS );

    $wpdb->query(
        $wpdb->prepare(
            'UPDATE ' . nolantis_ll_lockouts_table() . ' SET locked_until = GREATEST(locked_until, %d) WHERE id = %d',
            $until,
            $protection_id
        )
    );
}

function nolantis_ll_unlock_user( $username ) {
    global $wpdb;

    $now     = time();
    $updated = $wpdb->query(
        $wpdb->prepare(
            'UPDATE ' . nolantis_ll_lockouts_table() . " SET locked_until = LEAST(locked_until, %d), unlocked = 1 WHERE kind = 'user' AND username = %s AND locked_until > %d",
            $now,
            $username,
            $now
        )
    );

    nolantis_ll_log_event( '', 'userunlock', $username, 'admin' );

    return (int) $updated;
}

/* -------------------------------------------------------------------------
 * Comun
 * ---------------------------------------------------------------------- */

/**
 * @return object|null
 */
function nolantis_ll_get_lockout_by_id( $id ) {
    global $wpdb;

    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . nolantis_ll_lockouts_table() . ' WHERE id = %d', $id ) );
}

/**
 * Levanta un bloqueo (de IP o de usuario) a partir de su id.
 */
function nolantis_ll_unlock_by_id( $id ) {
    $lockout = nolantis_ll_get_lockout_by_id( $id );

    if ( ! $lockout ) {
        return false;
    }

    if ( 'user' === $lockout->kind ) {
        nolantis_ll_unlock_user( $lockout->username );
    } else {
        nolantis_ll_unlock_ip( $lockout->ip );
    }

    return true;
}

function nolantis_ll_unlock_all() {
    $lockouts = nolantis_ll_get_active_lockouts( 1000 );

    foreach ( $lockouts as $lockout ) {
        nolantis_ll_unlock_by_id( $lockout->id );
    }

    return count( $lockouts );
}

/**
 * @return object[]
 */
function nolantis_ll_get_active_lockouts( $limit = 200 ) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . nolantis_ll_lockouts_table() . ' WHERE locked_until > %d ORDER BY locked_at DESC LIMIT %d',
            time(),
            $limit
        )
    );
}

/**
 * @return object[]
 */
function nolantis_ll_get_log_entries( $limit = 100, $event = '' ) {
    global $wpdb;

    if ( '' !== $event ) {
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . nolantis_ll_log_table() . ' WHERE event = %s ORDER BY id DESC LIMIT %d',
                $event,
                $limit
            )
        );
    }

    return $wpdb->get_results(
        $wpdb->prepare( 'SELECT * FROM ' . nolantis_ll_log_table() . ' ORDER BY id DESC LIMIT %d', $limit )
    );
}

/**
 * Resumen de las ultimas 24 horas para el panel.
 */
function nolantis_ll_get_stats_24h() {
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT event, COUNT(*) AS total FROM ' . nolantis_ll_log_table() . ' WHERE created_at > %d GROUP BY event',
            time() - DAY_IN_SECONDS
        ),
        OBJECT_K
    );

    $stats = array(
        'failed'   => 0,
        'lockout'  => 0,
        'userlock' => 0,
        'success'  => 0,
    );

    foreach ( $stats as $event => $total ) {
        if ( isset( $rows[ $event ] ) ) {
            $stats[ $event ] = (int) $rows[ $event ]->total;
        }
    }

    return $stats;
}

function nolantis_ll_clear_log() {
    global $wpdb;

    // DELETE en vez de TRUNCATE: algunos hostings no conceden el permiso DROP que exige TRUNCATE.
    $wpdb->query( 'DELETE FROM ' . nolantis_ll_log_table() );
}
