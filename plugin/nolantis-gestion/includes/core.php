<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT', 'nolantis_update_check_notice' );

/**
 * Unico origen permitido para las actualizaciones del plugin: las releases de este repositorio.
 */
define( 'NOLANTIS_UPDATE_REPOSITORY', 'GinesPalazon/nolantis-gestion-plugin' );
define( 'NOLANTIS_UPDATE_ASSET_PATTERN', '/^nolantis-gestion-[0-9][0-9a-zA-Z._-]*\.zip$/i' );

/**
 * Solo se carga la libreria de dentro del plugin: nunca un vendor/ de fuera de su carpeta
 * (en produccion, la carpeta superior es wp-content/plugins/).
 */
function nolantis_get_updater_autoload_path() {
    $autoload = NOLANTIS_PLUGIN_PATH . 'vendor/autoload.php';

    return file_exists( $autoload ) ? $autoload : '';
}

/**
 * El actualizador solo hace falta en el panel, en las tareas programadas (wp-cron, donde se instalan
 * las actualizaciones automaticas) y en la linea de comandos. En las visitas a la web no se carga.
 */
function nolantis_updater_is_needed() {
    return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI;
}

function nolantis_load_updater() {
    global $nolantis_update_checker;

    if ( ! nolantis_updater_is_needed() ) {
        return;
    }

    $autoload = nolantis_get_updater_autoload_path();

    if ( '' === $autoload ) {
        return;
    }

    require_once $autoload;

    if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        return;
    }

    $nolantis_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/' . NOLANTIS_UPDATE_REPOSITORY . '/',
        NOLANTIS_PLUGIN_FILE,
        'nolantis-gestion-plugin'
    );

    $nolantis_update_checker->setBranch( 'main' );

    if ( method_exists( $nolantis_update_checker->getVcsApi(), 'setReleaseFilter' ) ) {
        $nolantis_update_checker->getVcsApi()->setReleaseFilter(
            null,
            \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
            10
        );
    }

    $nolantis_update_checker->getVcsApi()->enableReleaseAssets(
        NOLANTIS_UPDATE_ASSET_PATTERN,
        \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\Api::REQUIRE_RELEASE_ASSETS
    );
}
add_action( 'plugins_loaded', 'nolantis_load_updater' );

function nolantis_get_update_checker() {
    global $nolantis_update_checker;

    return isset( $nolantis_update_checker ) ? $nolantis_update_checker : null;
}

function nolantis_get_update_check_notice() {
    $notice = get_transient( NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT );

    if ( ! is_array( $notice ) ) {
        return false;
    }

    delete_transient( NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT );

    return $notice;
}

function nolantis_get_update_checker_error_message( $errors ) {
    $default_message = 'La comprobación de actualizaciones ha fallado. Revisa la conexión con GitHub o la configuración del repositorio.';
    $first           = reset( $errors );

    if ( ! is_array( $first ) ) {
        return $default_message;
    }

    $error    = isset( $first['error'] ) ? $first['error'] : null;
    $url      = isset( $first['url'] ) ? (string) $first['url'] : '';
    $response = isset( $first['httpResponse'] ) && is_array( $first['httpResponse'] ) ? $first['httpResponse'] : array();
    $code     = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;

    if ( 404 === $code && ( false !== strpos( $url, '/releases/latest' ) || false !== strpos( $url, '/releases' ) ) ) {
        return 'GitHub no encuentra una release válida. Revisa que el repositorio sea público, que la release esté publicada, que no sea borrador ni prerelease y que tenga el ZIP del plugin adjunto.';
    }

    if ( is_wp_error( $error ) ) {
        return $error->get_error_message();
    }

    return $default_message;
}

