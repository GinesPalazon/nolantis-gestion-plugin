<?php
/*
 * Servidor SMTP minimo SOLO para pruebas locales del plugin. No reenvia nada: guarda lo recibido
 * en /tmp/fake-smtp-received.log. Se apaga solo a los 15 minutos.
 *
 * Uso: php fake-smtp.php <puerto> <usuario> <contrasena> [tls]
 * Con "tls" anuncia STARTTLS con un certificado autofirmado (no valido para un cliente que lo verifique).
 */
$port     = (int) $argv[1];
$user     = $argv[2];
$pass     = $argv[3];
$tls      = ! empty( $argv[4] );
$out      = '/tmp/fake-smtp-received.log';
$pem      = '/tmp/fake-smtp-' . $port . '.pem';
$deadline = time() + 900;
$ctx      = stream_context_create();

if ( $tls ) {
    $key  = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
    $cert = openssl_csr_sign( openssl_csr_new( array( 'commonName' => 'fake.smtp.test' ), $key ), null, $key, 1 );
    openssl_x509_export( $cert, $cert_pem );
    openssl_pkey_export( $key, $key_pem );
    file_put_contents( $pem, $cert_pem . $key_pem );
    stream_context_set_option( $ctx, 'ssl', 'local_cert', $pem );
    stream_context_set_option( $ctx, 'ssl', 'verify_peer', false );
}

$server = stream_socket_server( "tcp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx );

if ( ! $server ) {
    fwrite( STDERR, "No se pudo abrir el puerto $port: $errstr\n" );
    exit( 1 );
}

while ( time() < $deadline ) {
    $c = @stream_socket_accept( $server, 5 );

    if ( ! $c ) {
        continue;
    }

    stream_set_timeout( $c, 20 );
    $w      = function ( $s ) use ( $c ) { fwrite( $c, $s . "\r\n" ); };
    $state  = 'cmd';
    $authed = false;
    $in_tls = false;
    $from   = '';
    $rcpt   = array();
    $data   = '';
    $u      = '';

    $w( '220 fake.smtp.test ESMTP listo' );

    while ( false !== ( $line = fgets( $c ) ) ) {
        $l = rtrim( $line, "\r\n" );

        if ( 'data' === $state ) {
            if ( '.' === $l ) {
                file_put_contents( $out, json_encode( array( 'from' => $from, 'rcpt' => $rcpt, 'data' => $data ) ) . "\n", FILE_APPEND );
                $w( '250 2.0.0 OK queued' );
                $state = 'cmd';
                $data  = '';
                $rcpt  = array();
            } else {
                $data .= $l . "\n";
            }
            continue;
        }

        if ( 'auth_user' === $state ) {
            $u     = base64_decode( $l );
            $state = 'auth_pass';
            $w( '334 UGFzc3dvcmQ6' );
            continue;
        }

        if ( 'auth_pass' === $state ) {
            $state  = 'cmd';
            $authed = ( $u === $user && base64_decode( $l ) === $pass );
            $w( $authed ? '235 2.7.0 Authentication successful' : '535 5.7.8 Username and Password not accepted' );
            continue;
        }

        $upper = strtoupper( $l );

        if ( 0 === strpos( $upper, 'EHLO' ) ) {
            $w( '250-fake.smtp.test' );
            if ( $tls && ! $in_tls ) {
                $w( '250-STARTTLS' );
            }
            $w( '250-AUTH LOGIN' );
            $w( '250 SIZE 42100000' );
        } elseif ( 0 === strpos( $upper, 'HELO' ) ) {
            $w( '250 fake.smtp.test' );
        } elseif ( 'STARTTLS' === $upper && $tls ) {
            $w( '220 2.0.0 Ready to start TLS' );
            $in_tls = @stream_socket_enable_crypto( $c, true, STREAM_CRYPTO_METHOD_TLS_SERVER );
            if ( ! $in_tls ) {
                break;
            }
        } elseif ( 0 === strpos( $upper, 'AUTH LOGIN' ) ) {
            $state = 'auth_user';
            $w( '334 VXNlcm5hbWU6' );
        } elseif ( 0 === strpos( $upper, 'MAIL FROM' ) ) {
            if ( '' !== $user && ! $authed ) {
                $w( '530 5.7.0 Authentication required' );
            } else {
                $from = $l;
                $w( '250 2.1.0 OK' );
            }
        } elseif ( 0 === strpos( $upper, 'RCPT TO' ) ) {
            $rcpt[] = $l;
            $w( '250 2.1.5 OK' );
        } elseif ( 'DATA' === $upper ) {
            $state = 'data';
            $w( '354 End data with <CR><LF>.<CR><LF>' );
        } elseif ( 'RSET' === $upper || 'NOOP' === $upper ) {
            $w( '250 OK' );
        } elseif ( 'QUIT' === $upper ) {
            $w( '221 Bye' );
            break;
        } else {
            $w( '502 5.5.2 Command not recognized' );
        }
    }

    fclose( $c );
}
