<?php
/**
 * Integridad de los archivos de WordPress (WP-Nolantis > Seguridad de acceso > Integridad).
 *
 * - Compara cada archivo del nucleo con las sumas MD5 oficiales de WordPress.org para la version
 *   e idioma instalados: detecta archivos modificados y archivos que faltan.
 * - Busca archivos PHP desconocidos en la raiz, wp-admin y wp-includes (donde suele esconderse el malware).
 * - Busca archivos PHP en wp-content/uploads, donde nunca deberia haberlos.
 *
 * Se ejecuta una vez al dia (wp-cron) y a mano desde el panel. Solo lee archivos: nunca los cambia.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_INTEGRITY_OPTION', 'nolantis_integrity_results' );
define( 'NOLANTIS_INTEGRITY_HOOK', 'nolantis_integrity_daily_scan' );
define( 'NOLANTIS_INTEGRITY_MAX_LIST', 200 );
define( 'NOLANTIS_INTEGRITY_MAX_UPLOAD_FILES', 100000 );

function nolantis_integrity_maybe_schedule() {
    if ( ! wp_next_scheduled( NOLANTIS_INTEGRITY_HOOK ) ) {
        wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', NOLANTIS_INTEGRITY_HOOK );
    }
}
add_action( 'plugins_loaded', 'nolantis_integrity_maybe_schedule', 5 );

function nolantis_integrity_unschedule() {
    wp_clear_scheduled_hook( NOLANTIS_INTEGRITY_HOOK );
}

function nolantis_integrity_is_php_file( $path ) {
    return (bool) preg_match( '/\.(php[0-9]?|phtml|phar|pht)$/i', $path );
}

/**
 * "index.php" vacios o con "Silence is golden" que muchos plugins dejan en uploads para que no se
 * listen las carpetas: no son peligrosos.
 */
function nolantis_integrity_is_silence_file( $path ) {
    if ( 'index.php' !== strtolower( basename( $path ) ) || filesize( $path ) > 200 ) {
        return false;
    }

    return (bool) preg_match( '#^\s*<\?php\s*(//\s*silence is golden\.?\s*)?(\?>)?\s*$#i', (string) file_get_contents( $path ) );
}

/**
 * @return array|false Sumas MD5 oficiales (ruta relativa => md5) o false si no se pueden descargar.
 */
function nolantis_integrity_get_checksums( &$locale ) {
    global $wp_version, $wp_local_package;

    if ( ! function_exists( 'get_core_checksums' ) ) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    // Igual que el actualizador de WordPress: paquete del idioma con el que se instalo.
    $locale    = ! empty( $wp_local_package ) ? $wp_local_package : 'en_US';
    $checksums = get_core_checksums( $wp_version, $locale );

    if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
        $locale    = 'en_US';
        $checksums = get_core_checksums( $wp_version, $locale );
    }

    return is_array( $checksums ) ? $checksums : false;
}

/**
 * Lista los archivos PHP de una carpeta (recursivamente) con su ruta relativa a ABSPATH.
 *
 * @return string[]
 */
function nolantis_integrity_list_php_files( $dir, $recursive = true, $max = 50000 ) {
    $files = array();

    if ( ! is_dir( $dir ) ) {
        return $files;
    }

    $iterator = $recursive
        ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) )
        : new FilesystemIterator( $dir, FilesystemIterator::SKIP_DOTS );
    $visited  = 0;

    foreach ( $iterator as $file ) {
        if ( ++$visited > $max ) {
            break;
        }

        if ( $file->isFile() && nolantis_integrity_is_php_file( $file->getFilename() ) ) {
            $files[] = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( ABSPATH ) ) ), '/' );
        }
    }

    sort( $files );

    return $files;
}

/**
 * Hace la comprobacion completa y guarda el resultado.
 *
 * @return array
 */
