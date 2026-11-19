<?php
/**
 * Limit Login: panel de administracion (WP-Nolantis > Seguridad de acceso).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nolantis_ll_admin_url( $tab = 'estado', $args = array() ) {
    return add_query_arg(
        array_merge(
            array(
                'page' => 'nolantis-limit-login',
                'tab'  => $tab,
            ),
            $args
        ),
        admin_url( 'admin.php' )
    );
}

function nolantis_ll_format_date( $timestamp ) {
    return wp_date( 'd/m/Y H:i:s', (int) $timestamp );
}

function nolantis_ll_get_event_labels() {
    return array(
        'failed'     => 'Fallido',
        'lockout'    => 'Bloqueo IP',
        'userlock'   => 'Cuenta protegida',
        'success'    => 'Correcto',
        'unlock'     => 'Desbloqueo IP',
        'userunlock' => 'Desbloqueo cuenta',
        'reset'      => 'Recuperar contraseña',
    );
}

function nolantis_ll_get_source_labels() {
    return array(
        'login'  => 'Formulario',
        'xmlrpc' => 'XML-RPC',
        'rest'   => 'REST API',
        'admin'  => 'Panel',
    );
}

function nolantis_ll_get_reason_labels() {
    return array(
        'attempts'    => 'Demasiados fallos',
        'bot_user'    => 'Usuario de bot',
        'user_attack' => 'Ataque desde muchas IPs',
    );
}

function nolantis_ll_get_ip_source_labels() {
    return array(
        'auto'        => 'Automático (recomendado): conexión directa o, si la web está detrás de Cloudflare, la IP real del visitante',
        'remote_addr' => 'Solo conexión directa (REMOTE_ADDR), ignorando cualquier cabecera de proxy',
        'proxy'       => 'Proxy de confianza (X-Forwarded-For): para hostings con proxy o balanceador propio',
    );
}

function nolantis_ll_post_form_open( $action, $confirm = '' ) {
    printf(
        '<form class="nolantis-inline-form" action="%1$s" method="post"%2$s>',
        esc_url( admin_url( 'admin-post.php' ) ),
        $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''
    );
    wp_nonce_field( $action );
    printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
}

/* -------------------------------------------------------------------------
 * Acciones (admin-post.php)
 * ---------------------------------------------------------------------- */

function nolantis_ll_check_admin_action( $nonce_action ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para realizar esta acción.' );
    }

    check_admin_referer( $nonce_action );
}

function nolantis_ll_redirect_with_message( $tab, $message ) {
    wp_safe_redirect( nolantis_ll_admin_url( $tab, array( 'nolantis_ll_msg' => $message ) ) );
    exit;
}

function nolantis_ll_handle_unlock() {
    nolantis_ll_check_admin_action( 'nolantis_ll_unlock' );

    $id = isset( $_POST['lockout_id'] ) ? absint( $_POST['lockout_id'] ) : 0;

    nolantis_ll_redirect_with_message( 'estado', $id && nolantis_ll_unlock_by_id( $id ) ? 'unlocked' : 'not_found' );
}
add_action( 'admin_post_nolantis_ll_unlock', 'nolantis_ll_handle_unlock' );

function nolantis_ll_handle_unlock_all() {
    nolantis_ll_check_admin_action( 'nolantis_ll_unlock_all' );
    nolantis_ll_unlock_all();
    nolantis_ll_redirect_with_message( 'estado', 'unlocked_all' );
}
add_action( 'admin_post_nolantis_ll_unlock_all', 'nolantis_ll_handle_unlock_all' );

function nolantis_ll_handle_clear_log() {
    nolantis_ll_check_admin_action( 'nolantis_ll_clear_log' );
    nolantis_ll_clear_log();
    nolantis_ll_redirect_with_message( 'registro', 'log_cleared' );
}
add_action( 'admin_post_nolantis_ll_clear_log', 'nolantis_ll_handle_clear_log' );

function nolantis_ll_handle_whitelist_me() {
    nolantis_ll_check_admin_action( 'nolantis_ll_whitelist_me' );

    $ip = nolantis_get_request_ip();

    if ( ! $ip ) {
        nolantis_ll_redirect_with_message( 'estado', 'invalid_ip' );
    }

    // En IPv6 se anade el bloque /64: los dispositivos cambian de direccion dentro de su /64 con frecuencia.
    $key                     = nolantis_ll_get_ip_key( $ip );
    $settings                = nolantis_ll_get_settings();
    $settings['whitelist'][] = $key;

    update_option( NOLANTIS_LL_OPTION, $settings );
    nolantis_ll_unlock_ip( $key );

    nolantis_ll_redirect_with_message( 'estado', 'whitelisted' );
}
add_action( 'admin_post_nolantis_ll_whitelist_me', 'nolantis_ll_handle_whitelist_me' );

/**
 * Cambia el slug publico (user_nicename) de un usuario para que no coincida con su login.
 */
