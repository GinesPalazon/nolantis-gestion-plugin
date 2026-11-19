<?php
/**
 * Ajustes generales: desactivar comentarios.
 *
 * Por tipo de contenido (entradas, paginas, adjuntos):
 * - cierra comentarios y pings del contenido existente (en la base de datos, al guardar);
 * - los cierra en el contenido nuevo;
 * - rechaza cualquier comentario aunque una publicacion los conserve abiertos (formulario, REST, XML-RPC);
 * - quita la caja de comentarios del editor.
 *
 * Opcionalmente oculta los comentarios del panel (menu, barra superior y feed de comentarios).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_GENERAL_OPTION', 'nolantis_general_settings' );

function nolantis_get_default_general_settings() {
    return array(
        'disable_post_comments'       => 0,
        'disable_page_comments'       => 0,
        'disable_attachment_comments' => 0,
        'hide_comments_ui'            => 0,
    );
}

function nolantis_get_general_settings() {
    $settings = get_option( NOLANTIS_GENERAL_OPTION, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    return wp_parse_args( $settings, nolantis_get_default_general_settings() );
}

/**
 * Tipos de contenido con los comentarios desactivados.
 *
 * @return string[]
 */
function nolantis_get_comment_disabled_types() {
    $settings = nolantis_get_general_settings();
    $map      = array(
        'disable_post_comments'       => 'post',
        'disable_page_comments'       => 'page',
        'disable_attachment_comments' => 'attachment',
    );
    $types    = array();

    foreach ( $map as $key => $type ) {
        if ( ! empty( $settings[ $key ] ) ) {
            $types[] = $type;
        }
    }

    return $types;
}

/* -------------------------------------------------------------------------
 * Ajustes
 * ---------------------------------------------------------------------- */

function nolantis_register_general_settings() {
    register_setting(
        'nolantis_general_settings_group',
        NOLANTIS_GENERAL_OPTION,
        array(
            'sanitize_callback' => 'nolantis_sanitize_general_settings',
            'default'           => nolantis_get_default_general_settings(),
        )
    );

    add_settings_section( 'nolantis_general_section', 'Comentarios', 'nolantis_render_general_section', 'nolantis-general-settings' );

    add_settings_field( 'nolantis_disable_comments', 'Desactivar comentarios en', 'nolantis_render_disable_comments_field', 'nolantis-general-settings', 'nolantis_general_section' );
    add_settings_field( 'nolantis_hide_comments_ui', 'Panel de administración', 'nolantis_render_hide_comments_ui_field', 'nolantis-general-settings', 'nolantis_general_section' );
}
add_action( 'admin_init', 'nolantis_register_general_settings' );

function nolantis_render_general_section() {
    echo '<p>Cierra los comentarios para frenar el spam. Se cierran los existentes, los nuevos nacen cerrados y se rechaza cualquier comentario que llegue igualmente (por el formulario, la API REST o XML-RPC).</p>';
}

function nolantis_render_general_checkbox( $key, $label ) {
    $settings = nolantis_get_general_settings();

    printf(
        '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
        esc_attr( NOLANTIS_GENERAL_OPTION ),
        esc_attr( $key ),
        checked( ! empty( $settings[ $key ] ), true, false ),
        esc_html( $label )
    );
}

function nolantis_render_disable_comments_field() {
    nolantis_render_general_checkbox( 'disable_post_comments', 'Entradas' );
    nolantis_render_general_checkbox( 'disable_page_comments', 'Páginas' );
    nolantis_render_general_checkbox( 'disable_attachment_comments', 'Adjuntos (páginas de imágenes y archivos, muy usadas por los bots de spam)' );

    echo '<p class="description">Si desmarcas una opción, las publicaciones que ya se cerraron siguen cerradas: puedes reabrirlas desde la lista de entradas o páginas con "Editar" en lote.</p>';
}

function nolantis_render_hide_comments_ui_field() {
    nolantis_render_general_checkbox( 'hide_comments_ui', 'Ocultar "Comentarios" del menú y de la barra superior, y quitar el feed de comentarios' );

    echo '<p class="description">Úsalo solo si ningún contenido de la web acepta comentarios; si no, no verás los que lleguen para moderarlos.</p>';
}

