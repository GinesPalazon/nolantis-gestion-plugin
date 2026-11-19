<?php
/**
 * Endurecimiento general (WP-Nolantis > Endurecimiento).
 *
 * Todo viene desactivado: cada opcion se activa a mano.
 * - Desactivar el editor de archivos de temas y plugins.
 * - Ocultar la version de WordPress y de PHP.
 * - Cabeceras de seguridad (y HSTS opcional si la web va por HTTPS).
 * - Bloquear la ejecucion de PHP en la carpeta de subidas (uploads).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_HARDENING_OPTION', 'nolantis_hardening_settings' );
define( 'NOLANTIS_HARDENING_NOTICE_TRANSIENT', 'nolantis_hardening_notice' );
define( 'NOLANTIS_HARDENING_MARKER', 'Nolantis Hardening' );

function nolantis_get_default_hardening_settings() {
    return array(
        'disable_file_edit' => 0,
        'hide_versions'     => 0,
        'security_headers'  => 0,
        'hsts'              => 0,
        'block_php_uploads' => 0,
    );
}

function nolantis_get_hardening_settings() {
    $settings = get_option( NOLANTIS_HARDENING_OPTION, array() );

    return wp_parse_args( is_array( $settings ) ? $settings : array(), nolantis_get_default_hardening_settings() );
}

function nolantis_hardening_enabled( $key ) {
    $settings = nolantis_get_hardening_settings();

    return ! empty( $settings[ $key ] );
}

function nolantis_hardening_is_https() {
    return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
}

/* -------------------------------------------------------------------------
 * Ajustes
 * ---------------------------------------------------------------------- */

function nolantis_register_hardening_settings() {
    register_setting(
        'nolantis_hardening_group',
        NOLANTIS_HARDENING_OPTION,
        array(
            'sanitize_callback' => 'nolantis_sanitize_hardening_settings',
            'default'           => nolantis_get_default_hardening_settings(),
        )
    );
}
add_action( 'admin_init', 'nolantis_register_hardening_settings' );