function nolantis_ll_handle_fix_nicename() {
    nolantis_ll_check_admin_action( 'nolantis_ll_fix_nicename' );

    $user = isset( $_POST['user_id'] ) ? get_userdata( absint( $_POST['user_id'] ) ) : false;

    if ( ! $user || ! nolantis_ll_nicename_reveals_login( $user ) ) {
        nolantis_ll_redirect_with_message( 'estado', 'not_found' );
    }

    // Se usa el nombre visible, salvo que contenga el login (por defecto WordPress usa el login como nombre visible).
    $candidate = sanitize_title( $user->display_name );

    if ( '' === $candidate || nolantis_ll_slug_contains_login( $candidate, $user ) ) {
        $candidate = 'autor-' . strtolower( wp_generate_password( 6, false ) );
    }

    $nicename = $candidate;

    while ( get_user_by( 'slug', $nicename ) ) {
        $nicename = $candidate . '-' . strtolower( wp_generate_password( 4, false ) );
    }

    wp_update_user(
        array(
            'ID'            => $user->ID,
            'user_nicename' => $nicename,
        )
    );

    nolantis_ll_redirect_with_message( 'estado', 'nicename_fixed' );
}
add_action( 'admin_post_nolantis_ll_fix_nicename', 'nolantis_ll_handle_fix_nicename' );

function nolantis_ll_handle_update_cf() {
    nolantis_ll_check_admin_action( 'nolantis_ll_update_cf' );

    nolantis_ll_redirect_with_message( 'estado', nolantis_ll_update_cloudflare_ranges() ? 'cf_updated' : 'cf_failed' );
}
add_action( 'admin_post_nolantis_ll_update_cf', 'nolantis_ll_handle_update_cf' );

function nolantis_ll_handle_test_alert() {
    nolantis_ll_check_admin_action( 'nolantis_ll_test_alert' );

    $sent = wp_mail(
        nolantis_ll_get_alert_recipient(),
        sprintf( '[%s] Aviso de prueba de seguridad', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
        "Este es un aviso de prueba del panel de seguridad de Nolantis.\n\nSi lo has recibido, los avisos por email funcionan correctamente.\n\nSitio: " . home_url( '/' )
    );

    nolantis_ll_redirect_with_message( 'avisos', $sent ? 'alert_sent' : 'alert_failed' );
}
add_action( 'admin_post_nolantis_ll_test_alert', 'nolantis_ll_handle_test_alert' );

/* -------------------------------------------------------------------------
 * Comprobaciones para la revision de seguridad
 * ---------------------------------------------------------------------- */

function nolantis_ll_nicename_matches_login( $nicename, $user ) {
    $nicename = strtolower( (string) $nicename );

    return $nicename === strtolower( $user->user_login ) || $nicename === sanitize_title( $user->user_login );
}

function nolantis_ll_slug_contains_login( $slug, $user ) {
    $slug = strtolower( (string) $slug );

    return false !== strpos( $slug, strtolower( $user->user_login ) ) || false !== strpos( $slug, sanitize_title( $user->user_login ) );
}

/**
 * El slug de /author/slug/ es publico; si coincide con el login, regala la mitad del acceso.
 */
function nolantis_ll_nicename_reveals_login( $user ) {
    return nolantis_ll_nicename_matches_login( $user->user_nicename, $user );
}

/* -------------------------------------------------------------------------
 * Pagina
 * ---------------------------------------------------------------------- */

function nolantis_render_limit_login_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $tabs = array(
        'estado'   => 'Estado',
        'registro' => 'Registro',
        'ajustes'  => 'Ajustes',
        'avisos'     => 'CAPTCHA y avisos',
        'integridad' => 'Integridad',
    );
    $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'estado';
    $tab  = isset( $tabs[ $tab ] ) ? $tab : 'estado';
    ?>
    <div class="wrap nolantis-ll">
        <?php nolantis_render_page_header( 'Seguridad de acceso' ); ?>
        <?php nolantis_ll_render_page_styles(); ?>
        <?php settings_errors(); ?>
        <?php nolantis_ll_render_action_notice(); ?>
        <?php nolantis_ll_render_status_notices(); ?>

        <nav class="nav-tab-wrapper">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a href="<?php echo esc_url( nolantis_ll_admin_url( $slug ) ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php
        switch ( $tab ) {
            case 'registro':
                nolantis_ll_render_log_tab();
                break;
            case 'ajustes':
                nolantis_ll_render_settings_tab();
                break;
            case 'avisos':
                nolantis_ll_render_alerts_tab();
                break;
            case 'integridad':
                nolantis_integrity_render_tab();
                break;
            default:
                nolantis_ll_render_status_tab();
        }
        ?>
    </div>
    <?php
}

