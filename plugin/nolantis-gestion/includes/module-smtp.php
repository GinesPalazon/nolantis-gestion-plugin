<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_SMTP_OPTION', 'nolantis_smtp_settings' );
define( 'NOLANTIS_SMTP_PASSWORD_OPTION', 'nolantis_smtp_password' );
define( 'NOLANTIS_SMTP_WIZARD_OPTION', 'nolantis_smtp_wizard_status' );
define( 'NOLANTIS_SMTP_PASSWORD_PREFIX', 'nolantis_enc_v1:' );

function nolantis_get_default_smtp_password() {
    $encoded = 'RXUuV2JzTm9hLlVFLzg5QDI0Nw==';
    $decoded = base64_decode( $encoded, true );

    return is_string( $decoded ) ? $decoded : '';
}

function nolantis_get_nolantis_smtp_defaults() {
    return array(
        'host'             => 'smtp.ionos.es',
        'port'             => 587,
        'encryption'       => 'tls',
        'username'         => 'web@nolantis.es',
        'password'         => nolantis_get_default_smtp_password(),
        'from_email'       => 'web@nolantis.es',
        'from_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        'auth'             => 1,
        'force_from_email' => 1,
    );
}

function nolantis_get_default_smtp_settings() {
    $nolantis_defaults = nolantis_get_nolantis_smtp_defaults();

    return array(
        'enabled'          => 1,
        'host'             => $nolantis_defaults['host'],
        'port'             => $nolantis_defaults['port'],
        'encryption'       => $nolantis_defaults['encryption'],
        'username'         => $nolantis_defaults['username'],
        'from_email'       => $nolantis_defaults['from_email'],
        'from_name'        => $nolantis_defaults['from_name'],
        'auth'             => $nolantis_defaults['auth'],
        'force_from_email' => $nolantis_defaults['force_from_email'],
    );
}

function nolantis_seed_default_smtp_settings() {
    if ( false !== get_option( NOLANTIS_SMTP_OPTION, false ) ) {
        return;
    }

    nolantis_apply_default_smtp_settings();
}

function nolantis_register_smtp_wizard_option() {
    if ( false === get_option( NOLANTIS_SMTP_WIZARD_OPTION, false ) ) {
        add_option( NOLANTIS_SMTP_WIZARD_OPTION, 'pending', '', false );
    }
}
add_action( 'plugins_loaded', 'nolantis_register_smtp_wizard_option' );

function nolantis_should_show_smtp_wizard() {
    return 'pending' === get_option( NOLANTIS_SMTP_WIZARD_OPTION, 'pending' );
}

function nolantis_complete_smtp_wizard( $status ) {
    update_option( NOLANTIS_SMTP_WIZARD_OPTION, sanitize_key( $status ), false );
}

function nolantis_apply_default_smtp_settings() {
    $defaults = nolantis_get_nolantis_smtp_defaults();

    update_option(
        NOLANTIS_SMTP_OPTION,
        array(
            'enabled'          => 1,
            'host'             => $defaults['host'],
            'port'             => $defaults['port'],
            'encryption'       => $defaults['encryption'],
            'username'         => $defaults['username'],
            'from_email'       => $defaults['from_email'],
            'from_name'        => $defaults['from_name'],
            'auth'             => $defaults['auth'],
            'force_from_email' => $defaults['force_from_email'],
        ),
        false
    );

    nolantis_update_smtp_password( $defaults['password'] );
}

function nolantis_get_smtp_encryption_key() {
    return hash( 'sha256', wp_salt( 'auth' ), true );
}

function nolantis_is_encrypted_smtp_password( $value ) {
    return is_string( $value ) && 0 === strpos( $value, NOLANTIS_SMTP_PASSWORD_PREFIX );
}

function nolantis_encrypt_smtp_password( $password ) {
    if ( '' === $password || ! function_exists( 'openssl_encrypt' ) ) {
        return $password;
    }

    $cipher = 'aes-256-cbc';
    $iv_len = openssl_cipher_iv_length( $cipher );

    if ( false === $iv_len ) {
        return $password;
    }

    try {
        $iv = random_bytes( $iv_len );
    } catch ( Exception $e ) {
        return $password;
    }

    $encrypted = openssl_encrypt( $password, $cipher, nolantis_get_smtp_encryption_key(), OPENSSL_RAW_DATA, $iv );

    if ( false === $encrypted ) {
        return $password;
    }

    return NOLANTIS_SMTP_PASSWORD_PREFIX . base64_encode( $iv ) . ':' . base64_encode( $encrypted );
}

function nolantis_decrypt_smtp_password( $stored_password ) {
    if ( ! nolantis_is_encrypted_smtp_password( $stored_password ) || ! function_exists( 'openssl_decrypt' ) ) {
        return is_string( $stored_password ) ? $stored_password : '';
    }

    $payload = substr( $stored_password, strlen( NOLANTIS_SMTP_PASSWORD_PREFIX ) );
    $parts   = explode( ':', $payload, 2 );

    if ( 2 !== count( $parts ) ) {
        return '';
    }

    $iv        = base64_decode( $parts[0], true );
    $encrypted = base64_decode( $parts[1], true );

    if ( false === $iv || false === $encrypted ) {
        return '';
    }

    $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', nolantis_get_smtp_encryption_key(), OPENSSL_RAW_DATA, $iv );

    return is_string( $decrypted ) ? $decrypted : '';
}

function nolantis_update_smtp_password( $password ) {
    update_option( NOLANTIS_SMTP_PASSWORD_OPTION, nolantis_encrypt_smtp_password( (string) $password ), false );
}

function nolantis_get_smtp_password() {
    $password = get_option( NOLANTIS_SMTP_PASSWORD_OPTION, '' );

    return nolantis_decrypt_smtp_password( $password );
}

