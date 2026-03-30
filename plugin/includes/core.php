<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nolantis_load_updater() {
    $autoload = NOLANTIS_ROOT_PATH . 'vendor/autoload.php';

    if ( ! file_exists( $autoload ) ) {
        return;
    }

    require_once $autoload;

    if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        return;
    }

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/GinesPalazon/nolantis-gestion-plugin/',
        NOLANTIS_PLUGIN_FILE,
        'nolantis-gestion-plugin'
    );

    $update_checker->getVcsApi()->enableReleaseAssets();
}
add_action( 'plugins_loaded', 'nolantis_load_updater' );

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