function nolantis_sanitize_general_settings( $input ) {
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    $sanitized = array();

    foreach ( array_keys( nolantis_get_default_general_settings() ) as $key ) {
        $sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    return $sanitized;
}

/**
 * Despues de guardar (y no durante el saneado, que puede ejecutarse dos veces o sin llegar a
 * guardarse) se cierran los comentarios del contenido existente de los tipos marcados.
 */
function nolantis_close_existing_comments() {
    global $wpdb;

    $types = nolantis_get_comment_disabled_types();

    if ( empty( $types ) ) {
        return;
    }

    $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed' WHERE post_type IN ({$placeholders}) AND (comment_status <> 'closed' OR ping_status <> 'closed')",
            $types
        )
    );

    // La cache de objetos guarda copias de las publicaciones: se invalida para que se vea el cambio
    // (wp_cache_flush_group existe desde WordPress 6.1).
    if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
        wp_cache_flush_group( 'posts' );
    } else {
        wp_cache_flush();
    }
}
add_action( 'add_option_' . NOLANTIS_GENERAL_OPTION, 'nolantis_close_existing_comments' );
add_action( 'update_option_' . NOLANTIS_GENERAL_OPTION, 'nolantis_close_existing_comments' );

/* -------------------------------------------------------------------------
 * Comportamiento
 * ---------------------------------------------------------------------- */

function nolantis_disable_comments_open( $open, $post_id ) {
    $post = get_post( $post_id );

    if ( $post && in_array( $post->post_type, nolantis_get_comment_disabled_types(), true ) ) {
        return false;
    }

    return $open;
}
add_filter( 'comments_open', 'nolantis_disable_comments_open', 20, 2 );
add_filter( 'pings_open', 'nolantis_disable_comments_open', 20, 2 );

function nolantis_disable_comments_on_insert( $data ) {
    if ( isset( $data['post_type'] ) && in_array( $data['post_type'], nolantis_get_comment_disabled_types(), true ) ) {
        $data['comment_status'] = 'closed';
        $data['ping_status']    = 'closed';
    }

    return $data;
}
add_filter( 'wp_insert_post_data', 'nolantis_disable_comments_on_insert', 20 );

function nolantis_remove_comment_support() {
    foreach ( nolantis_get_comment_disabled_types() as $type ) {
        remove_post_type_support( $type, 'comments' );
        remove_post_type_support( $type, 'trackbacks' );
    }
}
add_action( 'init', 'nolantis_remove_comment_support', 20 );

/* -------------------------------------------------------------------------
 * Ocultar comentarios del panel
 * ---------------------------------------------------------------------- */

function nolantis_is_comments_ui_hidden() {
    $settings = nolantis_get_general_settings();

    return ! empty( $settings['hide_comments_ui'] );
}

function nolantis_hide_comments_menu() {
    if ( nolantis_is_comments_ui_hidden() ) {
        remove_menu_page( 'edit-comments.php' );
    }
}
add_action( 'admin_menu', 'nolantis_hide_comments_menu', 999 );

function nolantis_hide_comments_admin_bar( $wp_admin_bar ) {
    if ( nolantis_is_comments_ui_hidden() ) {
        $wp_admin_bar->remove_node( 'comments' );
    }
}
add_action( 'admin_bar_menu', 'nolantis_hide_comments_admin_bar', 999 );

function nolantis_hide_comments_feed_link( $show ) {
    return nolantis_is_comments_ui_hidden() ? false : $show;
}
add_filter( 'feed_links_show_comments_feed', 'nolantis_hide_comments_feed_link' );

/**
 * En el escritorio, los widgets "De un vistazo" y "Actividad" tambien muestran comentarios.
 */
function nolantis_hide_comments_dashboard() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    if ( $screen && 'dashboard' === $screen->id && nolantis_is_comments_ui_hidden() ) {
        echo '<style>#dashboard_right_now .comment-count, #dashboard_right_now .comment-mod-count, #latest-comments { display: none !important; }</style>';
    }
}
add_action( 'admin_head', 'nolantis_hide_comments_dashboard' );
