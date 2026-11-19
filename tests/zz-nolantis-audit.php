<?php
/* SOLO PARA PRUEBAS LOCALES DE LA AUDITORIA. Se borra al terminar. No forma parte del plugin. */
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( 0 !== strpos( $url, 'http://localhost:8090' ) || ! empty( $args['_nolantis_inner'] ) ) {
        return $pre;
    }
    if ( get_option( 'nolantis_test_loopback_404' ) ) {
        return array( 'headers' => array(), 'body' => 'Not Found', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
    }
    if ( get_option( 'nolantis_test_loopback_error' ) ) {
        return new WP_Error( 'http_request_failed', 'cURL error 7: conexion rechazada (simulada)' );
    }
    $args['_nolantis_inner'] = true;
    $args['headers']         = isset( $args['headers'] ) ? (array) $args['headers'] : array();
    $args['headers']['Host'] = 'localhost:8090';
    return wp_remote_request( str_replace( 'http://localhost:8090', 'http://localhost', $url ), $args );
}, 10, 3 );
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    file_put_contents( '/tmp/nolantis-mails.log', 'SUBJECT: ' . $atts['subject'] . ' | TO: ' . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . ' | BODY: ' . str_replace( array( "\r", "\n" ), ' ', $atts['message'] ) . "\n", FILE_APPEND );
    return get_option( 'nolantis_test_mail_passthrough' ) ? null : true;
}, 10, 2 );
add_action( 'phpmailer_init', function ( $m ) {
    if ( ! get_option( 'nolantis_test_mail_passthrough' ) ) {
        return;
    }
    file_put_contents( '/tmp/nolantis-phpmailer.log', wp_json_encode( array( 'Mailer' => $m->Mailer, 'Host' => $m->Host, 'Port' => $m->Port, 'SMTPAuth' => $m->SMTPAuth, 'Username' => $m->Username, 'Password' => $m->Password, 'SMTPSecure' => $m->SMTPSecure, 'From' => $m->From, 'Sender' => $m->Sender, 'FromName' => $m->FromName ) ) . "\n", FILE_APPEND );
    $m->Host    = '127.0.0.1';
    $m->Port    = 1;
    $m->Timeout = 2;
}, 999 );
if ( get_option( 'nolantis_test_emergency_admin_access' ) ) {
    define( 'NOLANTIS_ADMIN_ACCESS_DISABLE', true );
}
add_filter( 'pre_option_blogname', function ( $value ) {
    $override = get_option( 'nolantis_test_blogname' );
    return $override ? $override : $value;
} );
