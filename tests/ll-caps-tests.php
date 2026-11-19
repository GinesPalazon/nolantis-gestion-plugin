<?php
/*
 * Pruebas del tope de filas del registro de intentos y del historial de bloqueos.
 * Uso: docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin < tests/ll-caps-tests.php
 * Vacia las tablas de Limit Login al terminar (son datos de pruebas).
 */
$_SERVER['HTTP_HOST'] = 'localhost:8090';
require '/var/www/html/wp-load.php';

$ok = 0;
$ko = 0;
function t( $n, $c, $d = '' ) {
    global $ok, $ko;
    if ( $c ) { $ok++; echo "  OK    $n\n"; } else { $ko++; echo "  FALLO $n $d\n"; }
}

global $wpdb;
$log      = nolantis_ll_log_table();
$lockouts = nolantis_ll_lockouts_table();
$count    = function ( $sql ) use ( $wpdb ) { return (int) $wpdb->get_var( $sql ); };

/** Inserta muchas filas de golpe en el registro (mas rapido que una a una). */
function nolantis_test_bulk_log( $rows, $ip = '198.51.100.1' ) {
    global $wpdb;
    $now = time();
    for ( $done = 0; $done < $rows; $done += 1000 ) {
        $values = array();
        for ( $i = 0; $i < min( 1000, $rows - $done ); $i++ ) {
            $values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s)', $now, $ip, 'bot', 'login', 'failed' );
        }
        $wpdb->query( 'INSERT INTO ' . nolantis_ll_log_table() . ' (created_at, ip, username, source, event) VALUES ' . implode( ',', $values ) );
    }
}

$wpdb->query( "DELETE FROM {$log}" );
$wpdb->query( "DELETE FROM {$lockouts}" );
add_filter( 'nolantis_ll_log_max_rows', function () { return 1000; } );
add_filter( 'nolantis_ll_lockouts_max_rows', function () { return 1000; } );

echo "== 1. Registro de intentos\n";
nolantis_test_bulk_log( 1500 );
$max_id = $count( "SELECT MAX(id) FROM {$log}" );
nolantis_ll_enforce_row_caps();
t( 'con 1.500 filas y tope de 1.000 => quedan 1.000', 1000 === $count( "SELECT COUNT(*) FROM {$log}" ) );
t( '  ...y son las más recientes', ( $max_id - 999 ) === $count( "SELECT MIN(id) FROM {$log}" ) );
nolantis_ll_enforce_row_caps();
t( 'por debajo del tope no se borra nada', 1000 === $count( "SELECT COUNT(*) FROM {$log}" ) );

echo "== 2. Borrado por tandas (no bloquea la tabla mucho tiempo)\n";
nolantis_test_bulk_log( 24000 );
nolantis_ll_enforce_row_caps();
t( 'una pasada borra como máximo 10.000 filas', 15000 === $count( "SELECT COUNT(*) FROM {$log}" ), (string) $count( "SELECT COUNT(*) FROM {$log}" ) );
nolantis_ll_enforce_row_caps();
nolantis_ll_enforce_row_caps();
t( 'pasadas sucesivas lo dejan en el tope', 1000 === $count( "SELECT COUNT(*) FROM {$log}" ) );

echo "== 3. Historial de bloqueos\n";
$now    = time();
$values = array();
for ( $i = 0; $i < 1200; $i++ ) {
    $values[] = $wpdb->prepare( '(%s, %s, %d, %d, %d)', 'ip', '203.0.113.' . ( $i % 250 ), 1, $now - 7200, $now - 3600 );
}
$wpdb->query( "INSERT INTO {$lockouts} (kind, ip, level, locked_at, locked_until) VALUES " . implode( ',', $values ) );
for ( $i = 1; $i <= 5; $i++ ) {
    $wpdb->insert( $lockouts, array( 'kind' => 'ip', 'ip' => '192.0.2.' . $i, 'level' => 1, 'locked_at' => $now, 'locked_until' => $now + 3600 ) );
}
nolantis_ll_enforce_row_caps();
t( '1.200 bloqueos terminados y tope de 1.000 => quedan 1.000 terminados', 1000 === $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$lockouts} WHERE locked_until < %d", $now ) ) );
t( 'los 5 bloqueos activos no se tocan', 5 === $count( $wpdb->prepare( "SELECT COUNT(*) FROM {$lockouts} WHERE locked_until > %d", $now ) ) );

echo "== 4. Durante un ataque se recorta solo (sin esperar a la limpieza diaria)\n";
$wpdb->query( "DELETE FROM {$log}" );
for ( $i = 0; $i < 5000; $i++ ) {
    nolantis_ll_log_event( '198.51.100.9', 'failed', 'bot', 'login' );
}
$rows = $count( "SELECT COUNT(*) FROM {$log}" );
t( '5.000 intentos seguidos => la tabla no pasa de 5.000 (se ha recortado sola)', $rows < 5000, (string) $rows );

echo "== 5. El bloqueo sigue funcionando tras recortar\n";
$wpdb->query( "DELETE FROM {$lockouts}" );
for ( $i = 0; $i < 3; $i++ ) {
    nolantis_ll_log_event( '203.0.113.77', 'failed', 'victima', 'login' );
}
t( 'los fallos recientes de una IP se siguen contando', 3 === nolantis_ll_count_recent_failures( '203.0.113.77' ) );

$wpdb->query( "DELETE FROM {$log}" );
$wpdb->query( "DELETE FROM {$lockouts}" );
echo "\nRESULTADO TOPE DE FILAS: $ok OK, $ko FALLOS\n";