function nolantis_ll_render_page_styles() {
    ?>
    <style>
        .nolantis-ll .nolantis-card-wide { max-width: 1100px; }
        .nolantis-ll .nolantis-card h2 { margin-top: 0; }
        .nolantis-ll .nolantis-stats { display: flex; flex-wrap: wrap; gap: 16px; }
        .nolantis-ll .nolantis-stat { flex: 1 1 160px; padding: 14px 16px; border: 1px solid #dcdcde; border-radius: 8px; background: #f6f7f7; }
        .nolantis-ll .nolantis-stat strong { display: block; font-size: 26px; line-height: 1.2; }
        .nolantis-ll .nolantis-stat span { color: #50575e; }
        .nolantis-ll .nolantis-table-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .nolantis-ll table.widefat td, .nolantis-ll table.widefat th { vertical-align: middle; }
        .nolantis-ll .nolantis-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #f0f0f1; white-space: nowrap; }
        .nolantis-ll .nolantis-badge-failed, .nolantis-ll .nolantis-badge-warn { background: #fcf0f1; color: #8a2424; }
        .nolantis-ll .nolantis-badge-lockout, .nolantis-ll .nolantis-badge-userlock { background: #8a2424; color: #fff; }
        .nolantis-ll .nolantis-badge-success, .nolantis-ll .nolantis-badge-ok { background: #edfaef; color: #1a6e2c; }
        .nolantis-ll .nolantis-badge-unlock, .nolantis-ll .nolantis-badge-userunlock, .nolantis-ll .nolantis-badge-info { background: #e5f0fa; color: #0a4b78; }
        .nolantis-ll .nolantis-badge-reset { background: #fcf9e8; color: #6b5300; }
        .nolantis-ll .nolantis-filters { margin: 0 0 12px; }
        .nolantis-ll textarea.code { width: 100%; max-width: 420px; }
        .nolantis-ll .form-table input.small-text { width: 80px; }
        .nolantis-ll .nolantis-inline-form { display: inline; }
        .nolantis-ll .nolantis-check-list { margin: 0; }
        .nolantis-ll .nolantis-check-list li { margin-bottom: 8px; }
    </style>
    <?php
}

function nolantis_ll_render_action_notice() {
    if ( empty( $_GET['nolantis_ll_msg'] ) ) {
        return;
    }

    $messages = array(
        'unlocked'       => array( 'success', 'Bloqueo levantado. Su contador de fallos y el escalado se han reiniciado.' ),
        'unlocked_all'   => array( 'success', 'Se han levantado todos los bloqueos.' ),
        'log_cleared'    => array( 'success', 'Registro vaciado.' ),
        'whitelisted'    => array( 'success', 'Tu IP se ha añadido a la lista blanca.' ),
        'invalid_ip'     => array( 'error', 'No se ha podido detectar tu IP.' ),
        'not_found'      => array( 'error', 'No se ha encontrado el elemento indicado.' ),
        'nicename_fixed' => array( 'success', 'Slug público cambiado. La URL de la página de autor de ese usuario ha cambiado.' ),
        'alert_sent'     => array( 'success', 'Aviso de prueba enviado. Revisa la bandeja de entrada (y la de spam).' ),
        'alert_failed'   => array( 'error', 'No se pudo enviar el aviso de prueba. Revisa la configuración SMTP.' ),
        'cf_updated'     => array( 'success', 'Rangos de Cloudflare actualizados.' ),
        'cf_failed'      => array( 'error', 'No se pudieron actualizar los rangos de Cloudflare; se siguen usando los anteriores. El motivo aparece en "Tu conexión".' ),
    );
    $key      = sanitize_key( wp_unslash( $_GET['nolantis_ll_msg'] ) );

    if ( ! isset( $messages[ $key ] ) ) {
        return;
    }

    printf(
        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr( $messages[ $key ][0] ),
        esc_html( $messages[ $key ][1] )
    );
}

function nolantis_ll_render_status_notices() {
    if ( nolantis_ll_is_emergency_disabled() ) {
        echo '<div class="notice notice-error"><p><strong>Protección desactivada de emergencia</strong> con <code>NOLANTIS_LIMIT_LOGIN_DISABLE</code> en <code>wp-config.php</code>. Quita esa línea cuando hayas recuperado el acceso.</p></div>';
    } elseif ( empty( nolantis_ll_get_setting( 'enabled' ) ) ) {
        echo '<div class="notice notice-warning"><p>La protección contra fuerza bruta está <strong>desactivada</strong>. Actívala en la pestaña Ajustes.</p></div>';
    }

    $hint   = nolantis_ll_detect_proxy_hint();
    $source = nolantis_ll_get_setting( 'ip_source' );
    $ip     = nolantis_get_request_ip();

    if ( 'cloudflare' === $hint && 'remote_addr' === $source ) {
        echo '<div class="notice notice-warning"><p>Esta web está detrás de <strong>Cloudflare</strong>, pero el <em>Origen de la IP</em> ignora sus cabeceras: todos los visitantes comparten la IP de Cloudflare. Cámbialo a "Automático" en Ajustes.</p></div>';
    }

    if ( $ip && ! nolantis_ll_is_public_ip( $ip ) ) {
        if ( 'proxy' === $hint && 'proxy' !== $source ) {
            echo '<div class="notice notice-warning"><p>Tu conexión llega desde una <strong>IP interna</strong> (<code>' . esc_html( $ip ) . '</code>) con la cabecera <code>X-Forwarded-For</code>: la web parece estar detrás de un proxy. Configura el <em>Origen de la IP</em> como "Proxy de confianza" e indica la IP del proxy (la de arriba). Mientras tanto <strong>las IPs internas no se bloquean</strong>, así que el bloqueo por IP no actúa.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>Tu IP detectada es <strong>interna</strong> (<code>' . esc_html( $ip ) . '</code>). Es normal en un entorno local. Si esta web está publicada, revisa con el hosting cómo llega la IP real: las IPs internas nunca se bloquean para no dejar fuera a todos los visitantes.</p></div>';
        }
    }
}

/* -------------------------------------------------------------------------
 * Pestana Estado
 * ---------------------------------------------------------------------- */

function nolantis_ll_render_status_tab() {
    $ip          = nolantis_get_request_ip();
    $remote      = nolantis_ll_get_remote_addr();
    $whitelisted = $ip && nolantis_ll_is_ip_whitelisted( $ip );
    $stats       = nolantis_ll_get_stats_24h();
    $lockouts    = nolantis_ll_get_active_lockouts();
    $settings    = nolantis_ll_get_settings();
    $ip_sources  = nolantis_ll_get_ip_source_labels();
    $reasons     = nolantis_ll_get_reason_labels();
    $ip_locks    = count( wp_list_filter( $lockouts, array( 'kind' => 'ip' ) ) );
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>Tu conexión</h2>
        <p><strong>Tu IP detectada:</strong> <code><?php echo esc_html( $ip ? $ip : 'No disponible' ); ?></code>
            <?php if ( $whitelisted ) : ?>
                <span class="nolantis-badge nolantis-badge-ok">En lista blanca</span>
            <?php endif; ?>
        </p>
        <?php if ( $ip && nolantis_ll_get_ip_key( $ip ) !== $ip ) : ?>
            <p><strong>Bloque IPv6 que se cuenta y bloquea:</strong> <code><?php echo esc_html( nolantis_ll_get_ip_key( $ip ) ); ?></code></p>
        <?php endif; ?>
        <?php if ( $remote && $remote !== $ip ) : ?>
            <p><strong>IP de conexión (proxy):</strong> <code><?php echo esc_html( $remote ); ?></code>
                <?php if ( 'cloudflare' === nolantis_ll_detect_proxy_hint() ) : ?>
                    <span class="nolantis-badge nolantis-badge-info">Cloudflare verificado</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <p><strong>Origen de la IP:</strong> <?php echo esc_html( isset( $ip_sources[ $settings['ip_source'] ] ) ? $ip_sources[ $settings['ip_source'] ] : $settings['ip_source'] ); ?></p>
        <?php $cf = nolantis_ll_get_cloudflare_ranges_status(); ?>
        <p><strong>Rangos de Cloudflare:</strong>
            <?php
            echo esc_html(
                'downloaded' === $cf['source']
                    ? sprintf( '%1$d rangos, descargados de Cloudflare el %2$s (se actualizan solos cada semana).', $cf['count'], wp_date( 'd/m/Y H:i', $cf['updated_at'] ) )
                    : sprintf( '%d rangos incluidos en el plugin (aún no se ha descargado la lista actualizada).', $cf['count'] )
            );
            ?>
            <?php if ( '' !== $cf['last_error'] ) : ?>
                <br /><span class="nolantis-badge nolantis-badge-warn">Último intento fallido</span> <?php echo esc_html( $cf['last_error'] ); ?>
            <?php endif; ?>
        </p>
        <?php nolantis_ll_post_form_open( 'nolantis_ll_update_cf' ); ?>
            <?php submit_button( 'Actualizar rangos de Cloudflare ahora', 'small', 'submit', false ); ?>
        </form>
        <p><strong>Reglas por IP:</strong>
            <?php
            echo esc_html(
                sprintf(
                    '%1$d fallos en %2$d min bloquean %3$d min. Si la IP reincide en las %4$d h siguientes al fin del bloqueo: %5$d h. Si vuelve a reincidir: %6$d días.',
                    $settings['max_attempts'],
                    $settings['window_minutes'],
                    $settings['lockout_1_minutes'],
                    $settings['reincidence_hours'],
                    $settings['lockout_2_hours'],
                    $settings['lockout_3_days']
                )
            );
            ?>
        </p>
        <?php if ( ! empty( $settings['user_protection_enabled'] ) ) : ?>
            <p><strong>Reglas por cuenta:</strong>
                <?php echo esc_html( sprintf( '%1$d fallos contra la misma cuenta en %2$d min (desde cualquier IP) activan su modo protegido durante %3$d min.', $settings['user_max_failures'], $settings['user_window_minutes'], $settings['user_protection_minutes'] ) ); ?>
            </p>
        <?php endif; ?>
        <?php if ( $ip && ! $whitelisted ) : ?>
            <?php nolantis_ll_post_form_open( 'nolantis_ll_whitelist_me' ); ?>
                <?php submit_button( 'Añadir mi IP a la lista blanca', 'secondary', 'submit', false ); ?>
                <p class="description">Útil para la IP fija de tu oficina. No lo hagas desde redes compartidas o con IP dinámica.</p>
            </form>
        <?php endif; ?>
    </div>

    <?php nolantis_ll_render_security_review(); ?>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Últimas 24 horas</h2>
        <div class="nolantis-stats">
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></strong><span>Intentos fallidos</span></div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $stats['lockout'] ) ); ?></strong><span>Bloqueos de IP</span></div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $stats['userlock'] ) ); ?></strong><span>Cuentas protegidas</span></div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $ip_locks ) ); ?></strong><span>IPs bloqueadas ahora</span></div>
            <div class="nolantis-stat"><strong><?php echo esc_html( number_format_i18n( $stats['success'] ) ); ?></strong><span>Accesos correctos</span></div>
        </div>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Bloqueos activos</h2>
        <?php if ( empty( $lockouts ) ) : ?>
            <p>No hay ninguna IP bloqueada ni ninguna cuenta en modo protegido.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>IP / Cuenta</th>
                        <th>Motivo</th>
                        <th>Nivel</th>
                        <th>Usuario probado</th>
                        <th>Desde</th>
                        <th>Hasta</th>
                        <th>Intentos rechazados</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $lockouts as $lockout ) : ?>
                        <?php $is_user = 'user' === $lockout->kind; ?>
                        <tr>
                            <td><span class="nolantis-badge <?php echo $is_user ? 'nolantis-badge-userlock' : 'nolantis-badge-lockout'; ?>"><?php echo esc_html( $is_user ? 'Cuenta' : 'IP' ); ?></span></td>
                            <td><code><?php echo esc_html( $is_user ? $lockout->username : $lockout->ip ); ?></code></td>
                            <td><?php echo esc_html( isset( $reasons[ $lockout->reason ] ) ? $reasons[ $lockout->reason ] : $lockout->reason ); ?></td>
                            <td><?php echo esc_html( $is_user ? '—' : $lockout->level ); ?></td>
                            <td><?php echo esc_html( ! $is_user && '' !== $lockout->username ? $lockout->username : '—' ); ?></td>
                            <td><?php echo esc_html( nolantis_ll_format_date( $lockout->locked_at ) ); ?></td>
                            <td><?php echo esc_html( nolantis_ll_format_date( $lockout->locked_until ) ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( (int) $lockout->blocked_attempts ) ); ?></td>
                            <td>
                                <?php nolantis_ll_post_form_open( 'nolantis_ll_unlock' ); ?>
                                    <input type="hidden" name="lockout_id" value="<?php echo esc_attr( $lockout->id ); ?>" />
                                    <?php submit_button( 'Desbloquear', 'small', 'submit', false ); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Una <strong>cuenta</strong> en modo protegido solo admite accesos desde IPs donde ese usuario ya había entrado, desde la lista blanca o resolviendo el CAPTCHA (si está configurado).</p>
            <div class="nolantis-table-actions">
                <?php nolantis_ll_post_form_open( 'nolantis_ll_unlock_all', '¿Levantar todos los bloqueos?' ); ?>
                    <?php submit_button( 'Desbloquear todo', 'secondary', 'submit', false ); ?>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Acceso de emergencia</h2>
        <p>Si te quedas fuera, añade esta línea en <code>wp-config.php</code> (antes de <em>"That's all, stop editing!"</em>), entra, desbloquea tu IP y bórrala después:</p>
        <p><code>define( 'NOLANTIS_LIMIT_LOGIN_DISABLE', true );</code></p>
    </div>

    <?php
    nolantis_render_update_tools_card();
}

