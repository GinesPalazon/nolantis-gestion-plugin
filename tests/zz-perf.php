<?php
/* SOLO PARA MEDIR EL RENDIMIENTO EN LOCAL. Se borra al terminar. */
if ( isset( $_SERVER['HTTP_X_NOLANTIS_OFF'] ) ) {
    add_filter( 'option_active_plugins', function ( $plugins ) {
        return array_values( array_diff( (array) $plugins, array( 'nolantis-gestion/nolantis-gestion-plugin.php' ) ) );
    } );
}
if ( ! defined( 'SAVEQUERIES' ) ) {
    define( 'SAVEQUERIES', true );
}
add_action( 'shutdown', function () {
    global $wpdb;
    $ours = array();
    foreach ( (array) $wpdb->queries as $q ) {
        if ( false !== stripos( $q[2], 'nolantis' ) ) {
            $ours[] = preg_replace( '/\s+/', ' ', substr( $q[0], 0, 140 ) ) . '  <=  ' . substr( $q[2], strrpos( $q[2], ',' ) + 1, 80 );
        }
    }
    file_put_contents( '/tmp/nolantis-perf.log', wp_json_encode( array(
        'uri'   => $_SERVER['REQUEST_URI'],
        'off'   => isset( $_SERVER['HTTP_X_NOLANTIS_OFF'] ),
        'total' => count( (array) $wpdb->queries ),
        'ours'  => $ours,
        'ms'    => round( ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 1 ),
        'mem'   => round( memory_get_peak_usage() / 1048576, 2 ),
    ) ) . "\n", FILE_APPEND );
}, 9999 );
