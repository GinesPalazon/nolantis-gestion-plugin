<?php
/**
 * SMTP: panel (WP-Nolantis > SMTP), envio de prueba y prueba de conexion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_SMTP_RESULT_TRANSIENT', 'nolantis_smtp_result_' );

function nolantis_smtp_admin_url( $tab = 'ajustes', $args = array() ) {
    return add_query_arg( array_merge( array( 'page' => 'nolantis-smtp', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}

function nolantis_smtp_check_admin_action( $nonce_action ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }

    check_admin_referer( $nonce_action );
}

/**
 * Guarda el resultado de una prueba para mostrarlo tras la redireccion (solo a ese usuario).
 */
function nolantis_smtp_store_result( $result ) {
    set_transient( NOLANTIS_SMTP_RESULT_TRANSIENT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
    wp_safe_redirect( nolantis_smtp_admin_url( 'probar' ) );
    exit;
}

function nolantis_smtp_describe_method() {
    $settings = nolantis_get_smtp_settings();

    if ( nolantis_is_smtp_active() ) {
        return sprintf( 'SMTP (%1$s, puerto %2$d, %3$s)', $settings['host'], $settings['port'], 'none' === $settings['encryption'] ? 'sin cifrado' : strtoupper( $settings['encryption'] ) );
    }

    return 'la función mail() del servidor (SMTP desactivado)';
}

/* -------------------------------------------------------------------------
 * Acciones
 * ---------------------------------------------------------------------- */

function nolantis_handle_test_email() {
    nolantis_smtp_check_admin_action( 'nolantis_send_test_email' );

    $recipient = isset( $_POST['test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['test_recipient'] ) ) : '';

    if ( ! is_email( $recipient ) ) {
        nolantis_smtp_store_result( array( 'ok' => false, 'title' => 'El destinatario indicado no es válido.' ) );
    }

    $captured = null;
    $listener = function ( $error ) use ( &$captured ) {
        $captured = $error;
    };

    add_action( 'wp_mail_failed', $listener );

    $sent = wp_mail(
        $recipient,
        sprintf( 'Prueba de correo de %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
        sprintf(
            "Hola,\n\nEste es un correo de prueba enviado desde %1\$s mediante %2\$s.\n\nFecha: %3\$s\n\nSi lo has recibido, el envío de correos de la web funciona correctamente.",
            home_url( '/' ),
            nolantis_smtp_describe_method(),
            wp_date( 'd/m/Y H:i:s' )
        )
    );

    remove_action( 'wp_mail_failed', $listener );

    if ( $sent ) {
        nolantis_smtp_store_result(
            array(
                'ok'    => true,
                'title' => sprintf( 'Correo de prueba enviado a %1$s mediante %2$s. Si no llega en unos minutos, revisa la carpeta de spam.', $recipient, nolantis_smtp_describe_method() ),
            )
        );
    }

    $error = is_wp_error( $captured ) ? $captured->get_error_message() : 'wp_mail() devolvió un error sin detalles.';
    $debug = nolantis_smtp_get_debug();

    nolantis_smtp_store_result(
        array(
            'ok'    => false,
            'title' => sprintf( 'No se pudo enviar el correo de prueba mediante %s.', nolantis_smtp_describe_method() ),
            'error' => $error,
            'hint'  => nolantis_smtp_explain_error( $error, $debug ),
            'debug' => $debug,
        )
    );
}
add_action( 'admin_post_nolantis_send_test_email', 'nolantis_handle_test_email' );

/**
 * Conecta e inicia sesion en el servidor SMTP con los ajustes guardados, sin enviar ningun correo.
 */
function nolantis_handle_test_connection() {
    nolantis_smtp_check_admin_action( 'nolantis_smtp_test_connection' );

    $settings = nolantis_get_smtp_settings();

    if ( '' === $settings['host'] ) {
        nolantis_smtp_store_result( array( 'ok' => false, 'title' => 'Indica y guarda el servidor SMTP antes de probar la conexión.' ) );
    }

    if ( ! empty( $settings['auth'] ) && 'ok' !== nolantis_get_smtp_password_status() ) {
        nolantis_smtp_store_result(
            array(
                'ok'    => false,
                'title' => 'unreadable' === nolantis_get_smtp_password_status()
                    ? 'La contraseña SMTP guardada no se puede leer (¿han cambiado las claves de wp-config.php?). Escríbela de nuevo en Ajustes y guarda.'
                    : 'Has activado la autenticación pero no hay ninguna contraseña guardada. Escríbela en Ajustes y guarda.',
            )
        );
    }

    if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }

    nolantis_smtp_reset_debug();

    $settings['log_mode'] = 'all';
    $mailer               = new \PHPMailer\PHPMailer\PHPMailer( true );
    nolantis_apply_smtp_settings( $mailer, $settings );

    $error = '';

    try {
        $connected = $mailer->smtpConnect();
        $mailer->smtpClose();
    } catch ( \PHPMailer\PHPMailer\Exception $exception ) {
        $connected = false;
        $error     = $exception->getMessage();
    }

    $target = sprintf( '%1$s:%2$d', $settings['host'], $settings['port'] );

    if ( $connected ) {
        nolantis_smtp_store_result(
            array(
                'ok'    => true,
                'title' => $settings['auth']
                    ? sprintf( 'Conexión correcta con %s y el usuario y la contraseña son válidos. No se ha enviado ningún correo.', $target )
                    : sprintf( 'Conexión correcta con %s. No se ha enviado ningún correo.', $target ),
                'debug' => nolantis_smtp_get_debug(),
            )
        );
    }

    $debug = nolantis_smtp_get_debug();
    $error = '' !== $error ? $error : 'No se pudo conectar con el servidor SMTP.';

    nolantis_smtp_store_result(
        array(
            'ok'    => false,
            'title' => sprintf( 'No se pudo conectar o iniciar sesión en %s.', $target ),
            'error' => $error,
            'hint'  => nolantis_smtp_explain_error( $error, $debug ),
            'debug' => $debug,
        )
    );
}
add_action( 'admin_post_nolantis_smtp_test_connection', 'nolantis_handle_test_connection' );

function nolantis_handle_clear_mail_log() {
    nolantis_smtp_check_admin_action( 'nolantis_clear_mail_log' );
    nolantis_mail_log_clear();

    wp_safe_redirect( nolantis_smtp_admin_url( 'registro', array( 'cleared' => 1 ) ) );
    exit;
}
add_action( 'admin_post_nolantis_clear_mail_log', 'nolantis_handle_clear_mail_log' );

/* -------------------------------------------------------------------------
 * Pagina
 * ---------------------------------------------------------------------- */

function nolantis_render_smtp_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $tabs = array(
        'ajustes'  => 'Ajustes',
        'probar'   => 'Probar',
        'registro' => 'Registro de envíos',
    );
    $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'ajustes';
    $tab  = isset( $tabs[ $tab ] ) ? $tab : 'ajustes';
    ?>
    <div class="wrap nolantis-smtp">
        <?php nolantis_render_page_header( 'Correo (SMTP)' ); ?>
        <style>
            .nolantis-smtp .nolantis-card { max-width: 1100px; }
            .nolantis-smtp .nolantis-card h2 { margin-top: 0; }
            .nolantis-smtp pre.nolantis-debug { max-height: 320px; overflow: auto; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; font-size: 12px; white-space: pre-wrap; }
            .nolantis-smtp .nolantis-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
            .nolantis-smtp .nolantis-badge-sent { background: #edfaef; color: #1a6e2c; }
            .nolantis-smtp .nolantis-badge-failed { background: #fcf0f1; color: #8a2424; }
            .nolantis-smtp .nolantis-preset-note { margin-top: 8px; }
            .nolantis-smtp .nolantis-stats { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 12px; }
            .nolantis-smtp .nolantis-stat { flex: 1 1 140px; padding: 12px 16px; border: 1px solid #dcdcde; border-radius: 8px; background: #f6f7f7; }
            .nolantis-smtp .nolantis-stat strong { display: block; font-size: 24px; }
            .nolantis-smtp table.widefat td { vertical-align: top; }
        </style>
        <?php settings_errors( NOLANTIS_SMTP_OPTION ); ?>
        <?php nolantis_smtp_render_status_notices(); ?>
        <nav class="nav-tab-wrapper">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a href="<?php echo esc_url( nolantis_smtp_admin_url( $slug ) ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
        if ( 'probar' === $tab ) {
            nolantis_smtp_render_test_tab();
        } elseif ( 'registro' === $tab ) {
            nolantis_smtp_render_log_tab();
        } else {
            nolantis_smtp_render_settings_tab();
        }
        ?>
    </div>
    <?php
}

function nolantis_smtp_render_status_notices() {
    if ( 'unreadable' === nolantis_get_smtp_password_status() ) {
        echo '<div class="notice notice-error"><p><strong>La contraseña SMTP guardada no se puede leer.</strong> Suele pasar cuando cambian las claves de seguridad de <code>wp-config.php</code> o se migra la web. Escribe de nuevo la contraseña en Ajustes y guarda.</p></div>';
    }

    if ( ! nolantis_is_smtp_active() ) {
        echo '<div class="notice notice-info"><p>SMTP está desactivado: WordPress envía los correos con la función <code>mail()</code> del servidor, que a menudo acaban en spam o no llegan.</p></div>';
    }
}

/* -------------------------------------------------------------------------
 * Pestana Ajustes
 * ---------------------------------------------------------------------- */

function nolantis_smtp_field_name( $key ) {
    return NOLANTIS_SMTP_OPTION . '[' . $key . ']';
}

function nolantis_smtp_render_settings_tab() {
    $settings = nolantis_get_smtp_settings();
    $status   = nolantis_get_smtp_password_status();
    $presets  = nolantis_get_smtp_presets();
    ?>
    <form action="options.php" method="post" autocomplete="off">
        <?php settings_fields( 'nolantis_settings_group' ); ?>
        <div class="nolantis-card">
            <h2>Servidor de correo</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Activar SMTP</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( nolantis_smtp_field_name( 'enabled' ) ); ?>" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> Enviar los correos de WordPress a través de este servidor SMTP</label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_preset">Proveedor</label></th>
                    <td>
                        <select id="nolantis_smtp_preset">
                            <option value="">— Elige uno para rellenar servidor, puerto y cifrado —</option>
                            <?php foreach ( $presets as $key => $preset ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" data-host="<?php echo esc_attr( $preset[1] ); ?>" data-port="<?php echo esc_attr( $preset[2] ); ?>" data-encryption="<?php echo esc_attr( $preset[3] ); ?>" data-note="<?php echo esc_attr( $preset[4] ); ?>"><?php echo esc_html( $preset[0] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description nolantis-preset-note" id="nolantis_smtp_preset_note">Valores habituales de cada proveedor: compruébalos con el tuyo. Solo rellena el formulario; no se guarda nada hasta que pulses "Guardar ajustes".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_host">Servidor SMTP</label></th>
                    <td><input type="text" id="nolantis_smtp_host" class="regular-text" name="<?php echo esc_attr( nolantis_smtp_field_name( 'host' ) ); ?>" value="<?php echo esc_attr( $settings['host'] ); ?>" placeholder="smtp.tudominio.com" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_port">Puerto</label></th>
                    <td><input type="number" id="nolantis_smtp_port" class="small-text" min="1" max="65535" name="<?php echo esc_attr( nolantis_smtp_field_name( 'port' ) ); ?>" value="<?php echo esc_attr( $settings['port'] ); ?>" /> <span class="description">Normalmente 587 (TLS) o 465 (SSL).</span></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_encryption">Cifrado</label></th>
                    <td>
                        <select id="nolantis_smtp_encryption" name="<?php echo esc_attr( nolantis_smtp_field_name( 'encryption' ) ); ?>">
                            <option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>>TLS (STARTTLS, puerto 587)</option>
                            <option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>>SSL (puerto 465)</option>
                            <option value="none" <?php selected( $settings['encryption'], 'none' ); ?>>Sin cifrado (solo servidores internos)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Autenticación</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( nolantis_smtp_field_name( 'auth' ) ); ?>" value="1" <?php checked( ! empty( $settings['auth'] ) ); ?> /> El servidor requiere usuario y contraseña</label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_username">Usuario</label></th>
                    <td><input type="text" id="nolantis_smtp_username" class="regular-text" name="<?php echo esc_attr( nolantis_smtp_field_name( 'username' ) ); ?>" value="<?php echo esc_attr( $settings['username'] ); ?>" placeholder="usuario@tudominio.com" autocomplete="off" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_password">Contraseña</label></th>
                    <td>
                        <input type="password" id="nolantis_smtp_password" class="regular-text" name="<?php echo esc_attr( nolantis_smtp_field_name( 'password' ) ); ?>" value="" placeholder="<?php echo esc_attr( 'ok' === $status ? 'Guardada. Déjala en blanco para mantenerla' : '' ); ?>" autocomplete="new-password" />
                        <?php if ( 'ok' === $status ) : ?>
                            <p class="description">Hay una contraseña guardada (cifrada). Escribe una nueva solo si quieres cambiarla.</p>
                            <label><input type="checkbox" name="<?php echo esc_attr( nolantis_smtp_field_name( 'clear_password' ) ); ?>" value="1" /> Borrar la contraseña guardada</label>
                        <?php elseif ( 'unreadable' === $status ) : ?>
                            <p class="description" style="color:#b32d2e;">La contraseña guardada no se puede leer: escríbela de nuevo.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="nolantis-card">
            <h2>Remitente</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="nolantis_smtp_from_email">Email remitente</label></th>
                    <td>
                        <input type="email" id="nolantis_smtp_from_email" class="regular-text" name="<?php echo esc_attr( nolantis_smtp_field_name( 'from_email' ) ); ?>" value="<?php echo esc_attr( $settings['from_email'] ); ?>" placeholder="no-reply@tudominio.com" />
                        <p class="description">Debe ser la misma cuenta del servidor SMTP o una dirección autorizada en él; si no, muchos servidores rechazan el envío.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Forzar remitente</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr( nolantis_smtp_field_name( 'force_from_email' ) ); ?>" value="1" <?php checked( ! empty( $settings['force_from_email'] ) ); ?> /> Usar este email como remitente en todos los correos de la web</label><br />
                        <label><input type="checkbox" name="<?php echo esc_attr( nolantis_smtp_field_name( 'reply_to_original' ) ); ?>" value="1" <?php checked( ! empty( $settings['reply_to_original'] ) ); ?> /> Si un formulario indicaba otro remitente (p. ej. el email del visitante), ponerlo como "Responder a"</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_from_name">Nombre del remitente</label></th>
                    <td><input type="text" id="nolantis_smtp_from_name" class="regular-text" name="<?php echo esc_attr( nolantis_smtp_field_name( 'from_name' ) ); ?>" value="<?php echo esc_attr( $settings['from_name'] ); ?>" /></td>
                </tr>
            </table>
        </div>

        <div class="nolantis-card">
            <h2>Registro de envíos</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="nolantis_smtp_log_mode">Registrar</label></th>
                    <td>
                        <select id="nolantis_smtp_log_mode" name="<?php echo esc_attr( nolantis_smtp_field_name( 'log_mode' ) ); ?>">
                            <option value="all" <?php selected( $settings['log_mode'], 'all' ); ?>>Todos los envíos</option>
                            <option value="errors" <?php selected( $settings['log_mode'], 'errors' ); ?>>Solo los que fallan</option>
                            <option value="off" <?php selected( $settings['log_mode'], 'off' ); ?>>Nada</option>
                        </select>
                        <p class="description">Se guarda la fecha, el destinatario y el asunto (nunca el contenido). En los fallos, además, el error y la respuesta del servidor (sin contraseñas).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nolantis_smtp_log_retention">Conservar</label></th>
                    <td><input type="number" id="nolantis_smtp_log_retention" class="small-text" min="1" max="365" name="<?php echo esc_attr( nolantis_smtp_field_name( 'log_retention_days' ) ); ?>" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" /> días <span class="description">(los emails de los destinatarios son datos personales: no los guardes más de lo necesario).</span></td>
                </tr>
            </table>
        </div>
        <?php submit_button( 'Guardar ajustes' ); ?>
    </form>
    <script>
        (function () {
            var select = document.getElementById('nolantis_smtp_preset');
            var note = document.getElementById('nolantis_smtp_preset_note');
            if (!select) { return; }
            var defaultNote = note.textContent;
            select.addEventListener('change', function () {
                var option = select.options[select.selectedIndex];
                if (!option.value) { note.textContent = defaultNote; return; }
                document.getElementById('nolantis_smtp_host').value = option.getAttribute('data-host');
                document.getElementById('nolantis_smtp_port').value = option.getAttribute('data-port');
                document.getElementById('nolantis_smtp_encryption').value = option.getAttribute('data-encryption');
                note.textContent = option.getAttribute('data-note') + ' Revisa los datos y pulsa "Guardar ajustes".';
            });
        })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestana Probar
 * ---------------------------------------------------------------------- */

function nolantis_smtp_render_result() {
    $key    = NOLANTIS_SMTP_RESULT_TRANSIENT . get_current_user_id();
    $result = get_transient( $key );

    if ( ! is_array( $result ) ) {
        return;
    }

    delete_transient( $key );
    ?>
    <div class="notice <?php echo ! empty( $result['ok'] ) ? 'notice-success' : 'notice-error'; ?>" style="max-width:1100px;box-sizing:border-box;">
        <p><strong><?php echo esc_html( $result['title'] ); ?></strong></p>
        <?php if ( ! empty( $result['hint'] ) ) : ?>
            <p><strong>Causa probable:</strong> <?php echo esc_html( $result['hint'] ); ?></p>
        <?php endif; ?>
        <?php if ( ! empty( $result['error'] ) ) : ?>
            <p><strong>Error:</strong> <code><?php echo esc_html( $result['error'] ); ?></code></p>
        <?php endif; ?>
        <?php if ( ! empty( $result['debug'] ) ) : ?>
            <details<?php echo empty( $result['ok'] ) ? ' open' : ''; ?>>
                <summary>Conversación con el servidor</summary>
                <pre class="nolantis-debug"><?php echo esc_html( implode( "\n", (array) $result['debug'] ) ); ?></pre>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

function nolantis_smtp_render_test_tab() {
    nolantis_smtp_render_result();
    ?>
    <div class="nolantis-card">
        <h2>Enviar correo de prueba</h2>
        <p>Envía un correo real con la configuración guardada (ahora: <?php echo esc_html( nolantis_smtp_describe_method() ); ?>). Si falla, verás el motivo y la respuesta del servidor.</p>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <?php wp_nonce_field( 'nolantis_send_test_email' ); ?>
            <input type="hidden" name="action" value="nolantis_send_test_email" />
            <p>
                <label for="nolantis_test_recipient">Destinatario</label><br />
                <input id="nolantis_test_recipient" name="test_recipient" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>" required />
            </p>
            <?php submit_button( 'Enviar correo de prueba', 'primary', 'submit', false ); ?>
        </form>
    </div>
    <div class="nolantis-card">
        <h2>Probar conexión</h2>
        <p>Conecta con el servidor SMTP guardado y comprueba el usuario y la contraseña <strong>sin enviar ningún correo</strong>. Funciona aunque SMTP aún no esté activado.</p>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <?php wp_nonce_field( 'nolantis_smtp_test_connection' ); ?>
            <input type="hidden" name="action" value="nolantis_smtp_test_connection" />
            <?php submit_button( 'Probar conexión', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestana Registro
 * ---------------------------------------------------------------------- */

function nolantis_smtp_render_log_tab() {
    $settings = nolantis_get_smtp_settings();
    $status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    $status   = in_array( $status, array( 'sent', 'failed' ), true ) ? $status : '';
    $entries  = nolantis_mail_log_get_entries( 100, $status );
    $day      = nolantis_mail_log_stats( time() - DAY_IN_SECONDS );
    $week     = nolantis_mail_log_stats( time() - WEEK_IN_SECONDS );
    $labels   = array(
        'sent'   => 'Enviado',
        'failed' => 'Fallido',
    );

    if ( ! empty( $_GET['cleared'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Registro vaciado.</p></div>';
    }
    ?>
    <div class="nolantis-card">
        <h2>Registro de envíos</h2>
        <?php if ( 'off' === $settings['log_mode'] ) : ?>
            <p><strong>El registro está desactivado.</strong> Actívalo en la pestaña Ajustes para saber qué correos salen y por qué fallan.</p>
        <?php elseif ( 'errors' === $settings['log_mode'] ) : ?>
            <p>Solo se registran los envíos que fallan.</p>
        <?php endif; ?>
        <div class="nolantis-stats">
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $day['sent'] ) ); ?></strong>Enviados (24 h)</div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $day['failed'] ) ); ?></strong>Fallidos (24 h)</div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $week['sent'] ) ); ?></strong>Enviados (7 días)</div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $week['failed'] ) ); ?></strong>Fallidos (7 días)</div>
        </div>
        <p>
            <a href="<?php echo esc_url( nolantis_smtp_admin_url( 'registro' ) ); ?>"<?php echo '' === $status ? ' class="current" aria-current="page"' : ''; ?>>Todos</a> |
            <a href="<?php echo esc_url( nolantis_smtp_admin_url( 'registro', array( 'status' => 'failed' ) ) ); ?>"<?php echo 'failed' === $status ? ' class="current" aria-current="page"' : ''; ?>>Fallidos</a> |
            <a href="<?php echo esc_url( nolantis_smtp_admin_url( 'registro', array( 'status' => 'sent' ) ) ); ?>"<?php echo 'sent' === $status ? ' class="current" aria-current="page"' : ''; ?>>Enviados</a>
        </p>
        <?php if ( empty( $entries ) ) : ?>
            <p>No hay envíos registrados.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:140px;">Fecha</th>
                        <th style="width:90px;">Estado</th>
                        <th style="width:70px;">Método</th>
                        <th>Destinatario</th>
                        <th>Asunto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( wp_date( 'd/m/Y H:i:s', (int) $entry->created_at ) ); ?></td>
                            <td><span class="nolantis-badge nolantis-badge-<?php echo esc_attr( $entry->status ); ?>"><?php echo esc_html( isset( $labels[ $entry->status ] ) ? $labels[ $entry->status ] : $entry->status ); ?></span></td>
                            <td><?php echo esc_html( 'smtp' === $entry->mailer ? 'SMTP' : 'mail()' ); ?></td>
                            <td><?php echo esc_html( $entry->recipients ); ?></td>
                            <td>
                                <?php echo esc_html( $entry->subject ); ?>
                                <?php if ( 'failed' === $entry->status ) : ?>
                                    <?php $hint = nolantis_smtp_explain_error( (string) $entry->error, (string) $entry->debug ); ?>
                                    <details style="margin-top:6px;">
                                        <summary><strong>¿Por qué ha fallado?</strong></summary>
                                        <?php if ( $hint ) : ?>
                                            <p><strong>Causa probable:</strong> <?php echo esc_html( $hint ); ?></p>
                                        <?php endif; ?>
                                        <p><strong>Error:</strong> <code><?php echo esc_html( $entry->error ); ?></code></p>
                                        <?php if ( '' !== (string) $entry->debug ) : ?>
                                            <pre class="nolantis-debug"><?php echo esc_html( $entry->debug ); ?></pre>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Se muestran los 100 envíos más recientes. Se borran automáticamente a los <?php echo esc_html( (int) $settings['log_retention_days'] ); ?> días.</p>
        <?php endif; ?>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('¿Vaciar el registro de envíos?');">
            <?php wp_nonce_field( 'nolantis_clear_mail_log' ); ?>
            <input type="hidden" name="action" value="nolantis_clear_mail_log" />
            <?php submit_button( 'Vaciar registro', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}
