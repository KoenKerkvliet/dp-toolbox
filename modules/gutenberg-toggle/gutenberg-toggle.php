<?php
/**
 * Module Name: Gutenberg Toggle
 * Description: Schakel de Gutenberg block-editor per post-type uit. Geselecteerde types vallen terug op de Classic Editor.
 * Category: admin
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the list of post-type slugs where Gutenberg should be disabled.
 */
function dp_toolbox_gt_get_disabled() {
    return (array) get_option( 'dp_toolbox_gt_disabled_post_types', [] );
}

/**
 * Disable Gutenberg for selected post types.
 *
 * Priority 100 to run after most other plugins that hook this filter.
 */
add_filter( 'use_block_editor_for_post_type', function ( $use_block_editor, $post_type ) {
    $disabled = dp_toolbox_gt_get_disabled();
    if ( in_array( $post_type, $disabled, true ) ) {
        return false;
    }
    return $use_block_editor;
}, 100, 2 );

/* Admin page (inline tab under DP Toolbox → Modules) */
if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
