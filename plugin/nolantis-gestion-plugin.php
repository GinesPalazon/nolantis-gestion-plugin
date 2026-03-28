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
<?php
/**
 * Plugin Name: Nolantis Gestión Plugin
 * Version:     1.0.0
 * Author:      Webs Rentables
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NOLANTIS_VERSION',     '1.0.0' );
define( 'NOLANTIS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOLANTIS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ── Auto-updater desde GitHub ──────────────────────────────
require_once NOLANTIS_PLUGIN_PATH . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/GinesPalazon/nolantis-gestion-plugin/',
    __FILE__,
    'nolantis-gestion-plugin'
);

// Usa los GitHub Releases como fuente de actualización
$updateChecker->getVcsApi()->enableReleaseAssets();
// ──────────────────────────────────────────────────────────

// Tu código del plugin a partir de aquí...

// Tu código empieza aquí
add_action( 'init', function() {
    // Inicialización del plugin
});