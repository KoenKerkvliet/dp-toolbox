<?php
/**
 * Module Name: Lorem ipsum
 * Description: Voor de bouwfase: typ {ipsum2p} in een veld en er staan meteen twee alinea's opvultekst. s = zinnen, p = alinea's, w = woorden (tot 20). Werkt in WordPress-velden, Gutenberg, de klassieke editor, JetEngine, FluentCart en de Bricks-builder. Toont ook waar nog opvultekst staat, zodat er bij oplevering niets achterblijft.
 * Category: content
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DP_TOOLBOX_IPSUM_VERSION', '1.0.0' );

/*
 * De code wordt in de browser vervangen zodra hij af is, dus {ipsum2p} komt nooit in de database.
 * Alleen voor beheerders; in wp-admin en op de voorkant (de Bricks-builder draait daar).
 */
function dp_toolbox_ipsum_laden() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    wp_enqueue_script( 'dp-toolbox-ipsum', plugins_url( 'lorem-ipsum.js', __FILE__ ), [], DP_TOOLBOX_IPSUM_VERSION, true );
    wp_add_inline_script( 'dp-toolbox-ipsum', 'window.dpIpsumZinnen = ' . wp_json_encode( dp_toolbox_opvultekst_zinnen() ) . ';', 'before' );
}
add_action( 'admin_enqueue_scripts', 'dp_toolbox_ipsum_laden' );
add_action( 'wp_enqueue_scripts', 'dp_toolbox_ipsum_laden' );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
