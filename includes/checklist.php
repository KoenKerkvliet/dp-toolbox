<?php
/**
 * DP Toolbox — Site-oplevering checklist
 *
 * Per-site afvinkbare lijst met items die horen bij een site-oplevering.
 * Auto-items checken zichzelf live; handmatige items worden opgeslagen
 * in de site-eigen option `dp_toolbox_checklist_state` (geen globale sync).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const DP_TOOLBOX_CHECKLIST_OPTION = 'dp_toolbox_checklist_state';

/* ------------------------------------------------------------------ */
/*  Registry                                                           */
/* ------------------------------------------------------------------ */

function dp_toolbox_get_checklist_groups() {
    $groups = [
        'visibility' => [
            'label' => 'Zichtbaarheid & SEO',
            'icon'  => 'dashicons-visibility',
            'items' => [
                [
                    'id'    => 'noindex_off',
                    'label' => 'Zoekmachines mogen indexeren',
                    'desc'  => 'WP Reading: "Discourage search engines" uit.',
                    'check' => fn() => get_option( 'blog_public' ) === '1',
                    'fix'   => admin_url( 'options-reading.php' ),
                    'fix_label' => 'Reading',
                ],
                [
                    'id'    => 'tagline_changed',
                    'label' => 'Tagline aangepast',
                    'desc'  => 'Niet meer de WP-default ("Just another WordPress site").',
                    'check' => function () {
                        $tag = trim( (string) get_option( 'blogdescription', '' ) );
                        return ! in_array( $tag, [ '', 'Just another WordPress site', 'Nog een WordPress site' ], true );
                    },
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
                [
                    'id'    => 'favicon_set',
                    'label' => 'Favicon ingesteld',
                    'desc'  => 'Site icon zichtbaar in browser-tab.',
                    'check' => fn() => has_site_icon(),
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
                [
                    'id'    => 'redirects_imported',
                    'label' => '301-redirects geïmporteerd',
                    'desc'  => 'Bij migratie: oude URLs doorverwijzen naar nieuwe paden.',
                ],
            ],
        ],

        'wp_basics' => [
            'label' => 'WP-basisinstellingen',
            'icon'  => 'dashicons-admin-settings',
            'items' => [
                [
                    'id'    => 'timezone_amsterdam',
                    'label' => 'Tijdzone = Europe/Amsterdam',
                    'check' => fn() => get_option( 'timezone_string' ) === 'Europe/Amsterdam',
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
                [
                    'id'    => 'date_format_dmy',
                    'label' => 'Datumformaat: dag maand jaar',
                    'desc'  => 'Format moet "j", "F" en "Y" bevatten.',
                    'check' => function () {
                        $f = (string) get_option( 'date_format' );
                        return strpos( $f, 'j' ) !== false
                            && strpos( $f, 'F' ) !== false
                            && strpos( $f, 'Y' ) !== false;
                    },
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
                [
                    'id'    => 'time_format_24h',
                    'label' => 'Tijdformaat: 24-uurs',
                    'desc'  => '"H:i" of vergelijkbaar — geen am/pm.',
                    'check' => function () {
                        $f = (string) get_option( 'time_format' );
                        $has_24 = strpbrk( $f, 'HG' ) !== false;
                        $has_12 = strpbrk( $f, 'aAg' ) !== false;
                        return $has_24 && ! $has_12;
                    },
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
                [
                    'id'    => 'week_start_monday',
                    'label' => 'Week begint op maandag',
                    'check' => fn() => (int) get_option( 'start_of_week' ) === 1,
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
                [
                    'id'    => 'permalinks_pretty',
                    'label' => 'Permalinks ≠ "Plain"',
                    'check' => fn() => (string) get_option( 'permalink_structure' ) !== '',
                    'fix'   => admin_url( 'options-permalink.php' ),
                    'fix_label' => 'Permalinks',
                ],
                [
                    'id'    => 'site_url_https',
                    'label' => 'Site-URL is HTTPS',
                    'check' => fn() => str_starts_with( (string) home_url(), 'https://' ),
                    'fix'   => admin_url( 'options-general.php' ),
                    'fix_label' => 'Algemeen',
                ],
            ],
        ],

        'content' => [
            'label' => 'Content opschonen',
            'icon'  => 'dashicons-trash',
            'items' => [
                [
                    'id'    => 'sample_page_deleted',
                    'label' => 'Sample Page verwijderd',
                    'check' => fn() => ! get_page_by_path( 'sample-page' ),
                    'fix'   => admin_url( 'edit.php?post_type=page' ),
                    'fix_label' => 'Pagina\'s',
                ],
                [
                    'id'    => 'hello_world_deleted',
                    'label' => '"Hello World" post verwijderd',
                    'check' => fn() => ! get_page_by_path( 'hello-world', OBJECT, 'post' )
                                       && ! get_page_by_path( 'hallo-wereld', OBJECT, 'post' ),
                    'fix'   => admin_url( 'edit.php' ),
                    'fix_label' => 'Berichten',
                ],
                [
                    'id'    => 'default_comment_deleted',
                    'label' => 'Default WP-comment verwijderd',
                    'desc'  => 'De automatische "A WordPress Commenter" reactie.',
                    'check' => function () {
                        global $wpdb;
                        $count = (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = 'wapuu@wordpress.example'"
                        );
                        return $count === 0;
                    },
                    'fix'   => admin_url( 'edit-comments.php' ),
                    'fix_label' => 'Reacties',
                ],
                [
                    'id'    => 'privacy_policy_set',
                    'label' => 'Privacy policy gepubliceerd',
                    'check' => function () {
                        $id = (int) get_option( 'wp_page_for_privacy_policy' );
                        return $id > 0 && get_post_status( $id ) === 'publish';
                    },
                    'fix'   => admin_url( 'options-privacy.php' ),
                    'fix_label' => 'Privacy',
                ],
                [
                    'id'    => 'menus_assigned',
                    'label' => 'Menu\'s gevuld in alle locaties',
                    'check' => function () {
                        $registered = get_registered_nav_menus();
                        if ( empty( $registered ) ) return true;
                        $assigned = get_nav_menu_locations();
                        foreach ( array_keys( $registered ) as $loc ) {
                            if ( empty( $assigned[ $loc ] ) ) return false;
                        }
                        return true;
                    },
                    'fix'   => admin_url( 'nav-menus.php' ),
                    'fix_label' => 'Menu\'s',
                ],
            ],
        ],

        'security' => [
            'label' => 'Beveiliging & onderhoud',
            'icon'  => 'dashicons-shield',
            'items' => [
                [
                    'id'    => 'wp_debug_off',
                    'label' => 'WP_DEBUG staat uit',
                    'desc'  => 'In productie wp-config.php — anders staan errors zichtbaar.',
                    'check' => fn() => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ),
                ],
                [
                    'id'    => 'no_pending_updates',
                    'label' => 'Geen pending updates',
                    'desc'  => 'WP-core, plugins en thema\'s zijn up-to-date.',
                    'check' => function () {
                        if ( ! function_exists( 'wp_get_update_data' ) ) return true;
                        $d = wp_get_update_data();
                        return (int) ( $d['counts']['total'] ?? 0 ) === 0;
                    },
                    'fix'   => admin_url( 'update-core.php' ),
                    'fix_label' => 'Updates',
                ],
                [
                    'id'    => 'aios_active',
                    'label' => 'Beveiligings-plugin actief',
                    'desc'  => 'AIOS (All-In-One Security).',
                    'check' => function () {
                        return dp_toolbox_cl_any_plugin_active( [
                            'all-in-one-wp-security-and-firewall/wp-security.php',
                        ] );
                    },
                    'fix'   => admin_url( 'plugins.php' ),
                    'fix_label' => 'Plugins',
                ],
                [
                    'id'    => 'backup_plugin_active',
                    'label' => 'Backup-plugin actief',
                    'desc'  => 'BackupBliss, UpdraftPlus, of vergelijkbaar.',
                    'check' => function () {
                        return dp_toolbox_cl_any_plugin_active( [
                            'backup-backup/backup-backup.php',
                            'updraftplus/updraftplus.php',
                            'all-in-one-wp-migration/all-in-one-wp-migration.php',
                            'duplicator/duplicator.php',
                            'wpvivid-backuprestore/wpvivid-backuprestore.php',
                            'backwpup/backwpup.php',
                        ] );
                    },
                    'fix'   => admin_url( 'plugins.php' ),
                    'fix_label' => 'Plugins',
                ],
                [
                    'id'    => 'default_admin_user_removed',
                    'label' => 'Default "admin" gebruiker weg',
                    'desc'  => 'Username "admin" is een security-risk.',
                    'check' => fn() => ! get_user_by( 'login', 'admin' ),
                    'fix'   => admin_url( 'users.php' ),
                    'fix_label' => 'Gebruikers',
                ],
            ],
        ],

        'mail' => [
            'label' => 'Mail & forms',
            'icon'  => 'dashicons-email-alt',
            'items' => [
                [
                    'id'    => 'smtp_configured',
                    'label' => 'SMTP geconfigureerd',
                    'check' => function () {
                        $s = get_option( 'dp_toolbox_smtp_settings', [] );
                        return ! empty( $s['host'] ) && ! empty( $s['from_email'] );
                    },
                    'fix'   => admin_url( 'admin.php?page=dp-toolbox#settings-smtp' ),
                    'fix_label' => 'SMTP',
                ],
                [
                    'id'    => 'test_email_sent',
                    'label' => 'Test-mail succesvol verstuurd',
                    'desc'  => 'Verstuur een test via de DP Toolbox SMTP-pagina.',
                    'fix'   => admin_url( 'admin.php?page=dp-toolbox#settings-smtp' ),
                    'fix_label' => 'SMTP',
                ],
                [
                    'id'    => 'forms_tested',
                    'label' => 'Alle contactformulieren getest',
                    'desc'  => 'Test elk formulier vanaf de site, controleer ontvangst.',
                ],
            ],
        ],

        'performance' => [
            'label' => 'Performance',
            'icon'  => 'dashicons-performance',
            'items' => [
                [
                    'id'    => 'cache_plugin_active',
                    'label' => 'Cache-plugin actief',
                    'desc'  => 'LiteSpeed Cache, WP Rocket, W3 Total Cache, etc.',
                    'check' => function () {
                        return dp_toolbox_cl_any_plugin_active( [
                            'litespeed-cache/litespeed-cache.php',
                            'wp-rocket/wp-rocket.php',
                            'w3-total-cache/w3-total-cache.php',
                            'wp-super-cache/wp-cache.php',
                            'wp-fastest-cache/wpFastestCache.php',
                        ] );
                    },
                    'fix'   => admin_url( 'plugins.php' ),
                    'fix_label' => 'Plugins',
                ],
                [
                    'id'    => 'webp_done',
                    'label' => 'WebP-conversie gedraaid',
                    'desc'  => 'Alle JPG/PNG omgezet naar WebP.',
                    'fix'   => admin_url( 'admin.php?page=dp-toolbox#settings-webp-converter' ),
                    'fix_label' => 'WebP',
                ],
                [
                    'id'    => 'alt_text_complete',
                    'label' => 'Alle afbeeldingen hebben alt-text',
                    'check' => function () {
                        $cached = get_transient( 'dp_toolbox_cl_alt_missing' );
                        if ( $cached === false ) {
                            global $wpdb;
                            $cached = (int) $wpdb->get_var( "
                                SELECT COUNT(*) FROM {$wpdb->posts} p
                                LEFT JOIN {$wpdb->postmeta} m
                                    ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
                                WHERE p.post_type = 'attachment'
                                    AND p.post_mime_type LIKE 'image/%'
                                    AND ( m.meta_value IS NULL OR m.meta_value = '' )
                            " );
                            set_transient( 'dp_toolbox_cl_alt_missing', $cached, 5 * MINUTE_IN_SECONDS );
                        }
                        return (int) $cached === 0;
                    },
                    'fix'   => admin_url( 'admin.php?page=dp-toolbox#settings-alt-text-filler' ),
                    'fix_label' => 'Alt Text Filler',
                ],
            ],
        ],

        'compliance' => [
            'label' => 'Compliance & analytics',
            'icon'  => 'dashicons-privacy',
            'items' => [
                [
                    'id'    => 'cookie_consent_active',
                    'label' => 'Cookie consent actief',
                    'desc'  => 'Complianz, CookieYes, of vergelijkbaar.',
                    'check' => function () {
                        return dp_toolbox_cl_any_plugin_active( [
                            'complianz-gdpr/complianz-gpdr.php',
                            'complianz-gdpr-premium/complianz-gpdr-premium.php',
                            'cookie-law-info/cookie-law-info.php',
                            'cookie-notice/cookie-notice.php',
                            'gdpr-cookie-consent/gdpr-cookie-consent.php',
                        ] );
                    },
                    'fix'   => admin_url( 'plugins.php' ),
                    'fix_label' => 'Plugins',
                ],
                [
                    'id'    => 'analytics_active',
                    'label' => 'Analytics-plugin actief',
                    'desc'  => 'Independent Analytics, Site Kit, MonsterInsights, etc.',
                    'check' => function () {
                        return dp_toolbox_cl_any_plugin_active( [
                            'independent-analytics/iawp.php',
                            'google-site-kit/google-site-kit.php',
                            'google-analytics-for-wordpress/googleanalytics.php',
                            'matomo/matomo.php',
                            'fathom-analytics/fathom-analytics.php',
                        ] );
                    },
                    'fix'   => admin_url( 'plugins.php' ),
                    'fix_label' => 'Plugins',
                ],
                [
                    'id'    => 'cookie_banner_tested',
                    'label' => 'Cookie banner getest',
                    'desc'  => 'Bezocht in incognito, accept/decline werken.',
                ],
            ],
        ],

        'handover' => [
            'label' => 'Overdracht aan klant',
            'icon'  => 'dashicons-businessperson',
            'items' => [
                [
                    'id'    => 'client_admin_exists',
                    'label' => 'Klant heeft eigen admin-account',
                    'check' => function () {
                        $admins = get_users( [ 'role' => 'administrator', 'fields' => [ 'user_email' ] ] );
                        foreach ( $admins as $u ) {
                            $email = strtolower( trim( (string) $u->user_email ) );
                            if ( ! str_ends_with( $email, '@designpixels.nl' ) ) return true;
                        }
                        return false;
                    },
                    'fix'   => admin_url( 'users.php' ),
                    'fix_label' => 'Gebruikers',
                ],
                [
                    'id'    => 'dp_admin_exists',
                    'label' => 'DP-account aanwezig',
                    'desc'  => 'Minimaal één @designpixels.nl admin voor toekomstige support.',
                    'check' => function () {
                        $admins = get_users( [ 'role' => 'administrator', 'fields' => [ 'user_email' ] ] );
                        foreach ( $admins as $u ) {
                            $email = strtolower( trim( (string) $u->user_email ) );
                            if ( str_ends_with( $email, '@designpixels.nl' ) ) return true;
                        }
                        return false;
                    },
                    'fix'   => admin_url( 'users.php' ),
                    'fix_label' => 'Gebruikers',
                ],
                [
                    'id'    => 'manual_handed_over',
                    'label' => 'Handleiding overhandigd',
                    'desc'  => 'Klant heeft korte handleiding/training gehad.',
                ],
            ],
        ],
    ];

    return apply_filters( 'dp_toolbox_checklist_groups', $groups );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function dp_toolbox_cl_any_plugin_active( array $candidates ) {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach ( $candidates as $p ) {
        if ( is_plugin_active( $p ) ) return true;
    }
    return false;
}

function dp_toolbox_checklist_get_state() {
    $state = get_option( DP_TOOLBOX_CHECKLIST_OPTION, [] );
    return is_array( $state ) ? $state : [];
}

function dp_toolbox_checklist_set_item( $id, $done ) {
    $state = dp_toolbox_checklist_get_state();
    if ( $done ) {
        $state[ $id ] = true;
    } else {
        unset( $state[ $id ] );
    }
    update_option( DP_TOOLBOX_CHECKLIST_OPTION, $state, false );
}

function dp_toolbox_checklist_item_done( array $item, array $state ) {
    if ( ! empty( $item['check'] ) && is_callable( $item['check'] ) ) {
        try {
            return (bool) call_user_func( $item['check'] );
        } catch ( \Throwable $e ) {
            return false;
        }
    }
    return ! empty( $state[ $item['id'] ] );
}

/* ------------------------------------------------------------------ */
/*  AJAX: toggle handmatig item                                        */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_checklist_toggle', function () {
    if ( ! function_exists( 'dp_toolbox_current_user_has_access' )
         || ! dp_toolbox_current_user_has_access() ) {
        wp_send_json_error( 'forbidden', 403 );
    }
    check_ajax_referer( 'dp_toolbox_checklist', '_wpnonce' );

    $id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
    $done = ! empty( $_POST['done'] );

    if ( ! $id ) {
        wp_send_json_error( 'missing id', 400 );
    }

    // Guard: alleen geregistreerde manual items toelaten.
    $valid = false;
    foreach ( dp_toolbox_get_checklist_groups() as $g ) {
        foreach ( $g['items'] as $item ) {
            if ( $item['id'] === $id && empty( $item['check'] ) ) {
                $valid = true;
                break 2;
            }
        }
    }
    if ( ! $valid ) {
        wp_send_json_error( 'unknown manual item', 400 );
    }

    dp_toolbox_checklist_set_item( $id, $done );
    wp_send_json_success( [ 'id' => $id, 'done' => $done ] );
} );

/* ------------------------------------------------------------------ */
/*  Render                                                             */
/* ------------------------------------------------------------------ */

function dp_toolbox_render_checklist_tab() {
    $groups = dp_toolbox_get_checklist_groups();
    $state  = dp_toolbox_checklist_get_state();
    $nonce  = wp_create_nonce( 'dp_toolbox_checklist' );

    // Pre-compute per-group + overall counts (server-side rendered initial state).
    $totals = [ 'all' => 0, 'done' => 0 ];
    $group_counts = [];
    foreach ( $groups as $key => $g ) {
        $g_all  = count( $g['items'] );
        $g_done = 0;
        foreach ( $g['items'] as $item ) {
            if ( dp_toolbox_checklist_item_done( $item, $state ) ) $g_done++;
        }
        $group_counts[ $key ] = [ 'all' => $g_all, 'done' => $g_done ];
        $totals['all']  += $g_all;
        $totals['done'] += $g_done;
    }
    $overall_pct = $totals['all'] > 0 ? round( $totals['done'] / $totals['all'] * 100 ) : 0;
    ?>
    <style>
        .dp-checklist { font-size: 13px; }

        .dp-cl-overall {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 16px 20px; margin-bottom: 14px;
            display: flex; align-items: center; gap: 16px;
        }
        .dp-cl-overall-text { font-weight: 600; color: #1d2327; white-space: nowrap; }
        .dp-cl-overall-text strong { color: #281E5D; font-size: 16px; }
        .dp-cl-progress-track {
            flex: 1; height: 8px; background: #ececef; border-radius: 999px; overflow: hidden;
        }
        .dp-cl-progress-bar {
            height: 100%; background: linear-gradient(90deg, #281E5D 0%, #4a3a8a 100%);
            transition: width 0.3s ease;
        }
        .dp-cl-overall-pct { font-size: 12px; color: #666; min-width: 40px; text-align: right; }

        .dp-cl-group {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            margin-bottom: 8px; overflow: hidden;
        }
        .dp-cl-group[open] { border-color: #d6cdf0; box-shadow: 0 1px 6px rgba(40,30,93,0.06); }
        .dp-cl-group summary {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; cursor: pointer; user-select: none;
            list-style: none;
        }
        .dp-cl-group summary::-webkit-details-marker { display: none; }
        .dp-cl-group summary .dashicons {
            color: #281E5D; font-size: 18px; width: 18px; height: 18px;
        }
        .dp-cl-group-label { flex: 1; font-weight: 600; color: #1d2327; }
        .dp-cl-group-progress {
            font-size: 12px; color: #666; font-variant-numeric: tabular-nums;
            background: #f5f3fb; padding: 3px 10px; border-radius: 12px;
        }
        .dp-cl-group-progress.is-complete { background: #e6f6ec; color: #00a32a; }
        .dp-cl-group-chevron {
            color: #999; transition: transform 0.2s; font-size: 16px !important;
            width: 16px !important; height: 16px !important;
        }
        .dp-cl-group[open] .dp-cl-group-chevron { transform: rotate(180deg); }

        .dp-cl-items {
            border-top: 1px solid #f0f0f1;
            display: flex; flex-direction: column;
        }
        .dp-cl-item {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 18px;
            border-bottom: 1px solid #f5f5f6;
        }
        .dp-cl-item:last-child { border-bottom: none; }
        .dp-cl-item.is-manual { cursor: pointer; }
        .dp-cl-item.is-manual:hover { background: #fafaff; }

        .dp-cl-status { flex-shrink: 0; width: 24px; display: flex; justify-content: center; }
        .dp-cl-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
        }
        .dp-cl-icon .dashicons { font-size: 14px; width: 14px; height: 14px; line-height: 14px; }
        .dp-cl-icon-green { background: #e6f6ec; color: #00a32a; }
        .dp-cl-icon-red   { background: #fce9e9; color: #d63638; }

        /* Manual checkbox — visueel consistent met de auto-items (groene cirkel + vink) */
        .dp-cl-checkbox {
            appearance: none; -webkit-appearance: none;
            width: 22px; height: 22px;
            border: 2px solid #d6cdf0; border-radius: 50%;
            cursor: pointer; transition: all 0.15s;
            background: #fff; margin: 0;
            position: relative; display: inline-block;
        }
        .dp-cl-checkbox:hover { border-color: #281E5D; background: #faf8ff; }
        .dp-cl-checkbox:checked {
            background: #e6f6ec; border-color: #00a32a;
        }
        .dp-cl-checkbox:checked:hover {
            background: #d3eedd; border-color: #008a23;
        }
        .dp-cl-checkbox:checked::after {
            content: ''; position: absolute;
            left: 5px; top: 1px;
            width: 6px; height: 11px;
            border: solid #00a32a; border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .dp-cl-text { flex: 1; min-width: 0; }
        .dp-cl-label-text { font-weight: 500; color: #1d2327; }
        .dp-cl-item.is-done .dp-cl-label-text { color: #555; }
        .dp-cl-desc { margin: 2px 0 0; color: #888; font-size: 12px; }

        .dp-cl-actions { flex-shrink: 0; }
        .dp-cl-fix-btn {
            display: inline-flex; align-items: center; gap: 4px;
            background: #fff; color: #281E5D;
            border: 1px solid #d6cdf0; border-radius: 6px;
            padding: 4px 12px; font-size: 12px; font-weight: 500;
            text-decoration: none; transition: all 0.15s;
        }
        .dp-cl-fix-btn:hover {
            background: #281E5D; color: #fff; border-color: #281E5D;
        }
        .dp-cl-fix-btn .dashicons {
            font-size: 13px; width: 13px; height: 13px; line-height: 13px;
        }

        .dp-cl-help {
            margin-top: 14px; padding: 10px 14px;
            background: #f5f3fb; border-left: 3px solid #281E5D; border-radius: 4px;
            font-size: 12px; color: #555;
        }
    </style>

    <div class="dp-checklist" data-nonce="<?php echo esc_attr( $nonce ); ?>">

        <div class="dp-cl-overall">
            <div class="dp-cl-overall-text"><strong class="dp-cl-overall-done"><?php echo (int) $totals['done']; ?></strong> / <span class="dp-cl-overall-all"><?php echo (int) $totals['all']; ?></span> voltooid</div>
            <div class="dp-cl-progress-track">
                <div class="dp-cl-progress-bar" style="width: <?php echo (int) $overall_pct; ?>%;"></div>
            </div>
            <div class="dp-cl-overall-pct"><?php echo (int) $overall_pct; ?>%</div>
        </div>

        <?php foreach ( $groups as $key => $g ):
            $g_all  = $group_counts[ $key ]['all'];
            $g_done = $group_counts[ $key ]['done'];
            $is_complete = ( $g_all > 0 && $g_done >= $g_all );
            ?>
            <details class="dp-cl-group" data-group="<?php echo esc_attr( $key ); ?>" <?php echo $is_complete ? '' : 'open'; ?>>
                <summary>
                    <span class="dashicons <?php echo esc_attr( $g['icon'] ); ?>"></span>
                    <span class="dp-cl-group-label"><?php echo esc_html( $g['label'] ); ?></span>
                    <span class="dp-cl-group-progress <?php echo $is_complete ? 'is-complete' : ''; ?>">
                        <span class="dp-cl-group-done"><?php echo (int) $g_done; ?></span> / <span class="dp-cl-group-all"><?php echo (int) $g_all; ?></span>
                    </span>
                    <span class="dashicons dashicons-arrow-down-alt2 dp-cl-group-chevron"></span>
                </summary>
                <div class="dp-cl-items">
                    <?php foreach ( $g['items'] as $item ):
                        $is_auto    = ! empty( $item['check'] );
                        $done       = dp_toolbox_checklist_item_done( $item, $state );
                        $row_class  = 'dp-cl-item ' . ( $is_auto ? 'is-auto' : 'is-manual' ) . ( $done ? ' is-done' : ' is-pending' );
                        ?>
                        <div class="<?php echo esc_attr( $row_class ); ?>"
                             data-id="<?php echo esc_attr( $item['id'] ); ?>"
                             data-auto="<?php echo $is_auto ? '1' : '0'; ?>">
                            <div class="dp-cl-status">
                                <?php if ( $is_auto ): ?>
                                    <span class="dp-cl-icon dp-cl-icon-<?php echo $done ? 'green' : 'red'; ?>">
                                        <span class="dashicons dashicons-<?php echo $done ? 'yes' : 'no-alt'; ?>"></span>
                                    </span>
                                <?php else: ?>
                                    <input type="checkbox" class="dp-cl-checkbox" <?php checked( $done ); ?>>
                                <?php endif; ?>
                            </div>
                            <div class="dp-cl-text">
                                <div class="dp-cl-label-text"><?php echo esc_html( $item['label'] ); ?></div>
                                <?php if ( ! empty( $item['desc'] ) ): ?>
                                    <p class="dp-cl-desc"><?php echo esc_html( $item['desc'] ); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="dp-cl-actions">
                                <?php
                                $show_fix = ! empty( $item['fix'] ) && ( ! $is_auto || ! $done );
                                if ( $show_fix ): ?>
                                    <a href="<?php echo esc_url( $item['fix'] ); ?>" class="dp-cl-fix-btn">
                                        <?php echo esc_html( $item['fix_label'] ?? 'Open' ); ?>
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>

        <p class="dp-cl-help">
            Auto-items worden bij elke pageload gecontroleerd. Handmatige items blijven afgevinkt staan per site (geen globale sync).
        </p>
    </div>

    <script>
    (function () {
        const root = document.querySelector('.dp-checklist');
        if (!root) return;
        const nonce   = root.dataset.nonce;
        const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

        // Whole row clickable for manual items
        root.querySelectorAll('.dp-cl-item.is-manual').forEach(row => {
            row.addEventListener('click', e => {
                if (e.target.closest('.dp-cl-fix-btn')) return; // let the fix-link work
                const cb = row.querySelector('.dp-cl-checkbox');
                if (!cb || e.target === cb) return;
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        root.querySelectorAll('.dp-cl-checkbox').forEach(cb => {
            cb.addEventListener('change', async () => {
                const item = cb.closest('.dp-cl-item');
                if (!item) return;
                const id   = item.dataset.id;
                const done = cb.checked;

                cb.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('action', 'dp_toolbox_checklist_toggle');
                    fd.append('_wpnonce', nonce);
                    fd.append('id', id);
                    fd.append('done', done ? '1' : '0');
                    const res = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    item.classList.toggle('is-done', done);
                    item.classList.toggle('is-pending', !done);
                    updateProgress();
                } catch (err) {
                    cb.checked = !done; // revert
                    console.error('Checklist toggle failed', err);
                } finally {
                    cb.disabled = false;
                }
            });
        });

        function updateProgress() {
            let totalDone = 0, totalAll = 0;
            root.querySelectorAll('.dp-cl-group').forEach(group => {
                const items = group.querySelectorAll('.dp-cl-item');
                let done = 0;
                items.forEach(i => { if (i.classList.contains('is-done')) done++; });
                const all = items.length;

                const doneEl = group.querySelector('.dp-cl-group-done');
                const allEl  = group.querySelector('.dp-cl-group-all');
                const badge  = group.querySelector('.dp-cl-group-progress');
                if (doneEl) doneEl.textContent = done;
                if (allEl)  allEl.textContent  = all;
                if (badge)  badge.classList.toggle('is-complete', all > 0 && done >= all);

                totalDone += done; totalAll += all;
            });
            const overallDone = root.querySelector('.dp-cl-overall-done');
            const overallAll  = root.querySelector('.dp-cl-overall-all');
            const overallPct  = root.querySelector('.dp-cl-overall-pct');
            const overallBar  = root.querySelector('.dp-cl-progress-bar');
            const pct = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;
            if (overallDone) overallDone.textContent = totalDone;
            if (overallAll)  overallAll.textContent  = totalAll;
            if (overallPct)  overallPct.textContent  = pct + '%';
            if (overallBar)  overallBar.style.width  = pct + '%';
        }
    })();
    </script>
    <?php
}