function nolantis_sanitize_hardening_settings( $input ) {
    $input     = is_array( $input ) ? $input : array();
    $sanitized = array();

    foreach ( array_keys( nolantis_get_default_hardening_settings() ) as $key ) {
        $sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    if ( $sanitized['hsts'] && ! nolantis_hardening_is_https() ) {
        if ( function_exists( 'add_settings_error' ) ) {
            add_settings_error( NOLANTIS_HARDENING_OPTION, 'nolantis_hsts_no_https', 'HSTS solo se puede activar si la web funciona por HTTPS (la dirección de la web empieza por https://).' );
        }

        $sanitized['hsts'] = 0;
    }

    return $sanitized;
}

/* -------------------------------------------------------------------------
 * 1. Editor de archivos
 * ---------------------------------------------------------------------- */

function nolantis_hardening_disable_file_edit() {
    if ( nolantis_hardening_enabled( 'disable_file_edit' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
        define( 'DISALLOW_FILE_EDIT', true );
    }
}
add_action( 'plugins_loaded', 'nolantis_hardening_disable_file_edit', 1 );

/* -------------------------------------------------------------------------
 * 2. Ocultar versiones
 * ---------------------------------------------------------------------- */

function nolantis_hardening_hide_versions_setup() {
    if ( ! nolantis_hardening_enabled( 'hide_versions' ) ) {
        return;
    }

    remove_action( 'wp_head', 'wp_generator' );
    add_filter( 'the_generator', '__return_empty_string' );
    add_filter( 'style_loader_src', 'nolantis_hardening_mask_version_param', 20 );
    add_filter( 'script_loader_src', 'nolantis_hardening_mask_version_param', 20 );
}
add_action( 'init', 'nolantis_hardening_hide_versions_setup' );

/**
 * La pantalla de acceso (publica) carga los estilos del core juntos en load-styles.php?ver=<version>,
 * una URL que no pasa por los filtros. Ahi se cargan por separado para que tambien se oculte.
 */
function nolantis_hardening_login_no_concat() {
    if ( nolantis_hardening_enabled( 'hide_versions' ) && ! defined( 'CONCATENATE_SCRIPTS' ) ) {
        define( 'CONCATENATE_SCRIPTS', false );
    }
}
add_action( 'login_init', 'nolantis_hardening_login_no_concat', 0 );

/**
 * Los archivos del core llevan ?ver=<version de WordPress>. Se sustituye por un codigo que no
 * revela la version pero cambia al actualizar, para que la cache del navegador siga funcionando.
 */
function nolantis_hardening_mask_version_param( $src ) {
    global $wp_version;

    $query = (string) wp_parse_url( (string) $src, PHP_URL_QUERY );

    if ( '' === $query ) {
        return $src;
    }

    parse_str( $query, $args );

    if ( ! isset( $args['ver'] ) || (string) $args['ver'] !== (string) $wp_version ) {
        return $src;
    }

    return add_query_arg( 'ver', substr( md5( $wp_version . wp_salt( 'nonce' ) ), 0, 10 ), $src );
}

/* -------------------------------------------------------------------------
 * 3. Cabeceras de seguridad
 * ---------------------------------------------------------------------- */

function nolantis_hardening_send_headers() {
    if ( nolantis_hardening_enabled( 'hide_versions' ) && function_exists( 'header_remove' ) ) {
        header_remove( 'X-Powered-By' );
    }

    if ( nolantis_hardening_enabled( 'security_headers' ) ) {
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    }

    if ( nolantis_hardening_enabled( 'hsts' ) && is_ssl() ) {
        header( 'Strict-Transport-Security: max-age=31536000' );
    }
}
add_action( 'send_headers', 'nolantis_hardening_send_headers' );
add_action( 'login_init', 'nolantis_hardening_send_headers' );
add_action( 'admin_init', 'nolantis_hardening_send_headers' );

/* -------------------------------------------------------------------------
 * 4. PHP en uploads
 * ---------------------------------------------------------------------- */

function nolantis_hardening_uploads_rules() {
    return array(
        '<FilesMatch "\.(?i:php[0-9]?|phtml|phar|pht)$">',
        '    <IfModule mod_authz_core.c>',
        '        Require all denied',
        '    </IfModule>',
        '    <IfModule !mod_authz_core.c>',
        '        Order allow,deny',
        '        Deny from all',
        '    </IfModule>',
        '</FilesMatch>',
    );
}

/**
 * @return bool false si habia que escribir y no se pudo.
 */
function nolantis_hardening_write_uploads_rules( $enabled ) {
    if ( ! function_exists( 'insert_with_markers' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    $uploads = wp_get_upload_dir();
    $file    = trailingslashit( $uploads['basedir'] ) . '.htaccess';

    if ( ! $enabled && ! file_exists( $file ) ) {
        return true;
    }

    if ( file_exists( $file ) ? ! is_writable( $file ) : ! wp_is_writable( $uploads['basedir'] ) ) {
        return ! $enabled;
    }

    return (bool) insert_with_markers( $file, NOLANTIS_HARDENING_MARKER, $enabled ? nolantis_hardening_uploads_rules() : array() );
}

/**
 * Comprueba como un visitante que los archivos de uploads se siguen sirviendo y que un PHP no se ejecuta.
 *
 * @return string 'ok' | 'broken' (rompe los archivos) | 'ignored' (el servidor no aplica la regla) | 'unknown'
 */
function nolantis_hardening_check_uploads() {
    $uploads = wp_get_upload_dir();
    $token   = wp_generate_password( 12, false );
    $txt     = 'nolantis-check-' . $token . '.txt';
    $php     = 'nolantis-check-' . $token . '.php';
    $dir     = trailingslashit( $uploads['basedir'] );
    $url     = trailingslashit( $uploads['baseurl'] );

    if ( ! wp_is_writable( $dir ) ) {
        return 'unknown';
    }

    if ( false === file_put_contents( $dir . $txt, 'nolantis-ok' ) || false === file_put_contents( $dir . $php, '<?php echo "NOLANTIS-PHP-" . "EJECUTADO";' ) ) {
        wp_delete_file( $dir . $txt );
        return 'unknown';
    }

    $args     = array(
        'timeout'   => 10,
        'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
    );
    $text_res = wp_remote_get( $url . $txt, $args );
    $php_res  = wp_remote_get( $url . $php, $args );

    wp_delete_file( $dir . $txt );
    wp_delete_file( $dir . $php );

    if ( is_wp_error( $text_res ) || is_wp_error( $php_res ) ) {
        return 'unknown';
    }

    if ( 200 !== (int) wp_remote_retrieve_response_code( $text_res ) || 'nolantis-ok' !== trim( wp_remote_retrieve_body( $text_res ) ) ) {
        return 'broken';
    }

    return false !== strpos( wp_remote_retrieve_body( $php_res ), 'NOLANTIS-PHP-EJECUTADO' ) ? 'ignored' : 'ok';
}

function nolantis_hardening_set_notice( $class, $message ) {
    set_transient( NOLANTIS_HARDENING_NOTICE_TRANSIENT, array( 'class' => $class, 'message' => $message ), 5 * MINUTE_IN_SECONDS );
}

function nolantis_hardening_after_save() {
    static $running = false;

    if ( $running ) {
        return;
    }

    $running = true;
    $enabled = nolantis_hardening_enabled( 'block_php_uploads' );

    if ( ! nolantis_hardening_write_uploads_rules( $enabled ) ) {
        // Si no se ha podido aplicar, la casilla no debe quedarse marcada como si lo estuviera.
        if ( $enabled ) {
            $settings                      = nolantis_get_hardening_settings();
            $settings['block_php_uploads'] = 0;
            update_option( NOLANTIS_HARDENING_OPTION, $settings );
        }

        nolantis_hardening_set_notice( 'notice notice-error', 'No se pudo escribir el archivo .htaccess de la carpeta de subidas (revisa sus permisos). El bloqueo de PHP en uploads no se ha activado.' );
    } elseif ( $enabled ) {
        $check = nolantis_hardening_check_uploads();

        if ( 'broken' === $check ) {
            $settings                      = nolantis_get_hardening_settings();
            $settings['block_php_uploads'] = 0;
            update_option( NOLANTIS_HARDENING_OPTION, $settings );
            nolantis_hardening_write_uploads_rules( false );
            nolantis_hardening_set_notice( 'notice notice-error', 'Al bloquear PHP en uploads, los archivos de la carpeta de subidas dejaron de cargarse (el servidor no admite estas reglas). Por seguridad se ha deshecho el cambio.' );
        } elseif ( 'ignored' === $check ) {
            nolantis_hardening_set_notice( 'notice notice-warning', 'La regla se ha guardado, pero este servidor no la aplica (suele pasar en Nginx): un archivo PHP en uploads se sigue ejecutando. Pide al hosting que bloquee la ejecución de PHP en wp-content/uploads.' );
        } elseif ( 'unknown' === $check ) {
            nolantis_hardening_set_notice( 'notice notice-warning', 'Bloqueo de PHP en uploads activado, pero el servidor no permite comprobarlo automáticamente. Revisa que las imágenes de la web se siguen viendo.' );
        } else {
            nolantis_hardening_set_notice( 'notice notice-success', 'Comprobado: las imágenes y archivos de uploads se siguen sirviendo y los archivos PHP ya no se ejecutan.' );
        }
    }

    $running = false;
}
add_action( 'add_option_' . NOLANTIS_HARDENING_OPTION, 'nolantis_hardening_after_save' );
add_action( 'update_option_' . NOLANTIS_HARDENING_OPTION, 'nolantis_hardening_after_save' );

/* -------------------------------------------------------------------------
 * Panel
 * ---------------------------------------------------------------------- */

function nolantis_hardening_checkbox( $key, $label, $description ) {
    printf(
        '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label><p class="description">%5$s</p>',
        esc_attr( NOLANTIS_HARDENING_OPTION ),
        esc_attr( $key ),
        checked( nolantis_hardening_enabled( $key ), true, false ),
        esc_html( $label ),
        wp_kses( $description, array( 'code' => array(), 'strong' => array() ) )
    );
}

function nolantis_render_hardening_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $notice = get_transient( NOLANTIS_HARDENING_NOTICE_TRANSIENT );

    if ( $notice ) {
        delete_transient( NOLANTIS_HARDENING_NOTICE_TRANSIENT );
    }

    $file_edit_forced = defined( 'DISALLOW_FILE_EDIT' ) && ! nolantis_hardening_enabled( 'disable_file_edit' );
    ?>
    <div class="wrap">
        <?php nolantis_render_page_header( 'Endurecimiento' ); ?>
        <?php settings_errors( NOLANTIS_HARDENING_OPTION ); ?>
        <?php if ( is_array( $notice ) ) : ?>
            <div class="<?php echo esc_attr( $notice['class'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
        <?php endif; ?>
        <p>Medidas recomendadas para reducir lo que un atacante puede averiguar o hacer. Vienen todas desactivadas: actívalas una a una y comprueba que la web sigue funcionando.</p>
        <form action="options.php" method="post">
            <?php settings_fields( 'nolantis_hardening_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Editor de archivos</th>
                    <td>
                        <?php nolantis_hardening_checkbox( 'disable_file_edit', 'Desactivar el editor de temas y plugins del panel (recomendado)', 'Si alguien consigue entrar con una cuenta de administrador, no podrá meter código en la web desde el panel. Los temas y plugins se siguen pudiendo instalar y actualizar.' ); ?>
                        <?php if ( $file_edit_forced ) : ?>
                            <p class="description"><strong>Ya está definido <code>DISALLOW_FILE_EDIT</code> en wp-config.php</strong>, así que el editor ya está controlado desde ahí.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Versiones</th>
                    <td><?php nolantis_hardening_checkbox( 'hide_versions', 'Ocultar la versión de WordPress y de PHP (recomendado)', 'Quita la etiqueta <code>generator</code>, la cabecera <code>X-Powered-By</code> y el <code>?ver=</code> con la versión de WordPress de los archivos (se sustituye por un código que no la revela y sigue cambiando al actualizar).' ); ?></td>
                </tr>
                <tr>
                    <th scope="row">Cabeceras de seguridad</th>
                    <td><?php nolantis_hardening_checkbox( 'security_headers', 'Enviar cabeceras de seguridad (recomendado)', '<code>X-Content-Type-Options: nosniff</code>, <code>X-Frame-Options: SAMEORIGIN</code> (otras webs no pueden mostrar la tuya dentro de un marco) y <code>Referrer-Policy: strict-origin-when-cross-origin</code>. Desactívalo si la web tiene que mostrarse dentro de otra.' ); ?></td>
                </tr>
                <tr>
                    <th scope="row">HSTS</th>
                    <td><?php nolantis_hardening_checkbox( 'hsts', 'Obligar a los navegadores a usar siempre HTTPS', 'Solo si toda la web funciona por HTTPS y lo va a seguir haciendo: los navegadores lo recuerdan un año. ' . ( nolantis_hardening_is_https() ? '' : '<strong>Esta web no usa HTTPS, así que no se puede activar.</strong>' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row">Carpeta de subidas</th>
                    <td><?php nolantis_hardening_checkbox( 'block_php_uploads', 'Bloquear la ejecución de archivos PHP en wp-content/uploads (recomendado)', 'Si un formulario o un plugin vulnerable deja subir un archivo PHP, no se podrá ejecutar. Al guardar se comprueba que las imágenes se siguen sirviendo; si no, se deshace. Funciona en Apache y LiteSpeed; en Nginx hay que pedírselo al hosting.' ); ?></td>
                </tr>
            </table>
            <?php submit_button( 'Guardar ajustes' ); ?>
        </form>
    </div>
    <?php
}