function nolantis_integrity_scan() {
    global $wp_version;

    $locale    = '';
    $checksums = nolantis_integrity_get_checksums( $locale );
    $result    = array(
        'scanned_at'  => time(),
        'version'     => $wp_version,
        'locale'      => $locale,
        'error'       => '',
        'checked'     => 0,
        'modified'    => array(),
        'missing'     => array(),
        'unknown'     => array(),
        'uploads_php' => array(),
    );

    if ( false === $checksums ) {
        $result['error'] = 'No se ha podido descargar la lista oficial de archivos de WordPress.org para la versión ' . $wp_version . '. Se volverá a intentar en la próxima comprobación.';
    } else {
        foreach ( $checksums as $file => $md5 ) {
            // Los temas y plugins por defecto de wp-content se actualizan o borran por separado.
            if ( 0 === strpos( $file, 'wp-content/' ) ) {
                continue;
            }

            $result['checked']++;
            $path = ABSPATH . $file;

            if ( ! file_exists( $path ) ) {
                $result['missing'][] = $file;
            } elseif ( md5_file( $path ) !== $md5 ) {
                $result['modified'][] = $file;
            }
        }

        $known = array( 'wp-config.php' => true );

        foreach ( array( array( ABSPATH, false ), array( ABSPATH . 'wp-admin', true ), array( ABSPATH . WPINC, true ) ) as $location ) {
            foreach ( nolantis_integrity_list_php_files( $location[0], $location[1] ) as $file ) {
                if ( ! isset( $checksums[ $file ] ) && ! isset( $known[ $file ] ) ) {
                    $result['unknown'][] = $file;
                }
            }
        }
    }

    $uploads = wp_get_upload_dir();

    foreach ( nolantis_integrity_list_php_files( $uploads['basedir'], true, NOLANTIS_INTEGRITY_MAX_UPLOAD_FILES ) as $file ) {
        if ( ! nolantis_integrity_is_silence_file( ABSPATH . $file ) ) {
            $result['uploads_php'][] = $file;
        }
    }

    foreach ( array( 'modified', 'missing', 'unknown', 'uploads_php' ) as $key ) {
        $result[ $key . '_total' ] = count( $result[ $key ] );
        $result[ $key ]            = array_slice( $result[ $key ], 0, NOLANTIS_INTEGRITY_MAX_LIST );
    }

    update_option( NOLANTIS_INTEGRITY_OPTION, $result, false );

    do_action( 'nolantis_integrity_scanned', $result );

    return $result;
}
add_action( NOLANTIS_INTEGRITY_HOOK, 'nolantis_integrity_scan' );

/**
 * @return array|false Ultimo resultado guardado.
 */
function nolantis_integrity_get_results() {
    $result = get_option( NOLANTIS_INTEGRITY_OPTION );

    return is_array( $result ) ? $result : false;
}

/**
 * Problemas que importan: archivos modificados, PHP desconocidos y PHP en uploads.
 * (Que falte un archivo como readme.html no es un riesgo: muchos hostings los borran.)
 */
function nolantis_integrity_count_issues( $result ) {
    if ( ! is_array( $result ) ) {
        return 0;
    }

    return (int) $result['modified_total'] + (int) $result['unknown_total'] + (int) $result['uploads_php_total'];
}

/* -------------------------------------------------------------------------
 * Aviso por email (desactivado por defecto)
 * ---------------------------------------------------------------------- */

function nolantis_integrity_alert( $result ) {
    if ( ! function_exists( 'nolantis_ll_send_alert' ) || empty( nolantis_ll_get_setting( 'alert_integrity' ) ) ) {
        return;
    }

    $issues = nolantis_integrity_count_issues( $result );

    if ( $issues < 1 ) {
        return;
    }

    $lines = array();

    foreach ( array( 'modified' => 'Archivos de WordPress modificados', 'unknown' => 'Archivos PHP desconocidos junto a WordPress', 'uploads_php' => 'Archivos PHP en la carpeta de subidas' ) as $key => $label ) {
        if ( $result[ $key . '_total' ] > 0 ) {
            $lines[] = sprintf( "%s (%d):\n- %s", $label, $result[ $key . '_total' ], implode( "\n- ", array_slice( $result[ $key ], 0, 20 ) ) );
        }
    }

    $body = sprintf( "La comprobación de archivos ha encontrado %d posibles problemas en %s:\n\n%s\n\nPuede ser señal de que la web ha sido hackeada. Revísalo en el panel cuanto antes.", $issues, home_url( '/' ), implode( "\n\n", $lines ) );

    // Un aviso por cada conjunto distinto de problemas (no se repite cada dia si no cambia nada).
    nolantis_ll_send_alert( 'integrity', md5( wp_json_encode( array( $result['modified'], $result['unknown'], $result['uploads_php'] ) ) ), 'Posibles archivos sospechosos en la web', $body );
}
add_action( 'nolantis_integrity_scanned', 'nolantis_integrity_alert' );

/* -------------------------------------------------------------------------
 * Panel
 * ---------------------------------------------------------------------- */

