<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nolantis_register_admin_menu() {
    $menu_icon = file_exists( NOLANTIS_PLUGIN_LOGO_WHITE_SF_PATH ) ? NOLANTIS_PLUGIN_LOGO_WHITE_SF_URL : 'dashicons-shield';

    add_menu_page(
        'Nolantis',
        'Nolantis',
        'manage_options',
        'nolantis-limit-login',
        'nolantis_render_limit_login_page',
        $menu_icon,
        56
    );

    add_submenu_page(
        'nolantis-limit-login',
        'Limit Login',
        'Limit Login',
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
    <?php if ( file_exists( NOLANTIS_PLUGIN_LOGO_WHITE_SF_PATH ) ) : ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var menuItem = document.getElementById('toplevel_page_nolantis-limit-login');
            var menuIcon = menuItem ? menuItem.querySelector('.wp-menu-image img') : null;

            if (!menuIcon) {
                return;
            }

            var defaultLogo = '<?php echo esc_js( NOLANTIS_PLUGIN_LOGO_WHITE_SF_URL ); ?>';
            menuIcon.src = defaultLogo;
        });
    </script>
    <?php endif; ?>
    <?php
}
add_action( 'admin_head', 'nolantis_admin_assets' );

function nolantis_render_plugin_list_logo() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    if ( ! $screen || 'plugins' !== $screen->id || ! file_exists( NOLANTIS_PLUGIN_LOGO_PATH ) ) {
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
            logo.src = '<?php echo esc_js( NOLANTIS_PLUGIN_LOGO_URL ); ?>';
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

function nolantis_render_limit_login_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $ip       = nolantis_get_request_ip();
    $lock     = nolantis_get_limit_login_lock( $ip );
    $attempts = $ip ? (int) get_transient( nolantis_get_limit_login_attempts_key( $ip ) ) : 0;
    ?>
    <div class="wrap">
        <?php nolantis_render_page_header( 'Limit Login' ); ?>
        <div class="nolantis-card">
            <p>Este sistema bloquea por IP los intentos fallidos de acceso al panel.</p>
            <p><strong>Reglas activas:</strong> 3 errores bloquean 24 horas. Si la misma IP vuelve a acumular 3 errores dentro de la ventana de reincidencia, el bloqueo pasa a 7 dias. Al terminar ese bloqueo largo, el estado se limpia automaticamente.</p>
            <p><strong>Tu IP detectada:</strong> <?php echo esc_html( $ip ? $ip : 'No disponible' ); ?></p>
            <p><strong>Intentos fallidos actuales para esta IP:</strong> <?php echo esc_html( (string) $attempts ); ?></p>
            <p><strong>Estado actual:</strong>
                <?php
                if ( $lock ) {
                    echo esc_html( sprintf( 'Bloqueada hasta %s.', wp_date( 'Y-m-d H:i:s', (int) $lock['locked_until'] ) ) );
                } else {
                    echo esc_html( 'Sin bloqueo activo.' );
                }
                ?>
            </p>
        </div>
    </div>
    <?php
}

function nolantis_render_smtp_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $test_recipient = get_option( 'admin_email', '' );
    ?>
    <div class="wrap">
        <?php nolantis_render_page_header( 'SMTP' ); ?>
        <?php settings_errors( NOLANTIS_SMTP_OPTION ); ?>
        <?php nolantis_render_test_mail_notice(); ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'nolantis_settings_group' );
            do_settings_sections( 'nolantis-smtp' );
            submit_button( 'Guardar ajustes' );
            ?>
        </form>
        <div class="nolantis-card">
            <h2>Enviar correo de prueba</h2>
            <p>Utiliza este formulario para comprobar que la configuracion SMTP funciona correctamente.</p>
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <?php wp_nonce_field( 'nolantis_send_test_email' ); ?>
                <input type="hidden" name="action" value="nolantis_send_test_email" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="nolantis_test_recipient">Destinatario</label>
                        </th>
                        <td>
                            <input
                                id="nolantis_test_recipient"
                                name="test_recipient"
                                type="email"
                                class="regular-text"
                                value="<?php echo esc_attr( $test_recipient ); ?>"
                                placeholder="admin@tudominio.com"
                                required
                            />
                            <p class="description">Se enviara un email de prueba usando la configuracion SMTP guardada arriba.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Enviar correo de prueba', 'secondary', 'submit', false ); ?>
            </form>
        </div>
    </div>
    <?php
}

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
            <p>La funcionalidad cambia la ruta publica del login. Una vez dentro, WordPress seguira usando el panel normal en <code>wp-admin</code> para usuarios ya autenticados.</p>
            <?php if ( ! empty( $settings['enabled'] ) && $access ) : ?>
                <p><strong>Ruta activa:</strong> <a href="<?php echo esc_url( $access ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $access ); ?></a></p>
            <?php else : ?>
                <p><strong>Ruta activa:</strong> desactivada. WordPress sigue usando sus rutas por defecto.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function nolantis_render_test_mail_notice() {
    if ( ! isset( $_GET['nolantis_test_mail'] ) ) {
        return;
    }

    $status    = sanitize_key( wp_unslash( $_GET['nolantis_test_mail'] ) );
    $recipient = isset( $_GET['recipient'] ) ? sanitize_email( wp_unslash( $_GET['recipient'] ) ) : '';
    $messages  = array(
        'success'       => array(
            'class'   => 'notice notice-success',
            'message' => sprintf( 'Correo de prueba enviado correctamente a %s.', $recipient ),
        ),
        'invalid_email' => array(
            'class'   => 'notice notice-error',
            'message' => 'El destinatario indicado no es valido.',
        ),
        'smtp_disabled' => array(
            'class'   => 'notice notice-warning',
            'message' => 'Activa SMTP y guarda la configuracion antes de enviar un correo de prueba.',
        ),
        'missing_host'  => array(
            'class'   => 'notice notice-warning',
            'message' => 'Debes indicar el servidor SMTP antes de enviar un correo de prueba.',
        ),
        'failed'        => array(
            'class'   => 'notice notice-error',
            'message' => 'No se pudo enviar el correo de prueba. Revisa la configuracion SMTP y los logs del servidor.',
        ),
    );

    if ( ! isset( $messages[ $status ] ) ) {
        return;
    }

    printf(
        '<div class="%1$s"><p>%2$s</p></div>',
        esc_attr( $messages[ $status ]['class'] ),
        esc_html( $messages[ $status ]['message'] )
    );
}
