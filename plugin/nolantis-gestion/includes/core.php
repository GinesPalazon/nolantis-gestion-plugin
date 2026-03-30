<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT', 'nolantis_update_check_notice' );

function nolantis_get_updater_autoload_path() {
    $candidates = array(
        NOLANTIS_PLUGIN_PATH . 'vendor/autoload.php',
        NOLANTIS_ROOT_PATH . 'vendor/autoload.php',
    );

    foreach ( $candidates as $candidate ) {
        if ( file_exists( $candidate ) ) {
            return $candidate;
        }
    }

    return '';
}

function nolantis_load_updater() {
    global $nolantis_update_checker;

    $autoload = nolantis_get_updater_autoload_path();

    if ( '' === $autoload ) {
        return;
    }

    require_once $autoload;

    if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        return;
    }

    $nolantis_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/GinesPalazon/nolantis-gestion-plugin/',
        NOLANTIS_PLUGIN_FILE,
        'nolantis-gestion-plugin'
    );

    $nolantis_update_checker->getVcsApi()->enableReleaseAssets('/\.zip($|[?&#])/i');
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
    $default_message = 'La comprobacion de actualizaciones ha fallado. Revisa la conexion con GitHub o la configuracion del repositorio.';
    $first           = reset( $errors );

    if ( ! is_array( $first ) ) {
        return $default_message;
    }

    $error    = isset( $first['error'] ) ? $first['error'] : null;
    $url      = isset( $first['url'] ) ? (string) $first['url'] : '';
    $response = isset( $first['httpResponse'] ) && is_array( $first['httpResponse'] ) ? $first['httpResponse'] : array();
    $code     = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;

    if ( 404 === $code && false !== strpos( $url, '/releases/latest' ) ) {
        return 'GitHub no devuelve una release publicada todavia. Para probar las actualizaciones con este sistema, crea una release publica en GitHub y adjunta el ZIP del plugin.';
    }

    if ( is_wp_error( $error ) ) {
        return $error->get_error_message();
    }

    return $default_message;
}

function nolantis_handle_manual_update_check() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta accion.' );
    }

    check_admin_referer( 'nolantis_check_updates' );

    $redirect = admin_url( 'admin.php?page=nolantis-limit-login' );
    $checker  = nolantis_get_update_checker();

    if ( ! $checker ) {
        set_transient(
            NOLANTIS_UPDATE_CHECK_NOTICE_TRANSIENT,
            array(
                'class'   => 'notice notice-error',
                'message' => 'No se pudo inicializar el comprobador de actualizaciones. Falta la libreria del actualizador en el plugin.',
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
                'message' => sprintf( 'Se ha encontrado una nueva version disponible: %s.', $update->version ),
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

function nolantis_get_request_ip() {
    if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
        return '';
    }

    return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
}

function nolantis_get_current_request_path() {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
    $request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
    $site_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

    if ( '' !== $site_path && '/' !== $site_path && 0 === strpos( $request_path, $site_path ) ) {
        $request_path = substr( $request_path, strlen( $site_path ) );
    }

    return trim( $request_path, '/' );
}