function nolantis_handle_integrity_scan() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }

    check_admin_referer( 'nolantis_integrity_scan' );
    nolantis_integrity_scan();

    wp_safe_redirect( add_query_arg( array( 'page' => 'nolantis-limit-login', 'tab' => 'integridad', 'scanned' => 1 ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_nolantis_integrity_scan', 'nolantis_handle_integrity_scan' );

function nolantis_integrity_render_list( $result, $key, $title, $help ) {
    if ( empty( $result[ $key . '_total' ] ) ) {
        return;
    }
    ?>
    <h3><?php echo esc_html( sprintf( '%1$s (%2$d)', $title, $result[ $key . '_total' ] ) ); ?></h3>
    <p class="description"><?php echo esc_html( $help ); ?></p>
    <ul style="list-style:disc;margin-left:20px;">
        <?php foreach ( $result[ $key ] as $file ) : ?>
            <li><code><?php echo esc_html( $file ); ?></code></li>
        <?php endforeach; ?>
    </ul>
    <?php if ( $result[ $key . '_total' ] > count( $result[ $key ] ) ) : ?>
        <p class="description"><?php echo esc_html( sprintf( 'Se muestran los primeros %d.', count( $result[ $key ] ) ) ); ?></p>
    <?php endif; ?>
    <?php
}

function nolantis_integrity_render_tab() {
    $result = nolantis_integrity_get_results();
    $issues = nolantis_integrity_count_issues( $result );
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>Integridad de los archivos de WordPress</h2>
        <p>Compara los archivos de WordPress con los originales publicados por WordPress.org y busca archivos PHP donde no debería haberlos. Se comprueba automáticamente una vez al día; solo lee los archivos, nunca los modifica.</p>

        <?php if ( ! empty( $_GET['scanned'] ) ) : ?>
            <div class="notice notice-success inline"><p>Comprobación terminada.</p></div>
        <?php endif; ?>

        <?php if ( ! $result ) : ?>
            <p><strong>Todavía no se ha hecho ninguna comprobación.</strong></p>
        <?php else : ?>
            <p><strong>Última comprobación:</strong> <?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $result['scanned_at'] ) ); ?> · WordPress <?php echo esc_html( $result['version'] ); ?> (<?php echo esc_html( $result['locale'] ); ?>) · <?php echo esc_html( number_format_i18n( (int) $result['checked'] ) ); ?> archivos comprobados</p>

            <?php if ( '' !== $result['error'] ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $result['error'] ); ?></p></div>
            <?php elseif ( 0 === $issues ) : ?>
                <p><span class="nolantis-badge nolantis-badge-ok">Todo correcto</span> Los archivos de WordPress coinciden con los originales y no hay archivos PHP sospechosos.</p>
            <?php else : ?>
                <div class="notice notice-error inline"><p><strong><?php echo esc_html( sprintf( 1 === $issues ? 'Hay %d posible problema.' : 'Hay %d posibles problemas.', $issues ) ); ?></strong> Puede ser señal de que la web ha sido hackeada. Si no reconoces estos archivos, revísalos cuanto antes.</p></div>
            <?php endif; ?>

            <?php
            nolantis_integrity_render_list( $result, 'modified', 'Archivos de WordPress modificados', 'Su contenido no coincide con el original. Para restaurarlos, ve a Escritorio > Actualizaciones y pulsa "Reinstalar la versión".' );
            nolantis_integrity_render_list( $result, 'unknown', 'Archivos PHP desconocidos junto a WordPress', 'No forman parte de WordPress. Algunos hostings añaden los suyos, pero un PHP desconocido en wp-admin o wp-includes es una señal típica de malware.' );
            nolantis_integrity_render_list( $result, 'uploads_php', 'Archivos PHP en la carpeta de subidas', 'En wp-content/uploads solo debería haber imágenes y documentos. Un archivo PHP ahí suele ser malware subido aprovechando un fallo. Activa "Bloquear PHP en uploads" en Endurecimiento.' );
            nolantis_integrity_render_list( $result, 'missing', 'Archivos de WordPress que faltan', 'Normalmente no es un riesgo: muchos hostings borran archivos como readme.html o license.txt. Reinstalar WordPress los repone.' );
            ?>
        <?php endif; ?>

        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:16px;">
            <?php wp_nonce_field( 'nolantis_integrity_scan' ); ?>
            <input type="hidden" name="action" value="nolantis_integrity_scan" />
            <?php submit_button( 'Comprobar ahora', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}