function nolantis_ll_render_security_review() {
    $admins     = get_users(
        array(
            'role'    => 'administrator',
            'orderby' => 'login',
            'number'  => 50,
        )
    );
    $bot_users  = nolantis_ll_get_existing_bot_usernames();
    $checks     = array();

    if ( ! empty( $bot_users ) ) {
        $checks[] = array( 'warn', sprintf( 'Existen usuarios con nombres que prueban los bots: %s. Crea otro administrador con un nombre distinto y elimina estos usuarios (asignando su contenido al nuevo).', implode( ', ', $bot_users ) ) );
    }

    if ( ! nolantis_ll_turnstile_is_configured() ) {
        $checks[] = array( 'info', 'El CAPTCHA (Cloudflare Turnstile) no está configurado. Es opcional, pero permite a los usuarios legítimos entrar desde redes nuevas durante un ataque.' );
    }

    if ( ! empty( nolantis_ll_get_setting( 'enable_xmlrpc' ) ) ) {
        $checks[] = array( 'info', 'XML-RPC está activado. Desactívalo en Ajustes si la web no lo necesita.' );
    }

    $integrity = function_exists( 'nolantis_integrity_get_results' ) ? nolantis_integrity_get_results() : false;

    if ( $integrity && nolantis_integrity_count_issues( $integrity ) > 0 ) {
        $checks[] = array( 'warn', sprintf( 'La comprobación de integridad ha encontrado %d posibles archivos sospechosos. Revísalos en la pestaña "Integridad".', nolantis_integrity_count_issues( $integrity ) ) );
    } elseif ( ! $integrity ) {
        $checks[] = array( 'info', 'Todavía no se ha comprobado la integridad de los archivos de WordPress (se hace sola una vez al día o desde la pestaña "Integridad").' );
    }

    if ( empty( nolantis_ll_get_setting( 'alert_user_attack' ) ) && empty( nolantis_ll_get_setting( 'alert_long_lockout' ) ) && empty( nolantis_ll_get_setting( 'alert_admin_new_ip' ) ) && empty( nolantis_ll_get_setting( 'alert_integrity' ) ) ) {
        $checks[] = array( 'info', 'Los avisos por email están desactivados. Puedes activarlos en la pestaña "CAPTCHA y avisos".' );
    }
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>Revisión de seguridad</h2>
        <?php if ( ! empty( $checks ) ) : ?>
            <ul class="nolantis-check-list">
                <?php foreach ( $checks as $check ) : ?>
                    <li><span class="nolantis-badge nolantis-badge-<?php echo esc_attr( $check[0] ); ?>"><?php echo esc_html( 'warn' === $check[0] ? 'Revisar' : 'Info' ); ?></span> <?php echo esc_html( $check[1] ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Administradores</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Slug público (/author/…)</th>
                    <th>IPs conocidas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $admins as $admin ) : ?>
                    <?php $reveals = nolantis_ll_nicename_reveals_login( $admin ); ?>
                    <tr>
                        <td><strong><?php echo esc_html( $admin->user_login ); ?></strong><?php echo get_current_user_id() === $admin->ID ? ' (tú)' : ''; ?></td>
                        <td>
                            <code><?php echo esc_html( $admin->user_nicename ); ?></code>
                            <?php if ( $reveals ) : ?>
                                <span class="nolantis-badge nolantis-badge-warn">Revela el usuario</span>
                                <?php nolantis_ll_post_form_open( 'nolantis_ll_fix_nicename', 'Se cambiará la URL pública de la página de autor de este usuario. ¿Continuar?' ); ?>
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $admin->ID ); ?>" />
                                    <?php submit_button( 'Cambiar slug', 'small', 'submit', false ); ?>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( number_format_i18n( count( nolantis_ll_get_known_ips( $admin->ID ) ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">El slug público aparece en la URL de la página de autor. Si coincide con el usuario de acceso, cualquiera puede averiguar el nombre con el que entrar. Cambiarlo modifica la URL <code>/author/…</code> de ese usuario.</p>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Pestana Registro
 * ---------------------------------------------------------------------- */

function nolantis_ll_render_log_tab() {
    $event_labels  = nolantis_ll_get_event_labels();
    $source_labels = nolantis_ll_get_source_labels();
    $filter        = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
    $filter        = isset( $event_labels[ $filter ] ) ? $filter : '';
    $entries       = nolantis_ll_get_log_entries( 200, $filter );
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>Registro de accesos</h2>
        <p class="nolantis-filters">
            <a href="<?php echo esc_url( nolantis_ll_admin_url( 'registro' ) ); ?>"<?php echo '' === $filter ? ' class="current" aria-current="page"' : ''; ?>>Todos</a>
            <?php foreach ( $event_labels as $event => $label ) : ?>
                | <a href="<?php echo esc_url( nolantis_ll_admin_url( 'registro', array( 'event' => $event ) ) ); ?>"<?php echo $event === $filter ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </p>
        <?php if ( empty( $entries ) ) : ?>
            <p>No hay registros.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Evento</th>
                        <th>IP</th>
                        <th>Usuario</th>
                        <th>Origen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( nolantis_ll_format_date( $entry->created_at ) ); ?></td>
                            <td><span class="nolantis-badge nolantis-badge-<?php echo esc_attr( $entry->event ); ?>"><?php echo esc_html( isset( $event_labels[ $entry->event ] ) ? $event_labels[ $entry->event ] : $entry->event ); ?></span></td>
                            <td><code><?php echo esc_html( '' !== $entry->ip ? $entry->ip : '—' ); ?></code></td>
                            <td><?php echo esc_html( '' !== $entry->username ? $entry->username : '—' ); ?></td>
                            <td><?php echo esc_html( isset( $source_labels[ $entry->source ] ) ? $source_labels[ $entry->source ] : $entry->source ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Se muestran los 200 eventos más recientes. Los registros se borran automáticamente a los <?php echo esc_html( (int) nolantis_ll_get_setting( 'log_retention_days' ) ); ?> días.</p>
        <?php endif; ?>
        <div class="nolantis-table-actions">
            <?php nolantis_ll_post_form_open( 'nolantis_ll_clear_log', '¿Vaciar todo el registro? Esto también reinicia los contadores de intentos fallidos.' ); ?>
                <?php submit_button( 'Vaciar registro', 'secondary', 'submit', false ); ?>
            </form>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Campos de formulario
 * ---------------------------------------------------------------------- */

function nolantis_ll_field_name( $key ) {
    return NOLANTIS_LL_OPTION . '[' . $key . ']';
}

function nolantis_ll_render_number_field( $key, $suffix ) {
    $bounds = nolantis_ll_get_numeric_bounds();

    printf(
        '<input type="number" class="small-text" name="%1$s" value="%2$d" min="%3$d" max="%4$d" required /> %5$s',
        esc_attr( nolantis_ll_field_name( $key ) ),
        (int) nolantis_ll_get_setting( $key ),
        (int) $bounds[ $key ][0],
        (int) $bounds[ $key ][1],
        esc_html( $suffix )
    );
}

/**
 * El campo oculto con valor 0 hace que una casilla desmarcada tambien se envie: asi el saneado
 * distingue "desmarcada" de "esta casilla no esta en esta pestana".
 */
function nolantis_ll_render_checkbox( $key, $label ) {
    printf(
        '<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
        esc_attr( nolantis_ll_field_name( $key ) ),
        checked( ! empty( nolantis_ll_get_setting( $key ) ), true, false ),
        esc_html( $label )
    );
}

function nolantis_ll_render_textarea( $key, $rows, $placeholder, $values ) {
    printf(
        '<textarea id="nolantis_ll_%1$s" class="code" rows="%2$d" name="%3$s" placeholder="%4$s">%5$s</textarea>',
        esc_attr( $key ),
        (int) $rows,
        esc_attr( nolantis_ll_field_name( $key ) ),
        esc_attr( $placeholder ),
        esc_textarea( implode( "\n", (array) $values ) )
    );
}

function nolantis_ll_settings_form_open() {
    echo '<form action="options.php" method="post">';
    settings_fields( 'nolantis_limit_login_group' );
}

function nolantis_ll_settings_form_close() {
    submit_button( 'Guardar ajustes' );
    echo '</form>';
}

/* -------------------------------------------------------------------------
 * Pestana Ajustes
 * ---------------------------------------------------------------------- */

function nolantis_ll_render_settings_tab() {
    $settings = nolantis_ll_get_settings();
    nolantis_ll_settings_form_open();
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>Bloqueo por IP</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Limitar intentos de acceso</th>
                <td><?php nolantis_ll_render_checkbox( 'enabled', 'Bloquear las IPs que fallen demasiadas veces' ); ?></td>
            </tr>
            <tr>
                <th scope="row">Intentos permitidos</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'max_attempts', 'fallos en' ); ?>
                    <?php nolantis_ll_render_number_field( 'window_minutes', 'minutos' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Primer bloqueo</th>
                <td><?php nolantis_ll_render_number_field( 'lockout_1_minutes', 'minutos' ); ?></td>
            </tr>
            <tr>
                <th scope="row">Segundo bloqueo</th>
                <td><?php nolantis_ll_render_number_field( 'lockout_2_hours', 'horas' ); ?></td>
            </tr>
            <tr>
                <th scope="row">Tercer bloqueo y siguientes</th>
                <td><?php nolantis_ll_render_number_field( 'lockout_3_days', 'días' ); ?></td>
            </tr>
            <tr>
                <th scope="row">Ventana de reincidencia</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'reincidence_hours', 'horas' ); ?>
                    <p class="description">Si una IP vuelve a bloquearse dentro de este tiempo desde que terminó su bloqueo anterior, sube al siguiente nivel.</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Protección por cuenta (contra botnets)</h2>
        <p>Una botnet usa miles de IPs con pocos intentos cada una, así que ninguna llega al límite por IP. Esta protección cuenta los fallos <strong>contra cada cuenta</strong>, vengan de la IP que vengan.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Activar</th>
                <td><?php nolantis_ll_render_checkbox( 'user_protection_enabled', 'Proteger las cuentas atacadas desde muchas IPs' ); ?></td>
            </tr>
            <tr>
                <th scope="row">Se activa con</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'user_max_failures', 'fallos contra la misma cuenta en' ); ?>
                    <?php nolantis_ll_render_number_field( 'user_window_minutes', 'minutos' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Duración del modo protegido</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'user_protection_minutes', 'minutos' ); ?>
                    <p class="description">Se renueva mientras siga el ataque. Durante ese tiempo solo se puede entrar en la cuenta desde IPs donde ese usuario ya había entrado antes, desde la lista blanca o resolviendo el CAPTCHA (si está configurado).</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Usuarios típicos de bots</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Bloqueo inmediato</th>
                <td>
                    <?php nolantis_ll_render_checkbox( 'bot_usernames_enabled', 'Bloquear al momento las IPs que prueben estos usuarios' ); ?>
                    <p class="description">Solo si el usuario <strong>no existe</strong> en esta web. Si existe un usuario real con ese nombre, nunca se aplica.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="nolantis_ll_bot_usernames">Lista de usuarios</label></th>
                <td>
                    <?php nolantis_ll_render_textarea( 'bot_usernames', 6, 'admin', $settings['bot_usernames'] ); ?>
                    <p class="description">Uno por línea.</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Recuperación de contraseña</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Limitar solicitudes</th>
                <td>
                    <?php nolantis_ll_render_checkbox( 'lostpw_limit_enabled', 'Limitar "¿Has olvidado tu contraseña?"' ); ?>
                    <p class="description">Evita que se use para enviar correos masivos. Al superar el límite no se envía nada.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Por IP</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'lostpw_ip_max', 'solicitudes cada' ); ?>
                    <?php nolantis_ll_render_number_field( 'lostpw_ip_window_minutes', 'minutos' ); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Por cuenta</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'lostpw_user_max', 'correos cada' ); ?>
                    <?php nolantis_ll_render_number_field( 'lostpw_user_window_minutes', 'minutos' ); ?>
                </td>
            </tr>
        </table>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Detección de la IP</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Origen de la IP</th>
                <td>
                    <?php foreach ( nolantis_ll_get_ip_source_labels() as $value => $label ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="radio" name="<?php echo esc_attr( nolantis_ll_field_name( 'ip_source' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['ip_source'], $value ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">"Automático" sirve para casi todas las webs, también detrás de Cloudflare. Las cabeceras de proxy solo se aceptan si la conexión llega realmente desde ese proxy (en Cloudflare se comprueban sus rangos oficiales), así nadie puede falsificar su IP. Las IPs internas (127.0.0.1, 10.x, 192.168.x…) nunca se bloquean.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="nolantis_ll_trusted_proxies">Proxies de confianza</label></th>
                <td>
                    <?php nolantis_ll_render_textarea( 'trusted_proxies', 3, '10.0.0.1', $settings['trusted_proxies'] ); ?>
                    <p class="description">Solo para "Proxy de confianza". Una IP o rango CIDR por línea. Los rangos de Cloudflare ya se consideran de confianza.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="nolantis_ll_whitelist">Lista blanca</label></th>
                <td>
                    <?php nolantis_ll_render_textarea( 'whitelist', 5, "203.0.113.10\n198.51.100.0/24\n2001:db8::/32", $settings['whitelist'] ); ?>
                    <p class="description">IPs que nunca se bloquean ni ven el CAPTCHA. Una IP o rango CIDR por línea (IPv4 o IPv6); para IPv6 usa el bloque /64 (el botón "Añadir mi IP" ya lo hace).</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Endurecimiento</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">XML-RPC</th>
                <td>
                    <?php nolantis_ll_render_checkbox( 'enable_xmlrpc', 'Activar XML-RPC' ); ?>
                    <p class="description">Desactivado por defecto: es una de las vías favoritas de los ataques de fuerza bruta. Actívalo solo si la web usa Jetpack, la app móvil de WordPress o publica desde herramientas externas por XML-RPC. Aunque esté activo, sus intentos fallidos cuentan para el bloqueo.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Enumeración de usuarios</th>
                <td>
                    <?php nolantis_ll_render_checkbox( 'block_user_enumeration', 'Ocultar los nombres de usuario a los visitantes' ); ?>
                    <p class="description">Bloquea <code>/?author=N</code>, el endpoint <code>/wp-json/wp/v2/users</code>, los datos de autor de oEmbed y el sitemap de usuarios para quien no ha iniciado sesión.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Mensajes de error</th>
                <td>
                    <?php nolantis_ll_render_checkbox( 'generic_login_errors', 'No revelar si un usuario existe' ); ?>
                    <p class="description">El login muestra "Usuario o contraseña incorrectos" y la recuperación de contraseña responde igual exista o no la cuenta.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Conservar registros</th>
                <td>
                    <?php nolantis_ll_render_number_field( 'log_retention_days', 'días' ); ?>
                    <p class="description">Las IPs son datos personales (RGPD): se borran automáticamente pasado este tiempo.</p>
                </td>
            </tr>
        </table>
    </div>
    <?php
    nolantis_ll_settings_form_close();
}

/* -------------------------------------------------------------------------
 * Pestana CAPTCHA y avisos
 * ---------------------------------------------------------------------- */

function nolantis_ll_render_alerts_tab() {
    $settings = nolantis_ll_get_settings();
    nolantis_ll_settings_form_open();
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>CAPTCHA: Cloudflare Turnstile</h2>
        <p>Opcional y gratuito. Crea un widget en <strong>Cloudflare &gt; Turnstile</strong> con el dominio de esta web y pega aquí sus claves. Sin claves no se muestra nada ni se carga ningún script externo.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="nolantis_ll_turnstile_site_key">Clave de sitio</label></th>
                <td><input type="text" id="nolantis_ll_turnstile_site_key" class="regular-text code" name="<?php echo esc_attr( nolantis_ll_field_name( 'turnstile_site_key' ) ); ?>" value="<?php echo esc_attr( $settings['turnstile_site_key'] ); ?>" autocomplete="off" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="nolantis_ll_turnstile_secret_key">Clave secreta</label></th>
                <td><input type="password" id="nolantis_ll_turnstile_secret_key" class="regular-text code" name="<?php echo esc_attr( nolantis_ll_field_name( 'turnstile_secret_key' ) ); ?>" value="<?php echo esc_attr( $settings['turnstile_secret_key'] ); ?>" autocomplete="new-password" /></td>
            </tr>
            <tr>
                <th scope="row">Mostrar el CAPTCHA</th>
                <td>
                    a partir de <?php nolantis_ll_render_number_field( 'turnstile_after_failures', 'fallos desde la misma IP' ); ?>
                    <p class="description">0 = mostrarlo siempre. También se muestra a quien intente entrar en una cuenta en modo protegido desde una red nueva. Se integra en el login de WordPress y en el de WooCommerce.</p>
                </td>
            </tr>
        </table>
        <p>Estado: <?php echo nolantis_ll_turnstile_is_configured() ? '<span class="nolantis-badge nolantis-badge-ok">Configurado</span>' : '<span class="nolantis-badge">Sin configurar</span>'; ?></p>
    </div>

    <div class="nolantis-card nolantis-card-wide">
        <h2>Avisos por email</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="nolantis_ll_alert_email">Enviar avisos a</label></th>
                <td>
                    <input type="email" id="nolantis_ll_alert_email" class="regular-text" name="<?php echo esc_attr( nolantis_ll_field_name( 'alert_email' ) ); ?>" value="<?php echo esc_attr( $settings['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                    <p class="description">Vacío = email del administrador (<?php echo esc_html( get_option( 'admin_email' ) ); ?>). Se envían con el SMTP del plugin si está configurado.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Avisar cuando</th>
                <td>
                    <?php nolantis_ll_render_checkbox( 'alert_user_attack', 'Una cuenta entra en modo protegido (ataque desde muchas IPs)' ); ?><br />
                    <?php nolantis_ll_render_checkbox( 'alert_long_lockout', 'Una IP recibe el bloqueo más largo (nivel 3)' ); ?><br />
                    <?php nolantis_ll_render_checkbox( 'alert_admin_new_ip', 'Un administrador entra desde una IP desde la que nunca había entrado' ); ?><br />
                    <?php nolantis_ll_render_checkbox( 'alert_integrity', 'La comprobación diaria de integridad encuentra archivos sospechosos' ); ?>
                    <p class="description">Todos vienen desactivados: marca los que quieras recibir. Como máximo se envía un aviso de cada tipo por hora, para no saturar el buzón durante un ataque.</p>
                </td>
            </tr>
        </table>
    </div>
    <?php
    nolantis_ll_settings_form_close();
    ?>
    <div class="nolantis-card nolantis-card-wide">
        <h2>Probar los avisos</h2>
        <p>Envía un email de prueba a <strong><?php echo esc_html( nolantis_ll_get_alert_recipient() ); ?></strong> (guarda antes los cambios).</p>
        <?php nolantis_ll_post_form_open( 'nolantis_ll_test_alert' ); ?>
            <?php submit_button( 'Enviar aviso de prueba', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}
