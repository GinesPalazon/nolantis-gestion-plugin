<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOLANTIS_GENERAL_OPTION', 'nolantis_general_settings' );

function nolantis_get_default_general_settings() {
    return array(
        'disable_post_comments' => 0,
    );
}

function nolantis_get_general_settings() {
    $settings = get_option( NOLANTIS_GENERAL_OPTION, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    return wp_parse_args( $settings, nolantis_get_default_general_settings() );
}

function nolantis_register_general_settings() {
    register_setting(
        'nolantis_general_settings_group',
        NOLANTIS_GENERAL_OPTION,
        array(
            'sanitize_callback' => 'nolantis_sanitize_general_settings',
            'default'           => nolantis_get_default_general_settings(),
        )
    );

    add_settings_section(
        'nolantis_general_section',
        'Ajustes generales',
        'nolantis_render_general_section',
        'nolantis-general-settings'
    );

    add_settings_field(
        'nolantis_disable_post_comments',
        'Desactivar comentarios',
        'nolantis_render_disable_post_comments_field',
        'nolantis-general-settings',
        'nolantis_general_section'
    );
}
add_action( 'admin_init', 'nolantis_register_general_settings' );

function nolantis_render_general_section() {
    echo '<p>Configura ajustes globales de proteccion y mantenimiento para esta web.</p>';
}

function nolantis_render_disable_post_comments_field() {
    $settings = nolantis_get_general_settings();
    $checked  = ! empty( $settings['disable_post_comments'] ) ? 'checked' : '';

    printf(
        '<label><input type="checkbox" name="%1$s[disable_post_comments]" value="1" %2$s /> %3$s</label>',
        esc_attr( NOLANTIS_GENERAL_OPTION ),
        esc_attr( $checked ),
        esc_html( 'Cerrar y bloquear comentarios en las publicaciones' )
    );

    echo '<p class="description">Esta opcion cierra comentarios y pings en entradas existentes, desactiva comentarios en nuevas publicaciones y bloquea el formulario aunque una entrada conserve comentarios abiertos.</p>';
}

function nolantis_sanitize_general_settings( $input ) {
    if ( ! is_array( $input ) ) {
        $input = array();
    }

    $sanitized = array(
        'disable_post_comments' => ! empty( $input['disable_post_comments'] ) ? 1 : 0,
    );

    if ( $sanitized['disable_post_comments'] ) {
        nolantis_close_existing_post_comments();
    }

    return wp_parse_args( $sanitized, nolantis_get_default_general_settings() );
}

function nolantis_are_post_comments_disabled() {
    $settings = nolantis_get_general_settings();

    return ! empty( $settings['disable_post_comments'] );
}

function nolantis_close_existing_post_comments() {
    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET comment_status = %s, ping_status = %s WHERE post_type = %s AND (comment_status <> %s OR ping_status <> %s)",
            'closed',
            'closed',
            'post',
            'closed',
            'closed'
        )
    );
}

function nolantis_disable_post_comments_open( $open, $post_id ) {
    if ( ! nolantis_are_post_comments_disabled() ) {
        return $open;
    }

    $post = get_post( $post_id );

    if ( $post && 'post' === $post->post_type ) {
        return false;
    }

    return $open;
}
add_filter( 'comments_open', 'nolantis_disable_post_comments_open', 20, 2 );
add_filter( 'pings_open', 'nolantis_disable_post_comments_open', 20, 2 );

function nolantis_disable_post_comments_on_insert( $data, $postarr ) {
    if ( ! nolantis_are_post_comments_disabled() ) {
        return $data;
    }

    if ( isset( $data['post_type'] ) && 'post' === $data['post_type'] ) {
        $data['comment_status'] = 'closed';
        $data['ping_status']    = 'closed';
    }

    return $data;
}
add_filter( 'wp_insert_post_data', 'nolantis_disable_post_comments_on_insert', 20, 2 );

function nolantis_hide_post_comment_support() {
    if ( ! nolantis_are_post_comments_disabled() ) {
        return;
    }

    remove_post_type_support( 'post', 'comments' );
    remove_post_type_support( 'post', 'trackbacks' );
}
add_action( 'init', 'nolantis_hide_post_comment_support', 20 );