function nolantis_handle_manual_update_check() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }

    check_admin_referer( 'nolantis_check_updates' );

    $redirect = admin_url( 'admin.php?page=nolantis-limit-login' );
    $checker  = nolantis_get_update_checker();

    if ( ! $checker ) {
        set_transient(
            NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT,
            array(
                'class'   => 'notice notice-error',
                'message' => 'No se pudo inicializar el comprobador de actualizaciones. Falta la librería del actualizador en el plugin.',
            ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    delete_site_transient( 'update_plugins' );
    wp_clean_plugins_cache( true );

    $update = $checker->checkForUpdates();
    $errors = $checker->getLastRequestApiErrors();

    if ( $update ) {
        set_transient(
            NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT,
            array(
                'class'   => 'notice notice-success',
                'message' => sprintf( 'Se ha encontrado una nueva versión disponible: %s.', $update->version ),
            ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    if ( ! empty( $errors ) ) {
        set_transient(
            NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT,
            array(
                'class'   => 'notice notice-warning',
                'message' => nolantis_get_update_checker_error_message( $errors ),
            ),
            MINUTE_IN_SECONDS
        );
    } else {
        set_transient(
            NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT,
            array(
                'class'   => 'notice notice-info',
                'message' => 'No hay nuevas actualizaciones disponibles en este momento.',
            ),
            MINUTE_IN_SECONDS
        );
    }

    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_nolantis_check_updates', 'nolantis_handle_manual_update_check' );

function nolantis_fix_update_package_directory( $source, $remote_source, $upgrader, $hook_extra = array() ) {
    if ( empty( $hook_extra['plugin'] ) || NOLANTIS_PLUGIN_BASENAME !== $hook_extra['plugin'] ) {
        return $source;
    }

    $expected_directory = dirname( NOLANTIS_PLUGIN_BASENAME );

    if ( '.' === $expected_directory || '' === $expected_directory || basename( $source ) === $expected_directory ) {
        return $source;
    }

    $target = trailingslashit( $remote_source ) . $expected_directory;

    if ( file_exists( $target ) ) {
        return $source;
    }

    if ( @rename( $source, $target ) ) {
        return $target;
    }

    return $source;
}
add_filter( 'upgrader_source_selection', 'nolantis_fix_update_package_directory', 10, 4 );

// nolantis_get_request_ip() esta en includes/limit-login/ip.php (respeta el ajuste "Origen de la IP").

function nolantis_get_current_request_path() {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
    $request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
    $site_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

    if ( '' !== $site_path && '/' !== $site_path && 0 === strpos( $request_path, $site_path ) ) {
        $request_path = substr( $request_path, strlen( $site_path ) );
    }

    return trim( sanitize_text_field( $request_path ), '/' );
}

/* -------------------------------------------------------------------------
 * Actualizacion automatica del plugin (solo de este plugin y solo desde su repositorio)
 * ---------------------------------------------------------------------- */

/**
 * La actualizacion automatica esta activa salvo que se desactive con la constante o que la web sea
 * un entorno local o de desarrollo: ahi la carpeta del plugin suele ser el codigo de trabajo y una
 * actualizacion lo sobrescribiria.
 */
function nolantis_is_self_auto_update_enabled() {
    if ( defined( 'NOLANTIS_DISABLE_AUTO_UPDATE' ) && NOLANTIS_DISABLE_AUTO_UPDATE ) {
        return false;
    }

    return ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
}

/**
 * Activa la actualizacion automatica unicamente para este plugin; el resto no se toca.
 */
function nolantis_force_self_auto_update( $update, $item ) {
    $plugin = is_object( $item ) && isset( $item->plugin ) ? $item->plugin : '';

    if ( NOLANTIS_PLUGIN_BASENAME !== $plugin ) {
        return $update;
    }

    return nolantis_is_self_auto_update_enabled() ? true : $update;
}
add_filter( 'auto_update_plugin', 'nolantis_force_self_auto_update', 20, 2 );

/**
 * Solo se acepta un ZIP de las releases del repositorio oficial, por HTTPS y con el nombre esperado.
 * Cualquier otra URL (otro repositorio, otro dominio, http://, un dominio parecido...) se rechaza,
 * aunque otro codigo haya modificado la informacion de actualizaciones de WordPress.
 */
function nolantis_is_trusted_update_url( $url ) {
    $parts = wp_parse_url( (string) $url );

    if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
        return false;
    }

    if ( 'https' !== strtolower( $parts['scheme'] ) || 'github.com' !== strtolower( $parts['host'] ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
        return false;
    }

    $prefix = '/' . strtolower( NOLANTIS_UPDATE_REPOSITORY ) . '/releases/download/';
    $path   = rawurldecode( $parts['path'] );

    if ( 0 !== strpos( strtolower( $path ), $prefix ) || false !== strpos( $path, '..' ) ) {
        return false;
    }

    $rest = explode( '/', substr( $path, strlen( $prefix ) ) );

    return 2 === count( $rest ) && '' !== $rest[0] && (bool) preg_match( NOLANTIS_UPDATE_ASSET_PATTERN, $rest[1] );
}

/**
 * Huella SHA-256 que GitHub publica para ese archivo de la release.
 *
 * @return string|WP_Error Huella en hexadecimal.
 */
function nolantis_get_update_asset_digest( $url ) {
    $path  = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
    $parts = explode( '/', trim( $path, '/' ) );
    $tag   = isset( $parts[4] ) ? $parts[4] : '';
    $name  = isset( $parts[5] ) ? $parts[5] : '';

    $response = wp_remote_get(
        'https://api.github.com/repos/' . NOLANTIS_UPDATE_REPOSITORY . '/releases/tags/' . rawurlencode( $tag ),
        array(
            'timeout' => 15,
            'headers' => array( 'Accept' => 'application/vnd.github+json' ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return new WP_Error( 'nolantis_update_api', 'GitHub no ha devuelto la información de la release (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
    }

    $release = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
        return new WP_Error( 'nolantis_update_not_stable', 'La release no es una versión publicada y estable.' );
    }

    foreach ( isset( $release['assets'] ) ? (array) $release['assets'] : array() as $asset ) {
        if ( isset( $asset['name'], $asset['digest'] ) && $asset['name'] === $name && preg_match( '/^sha256:([a-f0-9]{64})$/i', $asset['digest'], $matches ) ) {
            return strtolower( $matches[1] );
        }
    }

    return new WP_Error( 'nolantis_update_no_digest', 'GitHub no publica la huella SHA-256 de este archivo, así que no se puede verificar.' );
}

/**
 * Antes de instalar una actualizacion del plugin (automatica o pulsando "Actualizar"):
 * 1. la URL tiene que ser del repositorio oficial;
 * 2. el ZIP descargado tiene que tener la huella SHA-256 que publica GitHub para esa release.
 * Si algo no cuadra, no se instala nada y WordPress lo reintentara mas adelante.
 */
function nolantis_verify_update_package( $reply, $package, $upgrader, $hook_extra = array() ) {
    if ( false !== $reply ) {
        return $reply;
    }

    $is_ours = ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && NOLANTIS_PLUGIN_BASENAME === $hook_extra['plugin'] )
        || false !== stripos( (string) $package, 'nolantis-gestion' );

    if ( ! $is_ours ) {
        return $reply;
    }

    if ( ! nolantis_is_trusted_update_url( $package ) ) {
        return new WP_Error( 'nolantis_untrusted_package', 'Actualización bloqueada: el archivo no procede del repositorio oficial de Nolantis en GitHub.' );
    }

    $expected = nolantis_get_update_asset_digest( $package );

    if ( is_wp_error( $expected ) ) {
        return new WP_Error( 'nolantis_update_unverified', 'Actualización aplazada: no se ha podido verificar el archivo. ' . $expected->get_error_message() );
    }

    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $file = download_url( $package, 300 );

    if ( is_wp_error( $file ) ) {
        return $file;
    }

    if ( ! hash_equals( $expected, (string) hash_file( 'sha256', $file ) ) ) {
        wp_delete_file( $file );

        return new WP_Error( 'nolantis_update_checksum', 'Actualización bloqueada: el archivo descargado no coincide con la huella SHA-256 publicada en GitHub.' );
    }

    return $file;
}
add_filter( 'upgrader_pre_download', 'nolantis_verify_update_package', 20, 4 );
