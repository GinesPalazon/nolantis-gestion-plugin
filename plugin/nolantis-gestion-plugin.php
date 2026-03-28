<?php
/**
 * Plugin Name:       Nolantis Gestión Plugin
 * Plugin URI:        https://nolantis.com
 * Description:       Plugin de gestión para Nolantis.
 * Version:           1.0.0
 * Author:            Nolantis
 * Author URI:        https://nolantis.com
 * License:           GPL-2.0+
 * Text Domain:       nolantis-gestion
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Seguridad: evitar acceso directo
}

define( 'NOLANTIS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Tu código empieza aquí
add_action( 'init', function() {
    // Inicialización del plugin
});