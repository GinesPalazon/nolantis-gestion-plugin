<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nolantis_register_admin_menu() {
    $menu_icon = file_exists( NOLANTIS_PLUGIN_ICON_PATH ) ? NOLANTIS_PLUGIN_ICON_URL : 'dashicons-shield';

    add_menu_page(
        'WP-Nolantis',
        'WP-Nolantis',
        'manage_options',
        'nolantis-limit-login',
        'nolantis_render_limit_login_page',
        $menu_icon,
        56
    );

    add_submenu_page(
        'nolantis-limit-login',
        'Seguridad de acceso',
        'Seguridad de acceso',
        'manage_options',
        'nolantis-limit-login',
        'nolantis_render_limit_login_page'
    );

    add_submenu_page(
        'nolantis-limit-login',
        'SMTP',
        'SMTP',
        'manage_options',
        'nolantis-smtp',
        'nolantis_render_smtp_page'
    );

    add_submenu_page(
        'nolantis-limit-login',
        'Ruta acceso admin',
        'Ruta acceso admin',
        'manage_options',
        'nolantis-admin-access',
        'nolantis_render_admin_access_page'
    );

    add_submenu_page(
        'nolantis-limit-login',
        'Ajustes generales',
        'Ajustes generales',
        'manage_options',
        'nolantis-general-settings',
        'nolantis_render_general_settings_page'
    );

    add_submenu_page(
        'nolantis-limit-login',
        'Endurecimiento',
        'Endurecimiento',
        'manage_options',
        'nolantis-hardening',
        'nolantis_render_hardening_page'
    );
}
add_action( 'admin_menu', 'nolantis_register_admin_menu' );

function nolantis_admin_assets() {
    ?>
    <style>
        #toplevel_page_nolantis-limit-login .wp-menu-image img {
            width: 20px;
            height: 20px;
            padding: 7px 0 0;
            opacity: 1;
            object-fit: contain;
        }

        .nolantis-settings-header {
            display: flex;
            align-items: center;
            margin-bottom: 16px;
        }

        .nolantis-settings-header h1 {
            margin: 0;
        }

        .nolantis-card {
            margin-top: 24px;
            padding: 20px 24px;
            max-width: 760px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
        }

        .nolantis-card p {
            margin-top: 0;
        }

    </style>
    <?php
}
add_action( 'admin_head', 'nolantis_admin_assets' );

function nolantis_render_plugin_list_logo() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    if ( ! $screen || 'plugins' !== $screen->id || ! file_exists( NOLANTIS_PLUGIN_ICON_PATH ) ) {
        return;
    }
    ?>
    <style>
        .plugins tr[data-plugin="<?php echo esc_attr( NOLANTIS_PLUGIN_BASENAME ); ?>"] .nolantis-plugin-list-logo {
            width: 20px;
            height: 20px;
            margin-right: 8px;
            vertical-align: text-bottom;
            object-fit: contain;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var row = document.querySelector('.plugins tr[data-plugin="<?php echo esc_js( NOLANTIS_PLUGIN_BASENAME ); ?>"] .plugin-title strong');

            if (!row || row.querySelector('.nolantis-plugin-list-logo')) {
                return;
            }

            var logo = document.createElement('img');
            logo.src = '<?php echo esc_js( NOLANTIS_PLUGIN_ICON_URL ); ?>';
            logo.alt = 'Nolantis';
            logo.className = 'nolantis-plugin-list-logo';
            row.prepend(logo);
        });
    </script>
    <?php
}
add_action( 'admin_head-plugins.php', 'nolantis_render_plugin_list_logo' );

function nolantis_render_page_header( $title ) {
    ?>
    <div class="nolantis-settings-header">
        <h1><?php echo esc_html( $title ); ?></h1>
    </div>
    <?php
}