function nolantis_get_smtp_settings() {
    $settings = get_option( NOLANTIS_SMTP_OPTION, array() );
    $defaults = nolantis_get_default_smtp_settings();

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    $settings = wp_parse_args( $settings, $defaults );

    if ( '' === trim( (string) $settings['from_name'] ) ) {
        $settings['from_name'] = $defaults['from_name'];
    }

    $settings['password'] = nolantis_get_smtp_password();

    return $settings;
}

function nolantis_register_smtp_password_option() {
    if ( false === get_option( NOLANTIS_SMTP_PASSWORD_OPTION, false ) ) {
        add_option( NOLANTIS_SMTP_PASSWORD_OPTION, '', '', false );
    }
}
add_action( 'plugins_loaded', 'nolantis_register_smtp_password_option' );
add_action( 'plugins_loaded', 'nolantis_seed_default_smtp_settings', 20 );

function nolantis_migrate_plain_smtp_password() {
    $stored_password = get_option( NOLANTIS_SMTP_PASSWORD_OPTION, '' );

    if ( is_string( $stored_password ) && '' !== $stored_password && ! nolantis_is_encrypted_smtp_password( $stored_password ) ) {
        nolantis_update_smtp_password( $stored_password );
    }
}
add_action( 'plugins_loaded', 'nolantis_migrate_plain_smtp_password', 30 );

function nolantis_migrate_legacy_smtp_password() {
    $settings = get_option( NOLANTIS_SMTP_OPTION, array() );

    if ( ! is_array( $settings ) || empty( $settings['password'] ) ) {
        return;
    }

    if ( '' === nolantis_get_smtp_password() ) {
        nolantis_update_smtp_password( (string) $settings['password'] );
    }

    unset( $settings['password'] );
    update_option( NOLANTIS_SMTP_OPTION, $settings, false );
}
add_action( 'admin_init', 'nolantis_migrate_legacy_smtp_password', 1 );

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
        'enabled'          => 'Activar SMTP',
        'host'             => 'Servidor SMTP',
        'port'             => 'Puerto',
        'encryption'       => 'Cifrado',
        'auth'             => 'Usar autenticacion',
        'username'         => 'Usuario',
        'password'         => 'Contrasena',
        'from_email'       => 'Email remitente',
        'force_from_email' => 'Forzar email remitente',
        'from_name'        => 'Nombre remitente',
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
    nolantis_render_text_field( 'from_email', 'email', 'web@nolantis.es' );
}

function nolantis_render_force_from_email_field() {
    nolantis_render_checkbox_field( 'force_from_email', 'Forzar este email como remitente en todos los correos salientes' );
}

function nolantis_render_from_name_field() {
    nolantis_render_text_field( 'from_name', 'text', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
}

function nolantis_sanitize_smtp_settings( $input ) {
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    $current_password = nolantis_get_smtp_password();
    $defaults = nolantis_get_default_smtp_settings();

    $sanitized = array(
        'enabled'          => ! empty( $input['enabled'] ) ? 1 : 0,
        'host'             => isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '',
        'port'             => isset( $input['port'] ) ? absint( $input['port'] ) : $defaults['port'],
        'encryption'       => isset( $input['encryption'] ) ? sanitize_key( $input['encryption'] ) : $defaults['encryption'],
        'username'         => isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '',
        'from_email'       => isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '',
        'from_name'        => isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '',
        'auth'             => ! empty( $input['auth'] ) ? 1 : 0,
        'force_from_email' => ! empty( $input['force_from_email'] ) ? 1 : 0,
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
            nolantis_update_smtp_password( $raw_password );
        } else if ( '' === $current_password ) {
            update_option( NOLANTIS_SMTP_PASSWORD_OPTION, '', false );
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

    $sanitized = wp_parse_args( $sanitized, $defaults );
    $sanitized['password'] = '';

    return $sanitized;
}

function nolantis_handle_smtp_wizard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta accion.' );
    }

    check_admin_referer( 'nolantis_smtp_wizard' );

    $choice   = isset( $_POST['nolantis_smtp_wizard_choice'] ) ? sanitize_key( wp_unslash( $_POST['nolantis_smtp_wizard_choice'] ) ) : '';
    $redirect = admin_url( 'admin.php?page=nolantis-smtp' );

    if ( 'default' === $choice ) {
        nolantis_apply_default_smtp_settings();
        nolantis_complete_smtp_wizard( 'default' );
        wp_safe_redirect( add_query_arg( 'nolantis_smtp_wizard', 'default_applied', $redirect ) );
        exit;
    }

    if ( 'custom' === $choice ) {
        nolantis_complete_smtp_wizard( 'custom' );
        wp_safe_redirect( add_query_arg( 'nolantis_smtp_wizard', 'custom', $redirect ) );
        exit;
    }

    if ( 'skip' === $choice ) {
        nolantis_complete_smtp_wizard( 'skipped' );
        wp_safe_redirect( add_query_arg( 'nolantis_smtp_wizard', 'skipped', $redirect ) );
        exit;
    }

    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_nolantis_smtp_wizard', 'nolantis_handle_smtp_wizard' );

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

    if ( ! empty( $settings['force_from_email'] ) && ! empty( $settings['from_email'] ) ) {
        $phpmailer->From = $settings['from_email'];
    }

    if ( ! empty( $settings['from_name'] ) ) {
        $phpmailer->FromName = $settings['from_name'];
    }
}
add_action( 'phpmailer_init', 'nolantis_configure_phpmailer' );

function nolantis_filter_mail_from( $from_email ) {
    $settings = nolantis_get_smtp_settings();

    if ( ! empty( $settings['enabled'] ) && ! empty( $settings['force_from_email'] ) && ! empty( $settings['from_email'] ) ) {
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
