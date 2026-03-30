<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_SMTP_OPTION', 'nolantis_smtp_settings' );

function nolantis_get_default_smtp_settings() {
    return array(
        'enabled'     => 0,
        'host'        => '',
        'port'        => 587,
        'encryption'  => 'tls',
        'username'    => '',
        'password'    => '',
        'from_email'  => '',
        'from_name'   => '',
        'auth'        => 1,
    );
}

function nolantis_get_smtp_settings() {
    $settings = get_option( NOLANTIS_SMTP_OPTION, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    return wp_parse_args( $settings, nolantis_get_default_smtp_settings() );
}

function nolantis_register_smtp_settings() {
    register_setting(
        'nolantis_settings_group',
        NOLANTIS_SMTP_OPTION,
        array(
            'sanitize_callback' => 'nolantis_sanitize_smtp_settings',
            'default'           => nolantis_get_default_smtp_settings(),
        )
    );

    add_settings_section(
        'nolantis_smtp_section',
        'Configuracion SMTP',
        'nolantis_render_smtp_section',
        'nolantis-smtp'
    );

    $fields = array(
        'enabled'    => 'Activar SMTP',
        'host'       => 'Servidor SMTP',
        'port'       => 'Puerto',
        'encryption' => 'Cifrado',
        'auth'       => 'Usar autenticacion',
        'username'   => 'Usuario',
        'password'   => 'Contrasena',
        'from_email' => 'Email remitente',
        'from_name'  => 'Nombre remitente',
    );

    foreach ( $fields as $field => $label ) {
        add_settings_field(
            'nolantis_' . $field,
            $label,
            'nolantis_render_' . $field . '_field',
            'nolantis-smtp',
            'nolantis_smtp_section'
        );
    }
}
add_action( 'admin_init', 'nolantis_register_smtp_settings' );

function nolantis_render_smtp_section() {
    echo '<p>Configura aqui el envio de correos mediante SMTP para que WordPress no use la funcion mail() de PHP.</p>';
}

function nolantis_render_checkbox_field( $key, $description = '' ) {
    $settings = nolantis_get_smtp_settings();
    $checked  = ! empty( $settings[ $key ] ) ? 'checked' : '';

    printf(
        '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
        esc_attr( NOLANTIS_SMTP_OPTION ),
        esc_attr( $key ),
        esc_attr( $checked ),
        esc_html( $description )
    );
}

function nolantis_render_text_field( $key, $type = 'text', $placeholder = '' ) {
    $settings = nolantis_get_smtp_settings();
    $value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

    printf(
        '<input type="%1$s" class="regular-text" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" autocomplete="off" />',
        esc_attr( $type ),
        esc_attr( NOLANTIS_SMTP_OPTION ),
        esc_attr( $key ),
        esc_attr( $value ),
        esc_attr( $placeholder )
    );
}

function nolantis_render_enabled_field() {
    nolantis_render_checkbox_field( 'enabled', 'Usar SMTP para los emails salientes de WordPress' );
}

function nolantis_render_host_field() {
    nolantis_render_text_field( 'host', 'text', 'smtp.tudominio.com' );
}

function nolantis_render_port_field() {
    nolantis_render_text_field( 'port', 'number', '587' );
}

function nolantis_render_encryption_field() {
    $settings   = nolantis_get_smtp_settings();
    $encryption = isset( $settings['encryption'] ) ? $settings['encryption'] : 'tls';
    ?>
    <select name="<?php echo esc_attr( NOLANTIS_SMTP_OPTION ); ?>[encryption]">
        <option value="none" <?php selected( $encryption, 'none' ); ?>>Sin cifrado</option>
        <option value="ssl" <?php selected( $encryption, 'ssl' ); ?>>SSL</option>
        <option value="tls" <?php selected( $encryption, 'tls' ); ?>>TLS</option>
    </select>
    <?php
}

function nolantis_render_auth_field() {
    nolantis_render_checkbox_field( 'auth', 'El servidor SMTP requiere usuario y contrasena' );
}

function nolantis_render_username_field() {
    nolantis_render_text_field( 'username', 'text', 'usuario@tudominio.com' );
}

function nolantis_render_password_field() {
    $settings     = nolantis_get_smtp_settings();
    $has_password = ! empty( $settings['password'] );
    $placeholder  = $has_password ? 'Deja en blanco para mantener la actual' : '';

    printf(
        '<input type="password" class="regular-text" name="%1$s[password]" value="" placeholder="%2$s" autocomplete="new-password" />',
        esc_attr( NOLANTIS_SMTP_OPTION ),
        esc_attr( $placeholder )
    );

    if ( $has_password ) {
        echo '<p class="description">La contrasena ya esta guardada. Solo escribe una nueva si quieres reemplazarla.</p>';
    }
}

function nolantis_render_from_email_field() {
    nolantis_render_text_field( 'from_email', 'email', 'no-reply@tudominio.com' );
}

function nolantis_render_from_name_field() {
    nolantis_render_text_field( 'from_name', 'text', 'Nolantis' );
}

function nolantis_sanitize_smtp_settings( $input ) {
    $current  = nolantis_get_smtp_settings();
    $defaults = nolantis_get_default_smtp_settings();

    $sanitized = array(
        'enabled'     => ! empty( $input['enabled'] ) ? 1 : 0,
        'host'        => isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '',
        'port'        => isset( $input['port'] ) ? absint( $input['port'] ) : $defaults['port'],
        'encryption'  => isset( $input['encryption'] ) ? sanitize_key( $input['encryption'] ) : $defaults['encryption'],
        'username'    => isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '',
        'password'    => $current['password'],
        'from_email'  => isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '',
        'from_name'   => isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '',
        'auth'        => ! empty( $input['auth'] ) ? 1 : 0,
    );

    if ( ! in_array( $sanitized['encryption'], array( 'none', 'ssl', 'tls' ), true ) ) {
        $sanitized['encryption'] = $defaults['encryption'];
    }

    if ( empty( $sanitized['port'] ) ) {
        $sanitized['port'] = $defaults['port'];
    }

    if ( isset( $input['password'] ) ) {
        $raw_password = trim( (string) wp_unslash( $input['password'] ) );

        if ( '' !== $raw_password ) {
            $sanitized['password'] = $raw_password;
        }
    }

    if ( ! empty( $sanitized['from_email'] ) && ! is_email( $sanitized['from_email'] ) ) {
        add_settings_error(
            NOLANTIS_SMTP_OPTION,
            'nolantis_invalid_from_email',
            'El email remitente no es valido.'
        );
        $sanitized['from_email'] = '';
    }

    if ( $sanitized['enabled'] && empty( $sanitized['host'] ) ) {
        add_settings_error(
            NOLANTIS_SMTP_OPTION,
            'nolantis_missing_host',
            'Debes indicar el servidor SMTP para activar el envio.'
        );
    }

    return wp_parse_args( $sanitized, $defaults );
}

function nolantis_handle_test_email() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta accion.' );
    }

    check_admin_referer( 'nolantis_send_test_email' );

    $settings  = nolantis_get_smtp_settings();
    $recipient = isset( $_POST['test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['test_recipient'] ) ) : '';
    $redirect  = admin_url( 'admin.php?page=nolantis-smtp' );

    if ( ! is_email( $recipient ) ) {
        wp_safe_redirect( add_query_arg( 'nolantis_test_mail', 'invalid_email', $redirect ) );
        exit;
    }

    if ( empty( $settings['enabled'] ) ) {
        wp_safe_redirect( add_query_arg( 'nolantis_test_mail', 'smtp_disabled', $redirect ) );
        exit;
    }

    if ( empty( $settings['host'] ) ) {
        wp_safe_redirect( add_query_arg( 'nolantis_test_mail', 'missing_host', $redirect ) );
        exit;
    }

    $subject = sprintf( 'Prueba SMTP de %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
    $message = sprintf(
        "Hola,\n\nEste es un correo de prueba enviado desde WordPress usando la configuracion SMTP del plugin Nolantis.\n\nSitio: %s\nFecha: %s\nServidor SMTP: %s\n\nSi has recibido este mensaje, la configuracion parece correcta.",
        home_url(),
        wp_date( 'Y-m-d H:i:s' ),
        $settings['host']
    );

    $sent = wp_mail( $recipient, $subject, $message );

    if ( $sent ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'nolantis_test_mail' => 'success',
                    'recipient'          => $recipient,
                ),
                $redirect
            )
        );
        exit;
    }

    wp_safe_redirect( add_query_arg( 'nolantis_test_mail', 'failed', $redirect ) );
    exit;
}
add_action( 'admin_post_nolantis_send_test_email', 'nolantis_handle_test_email' );