function nolantis_render_update_tools_card() {
    $notice  = nolantis_get_update_check_notice();
    $checker = nolantis_get_update_checker();
    $update  = $checker ? $checker->getUpdate() : null;
    ?>
    <div class="nolantis-card">
        <h2>Actualizaciones</h2>
        <?php if ( $notice ) : ?>
            <div class="<?php echo esc_attr( $notice['class'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
        <?php endif; ?>
        <p><strong>Repositorio:</strong> GitHub <code>GinesPalazon/nolantis-gestion-plugin</code></p>
        <?php if ( $update ) : ?>
            <p><strong>Última actualización detectada:</strong> <?php echo esc_html( $update->version ); ?></p>
        <?php else : ?>
            <p><strong>Última actualización detectada:</strong> ninguna pendiente ahora mismo.</p>
        <?php endif; ?>
        <p><strong>Canal de actualización:</strong> GitHub Releases (repositorio público).</p>
        <p><strong>Actualización automática:</strong>
            <?php if ( nolantis_is_self_auto_update_enabled() ) : ?>
                activada. Las versiones nuevas se instalan solas, solo desde las releases de <code><?php echo esc_html( NOLANTIS_UPDATE_REPOSITORY ); ?></code> y comprobando su huella SHA-256 antes de instalarlas.
            <?php else : ?>
                desactivada en esta web (entorno local o de desarrollo, o constante <code>NOLANTIS_DISABLE_AUTO_UPDATE</code>). Las versiones nuevas hay que instalarlas a mano, con la misma verificación.
            <?php endif; ?>
        </p>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <?php wp_nonce_field( 'nolantis_check_updates' ); ?>
            <input type="hidden" name="action" value="nolantis_check_updates" />
            <?php submit_button( 'Buscar actualización', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

// La pagina de Limit Login (nolantis_render_limit_login_page) esta en includes/limit-login/admin.php.

function nolantis_render_admin_access_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $settings = nolantis_get_admin_access_settings();
    $access   = ! empty( $settings['slug'] ) ? home_url( trailingslashit( $settings['slug'] ) ) : '';
    $notice   = nolantis_get_admin_access_notice();
    ?>
    <div class="wrap">
        <?php nolantis_render_page_header( 'Ruta acceso admin' ); ?>
        <?php settings_errors( NOLANTIS_ADMIN_ACCESS_OPTION ); ?>
        <?php if ( $notice ) : ?>
            <div class="<?php echo esc_attr( $notice['class'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
        <?php endif; ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'nolantis_admin_access_group' );
            do_settings_sections( 'nolantis-admin-access' );
            submit_button( 'Guardar ajustes' );
            ?>
        </form>
        <div class="nolantis-card">
            <?php if ( nolantis_admin_access_is_emergency_disabled() ) : ?>
                <div class="notice notice-error inline"><p><strong>Ruta personalizada desactivada de emergencia</strong> con <code>NOLANTIS_ADMIN_ACCESS_DISABLE</code> en <code>wp-config.php</code>. Quita esa línea cuando hayas recuperado el acceso.</p></div>
            <?php endif; ?>
            <p>La funcionalidad cambia la ruta pública del login. Una vez dentro, WordPress sigue usando el panel normal en <code>wp-admin</code>.</p>
            <?php if ( nolantis_is_admin_access_enabled() && $access ) : ?>
                <p><strong>Ruta activa:</strong> <a href="<?php echo esc_url( $access ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $access ); ?></a></p>
            <?php else : ?>
                <p><strong>Ruta activa:</strong> desactivada. WordPress sigue usando sus rutas por defecto.</p>
            <?php endif; ?>
            <p><strong>Si te quedas fuera:</strong> añade <code>define( 'NOLANTIS_ADMIN_ACCESS_DISABLE', true );</code> en <code>wp-config.php</code>, entra por <code>wp-login.php</code>, corrige el ajuste y borra la línea.</p>
            <p class="description">Ten en cuenta que algunos temas muestran enlaces de "Acceder" (por ejemplo, en los comentarios si exiges registro para comentar o en el widget "Meta"): esos enlaces llevan a la ruta personalizada.</p>
        </div>
    </div>
    <?php
}

function nolantis_render_general_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    ?>
    <div class="wrap">
        <?php nolantis_render_page_header( 'Ajustes generales' ); ?>
        <?php settings_errors( NOLANTIS_GENERAL_OPTION ); ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'nolantis_general_settings_group' );
            do_settings_sections( 'nolantis-general-settings' );
            submit_button( 'Guardar ajustes' );
            ?>
        </form>
        <div class="nolantis-card">
            <p>Estos ajustes aplican cambios globales de comportamiento en WordPress. Antes de activar una opción, revisa si la web necesita mantener compatibilidad con comentarios, formularios o integraciones de terceros.</p>
        </div>
    </div>
    <?php
}
