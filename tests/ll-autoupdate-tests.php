<?php
/*
 * Pruebas de la actualizacion automatica segura. NO instala nada: solo llama a la verificacion y
 * descarga el ZIP oficial a un archivo temporal que se borra.
 * Uso: docker exec -i -u www-data nolantis-gestion-wp php /dev/stdin < tests/ll-autoupdate-tests.php
 */
$_SERVER['HTTP_HOST'] = 'localhost:8090';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$ok = 0;
$ko = 0;
function t( $n, $c, $d = '' ) {
    global $ok, $ko;
    if ( $c ) { $ok++; echo "  OK    $n\n"; } else { $ko++; echo "  FALLO $n $d\n"; }
}

// Release publicada que se usa como referencia (debe existir en GitHub con su ZIP adjunto).
$real   = 'https://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1.0.17/nolantis-gestion-1.0.17.zip';
$sha    = 'f0e3a037da7c2094d2637ab34405ba34298688dbada914e04db2842e9338b054';
$ours   = array( 'plugin' => NOLANTIS_PLUGIN_BASENAME );
$is_dev = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

echo "== 1. Solo se fuerza la actualizacion automatica de este plugin\n";
$forced = nolantis_force_self_auto_update( null, (object) array( 'plugin' => NOLANTIS_PLUGIN_BASENAME ) );
if ( $is_dev ) {
    t( 'entorno local/desarrollo => NO se fuerza (protege el codigo de trabajo)', null === $forced );
} else {
    t( 'plugin de Nolantis => actualizacion automatica activada', true === $forced );
    t( 'a traves del filtro real de WordPress', true === apply_filters( 'auto_update_plugin', false, (object) array( 'plugin' => NOLANTIS_PLUGIN_BASENAME ) ) );
}
t( 'otro plugin sin activar => no se toca', null === nolantis_force_self_auto_update( null, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
t( 'otro plugin desactivado por el usuario => sigue desactivado', false === nolantis_force_self_auto_update( false, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );

echo "== 2. Solo se acepta el repositorio oficial\n";
$cases = array(
    array( $real, true, 'release oficial' ),
    array( 'https://github.com/ginespalazon/nolantis-gestion-plugin/releases/download/v1.0.17/nolantis-gestion-1.0.17.zip', true, 'mayusculas distintas en el usuario' ),
    array( 'http://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1.0.17/nolantis-gestion-1.0.17.zip', false, 'http:// sin cifrar' ),
    array( 'https://github.com/Atacante/nolantis-gestion-plugin/releases/download/v1.0.17/nolantis-gestion-1.0.17.zip', false, 'otro usuario de GitHub' ),
    array( 'https://github.com/GinesPalazon/otro-repo/releases/download/v1.0.17/nolantis-gestion-1.0.17.zip', false, 'otro repositorio' ),
    array( 'https://github.com.evil.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/nolantis-gestion-1.0.17.zip', false, 'dominio parecido' ),
    array( 'https://evil.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/nolantis-gestion-1.0.17.zip', false, 'otro dominio' ),
    array( 'https://github.com:8443/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/nolantis-gestion-1.0.17.zip', false, 'otro puerto' ),
    array( 'https://usuario@github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/nolantis-gestion-1.0.17.zip', false, 'credenciales en la URL' ),
    array( 'https://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/../../../Atacante/x/releases/download/v1/nolantis-gestion-1.zip', false, 'salto de carpetas (..)' ),
    array( 'https://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/%2e%2e/nolantis-gestion-1.zip', false, 'salto de carpetas codificado' ),
    array( 'https://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/malware.zip', false, 'archivo con otro nombre' ),
    array( 'https://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v1/nolantis-gestion-1.0.17.zip?x=1', false, 'con parametros' ),
    array( 'https://github.com/GinesPalazon/nolantis-gestion-plugin/archive/refs/heads/main.zip', false, 'codigo de la rama' ),
    array( 'file:///etc/passwd', false, 'archivo local' ),
);
foreach ( $cases as $c ) {
    t( ( $c[1] ? 'acepta: ' : 'rechaza: ' ) . $c[2], $c[1] === nolantis_is_trusted_update_url( $c[0] ) );
}

echo "== 3. Verificacion real contra GitHub (sin instalar nada)\n";
$digest = nolantis_get_update_asset_digest( $real );
t( 'lee la huella SHA-256 publicada por GitHub', $sha === $digest, is_wp_error( $digest ) ? $digest->get_error_message() : (string) $digest );
$file = nolantis_verify_update_package( false, $real, null, $ours );
t( 'descarga oficial => verificada y aceptada', is_string( $file ) && file_exists( $file ), is_wp_error( $file ) ? $file->get_error_message() : '' );
if ( is_string( $file ) ) {
    t( '  ...el archivo coincide con la huella', hash_file( 'sha256', $file ) === $sha );
    wp_delete_file( $file );
}

echo "== 4. Ataques y fallos\n";
$r = nolantis_verify_update_package( false, 'https://evil.com/nolantis-gestion-9.9.9.zip', null, $ours );
t( 'URL inyectada de otro dominio => bloqueada', is_wp_error( $r ) && 'nolantis_untrusted_package' === $r->get_error_code() );
$r = nolantis_verify_update_package( false, 'https://github.com/Atacante/nolantis-gestion-plugin/releases/download/v9/nolantis-gestion-9.zip', array(), array() );
t( 'URL con "nolantis-gestion" sin decir que plugin es => tambien se controla', is_wp_error( $r ) && 'nolantis_untrusted_package' === $r->get_error_code() );
$tamper = function ( $pre, $args, $url ) use ( $real ) {
    if ( $url === $real && ! empty( $args['filename'] ) ) {
        file_put_contents( $args['filename'], 'ZIP MANIPULADO' );
        return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] );
    }
    return $pre;
};
add_filter( 'pre_http_request', $tamper, 10, 3 );
$r = nolantis_verify_update_package( false, $real, null, $ours );
remove_filter( 'pre_http_request', $tamper, 10 );
t( 'ZIP manipulado durante la descarga => bloqueado por la huella', is_wp_error( $r ) && 'nolantis_update_checksum' === $r->get_error_code() );
$noapi = function ( $pre, $args, $url ) {
    return 0 === strpos( $url, 'https://api.github.com/' ) ? new WP_Error( 'http_request_failed', 'sin conexion (simulado)' ) : $pre;
};
add_filter( 'pre_http_request', $noapi, 10, 3 );
$r = nolantis_verify_update_package( false, $real, null, $ours );
remove_filter( 'pre_http_request', $noapi, 10 );
t( 'no se puede comprobar la huella => no se instala', is_wp_error( $r ) && 'nolantis_update_unverified' === $r->get_error_code() );
$r = nolantis_verify_update_package( false, 'https://github.com/GinesPalazon/nolantis-gestion-plugin/releases/download/v0.0.0-no-existe/nolantis-gestion-0.0.0.zip', null, $ours );
t( 'release que no existe => no se instala', is_wp_error( $r ) );
t( 'otros plugins no se ven afectados', false === nolantis_verify_update_package( false, 'https://downloads.wordpress.org/plugin/akismet.5.0.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ) );
define( 'NOLANTIS_DISABLE_AUTO_UPDATE', true );
t( 'con NOLANTIS_DISABLE_AUTO_UPDATE => no se fuerza', false === nolantis_force_self_auto_update( false, (object) array( 'plugin' => NOLANTIS_PLUGIN_BASENAME ) ) );

echo "\nRESULTADO ACTUALIZACION SEGURA: $ok OK, $ko FALLOS\n";