function nolantis_configure_phpmailer( $phpmailer ) {
    $settings = nolantis_get_smtp_settings();

    if ( empty( $settings['enabled'] ) || empty( $settings['host'] ) ) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = $settings['host'];
    $phpmailer->Port = (int) $settings['port'];
    $phpmailer->SMTPAuth = ! empty( $settings['auth'] );

    if ( ! empty( $settings['username'] ) ) {
        $phpmailer->Username = $settings['username'];
    }

    if ( ! empty( $settings['password'] ) ) {
        $phpmailer->Password = $settings['password'];
    }

    if ( 'none' === $settings['encryption'] ) {
        $phpmailer->SMTPSecure = '';
    } else {
        $phpmailer->SMTPSecure = $settings['encryption'];
    }

    if ( ! empty( $settings['from_email'] ) ) {
        $phpmailer->From = $settings['from_email'];
    }

    if ( ! empty( $settings['from_name'] ) ) {
        $phpmailer->FromName = $settings['from_name'];
    }
}
add_action( 'phpmailer_init', 'nolantis_configure_phpmailer' );

function nolantis_filter_mail_from( $from_email ) {
    $settings = nolantis_get_smtp_settings();

    if ( ! empty( $settings['enabled'] ) && ! empty( $settings['from_email'] ) ) {
        return $settings['from_email'];
    }

    return $from_email;
}
add_filter( 'wp_mail_from', 'nolantis_filter_mail_from' );

function nolantis_filter_mail_from_name( $from_name ) {
    $settings = nolantis_get_smtp_settings();

    if ( ! empty( $settings['enabled'] ) && ! empty( $settings['from_name'] ) ) {
        return $settings['from_name'];
    }

    return $from_name;
}
add_filter( 'wp_mail_from_name', 'nolantis_filter_mail_from_name' );
