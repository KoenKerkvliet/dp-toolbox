<?php
/**
 * Module Name: Dashboard Widgets
 * Description: Aangepaste dashboard-widgets en opruimen van standaard WordPress-widgets.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DP_TOOLBOX_PUNCH_CARD_API_URL', 'https://gzrfnafjelhyveceebwj.supabase.co/functions/v1/api-punch-cards' );

/* ------------------------------------------------------------------
 *  Widget registratie — alles in één widget
 * ------------------------------------------------------------------ */

// Priority 999: optioneel standaard WP-widgets verbergen
add_action( 'wp_dashboard_setup', function () {
    if ( ! get_option( 'dp_toolbox_dashboard_hide_defaults', true ) ) {
        return;
    }
    global $wp_meta_boxes;
    if ( isset( $wp_meta_boxes['dashboard'] ) ) {
        $wp_meta_boxes['dashboard'] = [];
    }
}, 999 );

// Priority 1000: eigen widget registreren
add_action( 'wp_dashboard_setup', function () {
    if ( ! current_user_can( 'read' ) ) {
        return;
    }

    wp_add_dashboard_widget( 'dp_toolbox_dashboard', 'DP Dashboard', 'dp_toolbox_dashboard_render' );

    // Forceer widget bovenaan normal column
    global $wp_meta_boxes;
    foreach ( [ 'normal', 'side' ] as $context ) {
        foreach ( [ 'core', 'high', 'default', 'low' ] as $priority ) {
            if ( isset( $wp_meta_boxes['dashboard'][ $context ][ $priority ]['dp_toolbox_dashboard'] ) ) {
                $widget = $wp_meta_boxes['dashboard'][ $context ][ $priority ]['dp_toolbox_dashboard'];
                unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ]['dp_toolbox_dashboard'] );
                $wp_meta_boxes['dashboard']['normal']['core'] = [ 'dp_toolbox_dashboard' => $widget ]
                    + ( $wp_meta_boxes['dashboard']['normal']['core'] ?? [] );
            }
        }
    }
}, 1000 );

// Override saved user meta box order
add_filter( 'get_user_option_meta-box-order_dashboard', function ( $order ) {
    if ( ! is_array( $order ) ) {
        $order = [];
    }

    foreach ( [ 'normal', 'side', 'column3', 'column4' ] as $col ) {
        if ( isset( $order[ $col ] ) ) {
            $order[ $col ] = preg_replace( '/\b,?dp_toolbox_dashboard\b/', '', $order[ $col ] );
            $order[ $col ] = ltrim( $order[ $col ], ',' );
        }
    }

    $existing        = isset( $order['normal'] ) ? trim( $order['normal'], ',' ) : '';
    $order['normal'] = 'dp_toolbox_dashboard' . ( $existing ? ',' . $existing : '' );

    return $order;
} );

// Force DP widget to NOT be hidden, even if user had closed it / screen options unchecked it
add_filter( 'hidden_meta_boxes', function ( $hidden ) {
    if ( ! is_array( $hidden ) ) return $hidden;
    return array_values( array_diff( $hidden, [ 'dp_toolbox_dashboard' ] ) );
}, 10, 1 );

add_filter( 'get_user_option_metaboxhidden_dashboard', function ( $hidden ) {
    if ( ! is_array( $hidden ) ) return $hidden;
    return array_values( array_diff( $hidden, [ 'dp_toolbox_dashboard' ] ) );
} );

// Force widget to be "open" (not collapsed)
add_filter( 'get_user_option_closedpostboxes_dashboard', function ( $closed ) {
    if ( ! is_array( $closed ) ) return $closed;
    return array_values( array_diff( $closed, [ 'dp_toolbox_dashboard' ] ) );
} );

/* ------------------------------------------------------------------
 *  Render: één gecombineerde widget
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_render() {
    $show_welkom    = get_option( 'dp_toolbox_dashboard_welkom', true );
    $show_analytics = get_option( 'dp_toolbox_dashboard_analytics', true );
    $show_converter = get_option( 'dp_toolbox_dashboard_converter', true );
    $show_punch     = get_option( 'dp_toolbox_dashboard_punch_card', false );
    $show_forms     = get_option( 'dp_toolbox_dashboard_forms', true );

    // Wanneer welkom + analytics + IA actief zijn → welkom neemt stats + chart over,
    // analytics-sectie toont alleen de top-lijstjes.
    $ia_active    = $show_analytics && dp_toolbox_dashboard_ia_available();
    $merge_into_welkom = $show_welkom && $ia_active;
    $ia_data      = $ia_active ? dp_toolbox_dashboard_get_analytics_data() : null;

    if ( $show_welkom ) {
        dp_toolbox_dashboard_section_welkom( $merge_into_welkom ? $ia_data : null );
    }

    if ( $ia_active ) {
        dp_toolbox_dashboard_section_analytics( $merge_into_welkom );
    }

    // 3 kolommen onder welkom
    $has_columns = $show_forms || $show_converter || $show_punch;
    if ( $has_columns ) {
        echo '<div class="dp-dash-columns">';
        if ( $show_forms )     dp_toolbox_dashboard_section_forms();
        if ( $show_converter ) dp_toolbox_dashboard_section_converter();
        if ( $show_punch )     dp_toolbox_dashboard_section_punch();
        echo '</div>';
    }

    // Tutorials — altijd als laatste onderdeel van de widget
    if ( get_option( 'dp_toolbox_dashboard_tutorials', false ) ) {
        dp_toolbox_dashboard_section_tutorials();
    }
}

/* ------------------------------------------------------------------
 *  Helper: YouTube video-ID uit URL parsen
 *  Accepteert:
 *   - https://www.youtube.com/watch?v=VIDEO_ID
 *   - https://youtu.be/VIDEO_ID
 *   - https://www.youtube.com/embed/VIDEO_ID
 *   - https://www.youtube.com/shorts/VIDEO_ID
 *  Return: 11-char video-ID, of lege string bij ongeldig.
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_youtube_id( $url ) {
    $url = trim( (string) $url );
    if ( ! $url ) return '';

    // youtu.be/XXXXX
    if ( preg_match( '~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m ) ) {
        return $m[1];
    }
    // youtube.com/watch?v=XXXXX
    if ( preg_match( '~[?&]v=([A-Za-z0-9_-]{11})~', $url, $m ) ) {
        return $m[1];
    }
    // youtube.com/embed/XXXXX  of  /shorts/XXXXX
    if ( preg_match( '~youtube\.com/(?:embed|shorts)/([A-Za-z0-9_-]{11})~', $url, $m ) ) {
        return $m[1];
    }
    return '';
}

/* ------------------------------------------------------------------
 *  Helper: Is Independent Analytics actief?
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_ia_available() {
    global $wpdb;
    $table = $wpdb->prefix . 'independent_analytics_views';
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/* ------------------------------------------------------------------
 *  Data ophalen (gecached) voor Analytics-widget
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_get_analytics_data() {
    global $wpdb;

    $data = get_transient( 'dp_toolbox_analytics_data' );
    if ( false !== $data ) {
        return $data;
    }

    $since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

    $tv = $wpdb->prefix . 'independent_analytics_views';
    $ts = $wpdb->prefix . 'independent_analytics_sessions';
    $tr = $wpdb->prefix . 'independent_analytics_resources';
    $tf = $wpdb->prefix . 'independent_analytics_referrers';

    $totals = $wpdb->get_row( $wpdb->prepare( "
        SELECT
            (SELECT COUNT(*) FROM {$tv} WHERE viewed_at >= %s) AS views,
            (SELECT COUNT(*) FROM {$ts} WHERE created_at >= %s) AS sessions,
            (SELECT COUNT(DISTINCT visitor_id) FROM {$ts} WHERE created_at >= %s) AS visitors
    ", $since, $since, $since ), ARRAY_A );

    $chart_rows = $wpdb->get_results( $wpdb->prepare( "
        SELECT DATE(viewed_at) AS d, COUNT(*) AS n
        FROM {$tv}
        WHERE viewed_at >= %s
        GROUP BY DATE(viewed_at)
        ORDER BY d ASC
    ", $since ), ARRAY_A );

    // Vul hiaten in de grafiek (dagen zonder data)
    $chart = [];
    for ( $i = 6; $i >= 0; $i-- ) {
        $day = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
        $chart[ $day ] = 0;
    }
    foreach ( $chart_rows as $row ) {
        if ( isset( $chart[ $row['d'] ] ) ) {
            $chart[ $row['d'] ] = (int) $row['n'];
        }
    }

    $top_pages = $wpdb->get_results( $wpdb->prepare( "
        SELECT r.cached_title, r.cached_url, COUNT(*) AS n
        FROM {$tv} v
        JOIN {$tr} r ON v.resource_id = r.id
        WHERE v.viewed_at >= %s
        GROUP BY v.resource_id
        ORDER BY n DESC
        LIMIT 5
    ", $since ), ARRAY_A );

    $top_refs = $wpdb->get_results( $wpdb->prepare( "
        SELECT r.domain, COUNT(*) AS n
        FROM {$ts} s
        JOIN {$tf} r ON s.referrer_id = r.id
        WHERE s.created_at >= %s AND r.domain != ''
        GROUP BY r.id
        ORDER BY n DESC
        LIMIT 5
    ", $since ), ARRAY_A );

    $data = [
        'totals'    => $totals,
        'chart'     => $chart,
        'top_pages' => $top_pages,
        'top_refs'  => $top_refs,
    ];

    set_transient( 'dp_toolbox_analytics_data', $data, 5 * MINUTE_IN_SECONDS );
    return $data;
}

/* ------------------------------------------------------------------
 *  Render: Analytics chart-SVG (herbruikbaar in welkom én analytics)
 *  $opts: ['vh' => 200, 'light' => false]  — light=true voor donkere header
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_render_analytics_chart( $data, $opts = [] ) {
    $chart_values = array_values( $data['chart'] );
    $chart_max    = max( 1, max( $chart_values ) );
    $pts          = count( $chart_values );

    $vw    = 800;
    $vh    = $opts['vh'] ?? 200;
    $light = ! empty( $opts['light'] );
    $pad_l = 24; $pad_r = 24; $pad_t = 16; $pad_b = 28;
    $plot_w = $vw - $pad_l - $pad_r;
    $plot_h = $vh - $pad_t - $pad_b;
    $step   = $pts > 1 ? $plot_w / ( $pts - 1 ) : 0;

    $coords = [];
    foreach ( $chart_values as $i => $v ) {
        $x = $pad_l + $i * $step;
        $y = $pad_t + $plot_h - ( $v / $chart_max ) * $plot_h;
        $coords[] = [ 'x' => round( $x, 1 ), 'y' => round( $y, 1 ), 'v' => (int) $v ];
    }

    $line_pts = implode( ' ', array_map( function ( $c ) { return $c['x'] . ',' . $c['y']; }, $coords ) );
    $baseline_y = $pad_t + $plot_h;
    $last_x     = $pad_l + ( $pts - 1 ) * $step;
    $area_pts   = $line_pts . ' ' . round( $last_x, 1 ) . ',' . $baseline_y . ' ' . $pad_l . ',' . $baseline_y;

    $grid_ys = [ $pad_t, $pad_t + $plot_h * 0.5, $baseline_y ];

    $days_arr    = array_keys( $data['chart'] );
    $stroke      = $light ? '#c4b5fd' : '#281E5D';
    $grid_stroke = $light ? 'rgba(255,255,255,0.12)' : '#e8e5f0';
    $grad_color  = $light ? '#c4b5fd' : '#281E5D';
    $label_fill  = $light ? 'rgba(255,255,255,0.6)' : '#888';
    $dot_fill    = $light ? '#1a1235' : '#fff';
    $grad_id     = $light ? 'dp-dash-a-grad-light' : 'dp-dash-a-grad';

    ob_start();
    ?>
    <svg viewBox="0 0 <?php echo (int) $vw; ?> <?php echo (int) $vh; ?>"
         role="img" aria-label="Dagelijkse pageviews laatste 7 dagen">
        <defs>
            <linearGradient id="<?php echo esc_attr( $grad_id ); ?>" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="<?php echo esc_attr( $grad_color ); ?>" stop-opacity="0.22"/>
                <stop offset="100%" stop-color="<?php echo esc_attr( $grad_color ); ?>" stop-opacity="0"/>
            </linearGradient>
        </defs>

        <?php foreach ( $grid_ys as $gy ) : ?>
            <line x1="<?php echo (int) $pad_l; ?>" x2="<?php echo (int) ( $vw - $pad_r ); ?>"
                  y1="<?php echo esc_attr( round( $gy, 1 ) ); ?>"
                  y2="<?php echo esc_attr( round( $gy, 1 ) ); ?>"
                  stroke="<?php echo esc_attr( $grid_stroke ); ?>" stroke-width="1" stroke-dasharray="2 4"/>
        <?php endforeach; ?>

        <polygon points="<?php echo esc_attr( $area_pts ); ?>" fill="url(#<?php echo esc_attr( $grad_id ); ?>)"/>
        <polyline points="<?php echo esc_attr( $line_pts ); ?>"
                  fill="none" stroke="<?php echo esc_attr( $stroke ); ?>" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round"/>

        <?php foreach ( $coords as $i => $c ) : ?>
            <circle class="dp-dash-a-dot"
                    cx="<?php echo esc_attr( $c['x'] ); ?>"
                    cy="<?php echo esc_attr( $c['y'] ); ?>"
                    r="5" fill="<?php echo esc_attr( $dot_fill ); ?>"
                    stroke="<?php echo esc_attr( $stroke ); ?>" stroke-width="2.5">
                <title><?php echo esc_html( date_i18n( 'j M', strtotime( $days_arr[ $i ] ) ) . ': ' . $c['v'] . ' views' ); ?></title>
            </circle>
        <?php endforeach; ?>

        <?php foreach ( $coords as $i => $c ) :
            $day_txt = date_i18n( 'j M', strtotime( $days_arr[ $i ] ) );
        ?>
            <text x="<?php echo esc_attr( $c['x'] ); ?>" y="<?php echo (int) ( $vh - 8 ); ?>"
                  text-anchor="middle"
                  style="font-size:11px;font-weight:500;fill:<?php echo esc_attr( $label_fill ); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif"><?php echo esc_html( $day_txt ); ?></text>
        <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

/* ------------------------------------------------------------------
 *  Sectie: Analytics (Independent Analytics — laatste 7 dagen)
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_section_analytics( $lists_only = false ) {
    $data = dp_toolbox_dashboard_get_analytics_data();

    $views    = (int) ( $data['totals']['views'] ?? 0 );
    $visitors = (int) ( $data['totals']['visitors'] ?? 0 );
    $sessions = (int) ( $data['totals']['sessions'] ?? 0 );
    $has_data = $views > 0;
    ?>
    <div class="dp-dash-analytics <?php echo $lists_only ? 'is-lists-only' : ''; ?>">
        <?php if ( ! $lists_only ) : ?>
            <div class="dp-dash-analytics-header">
                <span class="dashicons dashicons-chart-line"></span>
                <span class="dp-dash-analytics-title">Analytics</span>
                <span class="dp-dash-analytics-period">laatste 7 dagen</span>
            </div>
        <?php endif; ?>

        <?php if ( ! $has_data ) : ?>
            <div class="dp-dash-analytics-empty">
                Nog geen bezoekersdata de afgelopen 7 dagen.
            </div>
        <?php else : ?>
            <?php if ( ! $lists_only ) : ?>
                <div class="dp-dash-analytics-top">
                    <div class="dp-dash-analytics-stats">
                        <div class="dp-dash-analytics-stat">
                            <span class="dp-dash-analytics-num"><?php echo esc_html( number_format_i18n( $views ) ); ?></span>
                            <span class="dp-dash-analytics-lbl">Pageviews</span>
                        </div>
                        <div class="dp-dash-analytics-stat">
                            <span class="dp-dash-analytics-num"><?php echo esc_html( number_format_i18n( $visitors ) ); ?></span>
                            <span class="dp-dash-analytics-lbl">Bezoekers</span>
                        </div>
                        <div class="dp-dash-analytics-stat">
                            <span class="dp-dash-analytics-num"><?php echo esc_html( number_format_i18n( $sessions ) ); ?></span>
                            <span class="dp-dash-analytics-lbl">Sessies</span>
                        </div>
                    </div>
                    <div class="dp-dash-analytics-chart">
                        <?php echo dp_toolbox_dashboard_render_analytics_chart( $data ); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dp-dash-analytics-lists">
                <div class="dp-dash-analytics-list">
                    <div class="dp-dash-analytics-list-title">Top pagina's</div>
                    <?php if ( empty( $data['top_pages'] ) ) : ?>
                        <div class="dp-dash-analytics-empty-small">—</div>
                    <?php else : ?>
                        <ol>
                            <?php foreach ( $data['top_pages'] as $row ) :
                                $title = $row['cached_title'] ?: $row['cached_url'] ?: '(onbekend)';
                                $url   = $row['cached_url'] ?: '#';
                            ?>
                                <li>
                                    <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="dp-dash-analytics-link">
                                        <?php echo esc_html( $title ); ?>
                                    </a>
                                    <span class="dp-dash-analytics-count"><?php echo esc_html( number_format_i18n( $row['n'] ) ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>

                <div class="dp-dash-analytics-list">
                    <div class="dp-dash-analytics-list-title">Top referrers</div>
                    <?php if ( empty( $data['top_refs'] ) ) : ?>
                        <div class="dp-dash-analytics-empty-small">—</div>
                    <?php else : ?>
                        <ol>
                            <?php foreach ( $data['top_refs'] as $row ) : ?>
                                <li>
                                    <span class="dp-dash-analytics-link"><?php echo esc_html( $row['domain'] ); ?></span>
                                    <span class="dp-dash-analytics-count"><?php echo esc_html( number_format_i18n( $row['n'] ) ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 *  Sectie: Welkom
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_section_welkom( $ia_data = null ) {
    $user      = wp_get_current_user();
    $site_name = get_bloginfo( 'name' );
    $hour      = (int) current_time( 'G' );
    $new_post  = admin_url( 'post-new.php' );
    $view_site = home_url( '/' );

    if ( $hour >= 6 && $hour < 12 ) {
        $greeting = 'Goedemorgen';
        $emoji    = '&#9728;&#65039;';
    } elseif ( $hour >= 12 && $hour < 18 ) {
        $greeting = 'Goedemiddag';
        $emoji    = '&#9728;&#65039;';
    } else {
        $greeting = 'Goedenavond';
        $emoji    = '&#127769;';
    }

    $with_ia = is_array( $ia_data );
    ?>
    <div class="dp-dash-welkom <?php echo $with_ia ? 'has-analytics' : ''; ?>">
        <div class="dp-dash-welkom-header">
            <div class="dp-dash-greeting">
                <div class="dp-dash-hello">
                    <span class="dp-dash-emoji"><?php echo $emoji; ?></span>
                    <span><?php echo esc_html( $greeting ); ?>, <?php echo esc_html( $user->display_name ); ?>!</span>
                </div>
                <div class="dp-dash-sub">
                    Welkom op het dashboard van <strong><?php echo esc_html( $site_name ); ?></strong>
                </div>
            </div>

            <?php if ( $with_ia ) :
                $views    = (int) ( $ia_data['totals']['views'] ?? 0 );
                $visitors = (int) ( $ia_data['totals']['visitors'] ?? 0 );
                $sessions = (int) ( $ia_data['totals']['sessions'] ?? 0 );
            ?>
                <div class="dp-dash-welkom-analytics">
                    <div class="dp-dash-welkom-stats dp-dash-welkom-stats-ia">
                        <div class="dp-dash-welkom-stat">
                            <span class="dp-dash-welkom-stat-num"><?php echo esc_html( number_format_i18n( $views ) ); ?></span>
                            <span class="dp-dash-welkom-stat-label">PAGEVIEWS</span>
                        </div>
                        <div class="dp-dash-welkom-stat">
                            <span class="dp-dash-welkom-stat-num"><?php echo esc_html( number_format_i18n( $visitors ) ); ?></span>
                            <span class="dp-dash-welkom-stat-label">BEZOEKERS</span>
                        </div>
                        <div class="dp-dash-welkom-stat">
                            <span class="dp-dash-welkom-stat-num"><?php echo esc_html( number_format_i18n( $sessions ) ); ?></span>
                            <span class="dp-dash-welkom-stat-label">SESSIES</span>
                        </div>
                    </div>
                    <div class="dp-dash-welkom-chart">
                        <?php echo dp_toolbox_dashboard_render_analytics_chart( $ia_data, [ 'vh' => 140, 'light' => true ] ); ?>
                    </div>
                </div>
            <?php else :
                $posts = wp_count_posts( 'post' )->publish;
                $pages = wp_count_posts( 'page' )->publish;
            ?>
                <div class="dp-dash-welkom-stats">
                    <div class="dp-dash-welkom-stat">
                        <span class="dp-dash-welkom-stat-num"><?php echo (int) $posts; ?></span>
                        <span class="dp-dash-welkom-stat-label">BERICHTEN</span>
                    </div>
                    <div class="dp-dash-welkom-stat">
                        <span class="dp-dash-welkom-stat-num"><?php echo (int) $pages; ?></span>
                        <span class="dp-dash-welkom-stat-label">PAGINA&rsquo;S</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="dp-dash-welkom-footer">
            <a href="<?php echo esc_url( $new_post ); ?>" class="dp-dash-btn dp-dash-btn-primary">+ Nieuw bericht</a>
            <a href="<?php echo esc_url( $view_site ); ?>" class="dp-dash-btn dp-dash-btn-secondary" target="_blank">Bekijk site &#8599;</a>
        </div>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 *  Sectie: Tutorials (YouTube-video's, onderaan widget)
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_section_tutorials() {
    $tut_urls  = (array) get_option( 'dp_toolbox_dashboard_tutorial_urls', [] );
    $video_ids = [];
    foreach ( $tut_urls as $url ) {
        $id = dp_toolbox_dashboard_youtube_id( $url );
        if ( $id ) $video_ids[] = $id;
    }
    $video_ids = array_slice( $video_ids, 0, 3 );
    if ( empty( $video_ids ) ) return;

    $count = count( $video_ids );
    ?>
    <div class="dp-dash-tutorials">
        <div class="dp-dash-tutorials-title">
            <span class="dashicons dashicons-video-alt3"></span>
            Tutorials
        </div>
        <div class="dp-dash-tutorials-grid" data-count="<?php echo (int) $count; ?>">
            <?php foreach ( $video_ids as $vid ) : ?>
                <div class="dp-dash-tutorial">
                    <iframe
                        src="https://www.youtube-nocookie.com/embed/<?php echo esc_attr( $vid ); ?>"
                        title="YouTube video"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen
                        loading="lazy"></iframe>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 *  Sectie: Formulier Inzendingen (Bit Form)
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_section_forms() {
    global $wpdb;

    // Check of Bit Form tabellen bestaan
    $table_entries = $wpdb->prefix . 'bitforms_form_entries';
    $table_meta    = $wpdb->prefix . 'bitforms_form_entrymeta';
    $table_forms   = $wpdb->prefix . 'bitforms_form';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_entries}'" ) !== $table_entries ) {
        return; // Bit Form niet geïnstalleerd
    }

    // Laatste gezien timestamp ophalen (per user)
    $user_id   = get_current_user_id();
    $last_seen = get_user_meta( $user_id, 'dp_toolbox_forms_last_seen', true );
    $last_seen = $last_seen ? $last_seen : '1970-01-01 00:00:00';

    // 5 meest recente inzendingen
    $entries = $wpdb->get_results( $wpdb->prepare( "
        SELECT e.id, e.form_id, e.created_at, e.user_ip,
               f.form_name
        FROM {$table_entries} e
        LEFT JOIN {$table_forms} f ON e.form_id = f.id
        ORDER BY e.created_at DESC
        LIMIT 5
    " ) );

    // Tel nieuwe inzendingen (sinds laatste keer gezien)
    $new_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_entries} WHERE created_at > %s",
        $last_seen
    ) );

    // Update last_seen timestamp
    update_user_meta( $user_id, 'dp_toolbox_forms_last_seen', current_time( 'mysql' ) );

    // Haal naam + email op per entry via entrymeta
    $entry_data = [];
    if ( $entries ) {
        $entry_ids = wp_list_pluck( $entries, 'id' );
        $ids_str   = implode( ',', array_map( 'intval', $entry_ids ) );

        // Haal form_content op voor veld-labels
        $form_ids   = array_unique( wp_list_pluck( $entries, 'form_id' ) );
        $form_fields = [];
        foreach ( $form_ids as $fid ) {
            $content = $wpdb->get_var( $wpdb->prepare(
                "SELECT form_content FROM {$table_forms} WHERE id = %d", $fid
            ) );
            if ( $content ) {
                $decoded = json_decode( $content, true );
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $key => $field ) {
                        $lbl = $field['lbl'] ?? '';
                        $typ = $field['typ'] ?? '';
                        $form_fields[ $fid ][ $key ] = [ 'label' => $lbl, 'type' => $typ ];
                    }
                }
            }
        }

        // Haal meta-waarden op
        $metas = $wpdb->get_results( "
            SELECT bitforms_form_entry_id, meta_key, meta_value
            FROM {$table_meta}
            WHERE bitforms_form_entry_id IN ({$ids_str})
        " );

        foreach ( $metas as $m ) {
            $entry_data[ $m->bitforms_form_entry_id ][ $m->meta_key ] = $m->meta_value;
        }
    }

    ?>
    <div class="dp-dash-section dp-dash-forms-section">
        <div class="dp-dash-section-title">
            <span class="dashicons dashicons-email-alt"></span>
            Inzendingen
            <?php if ( $new_count > 0 ) : ?>
                <span class="dp-dash-forms-badge"><?php echo (int) $new_count; ?></span>
            <?php endif; ?>
        </div>

        <?php if ( empty( $entries ) ) : ?>
            <div class="dp-dash-forms-empty">Nog geen formulier-inzendingen ontvangen.</div>
        <?php else : ?>
            <div class="dp-dash-forms-list">
                <?php foreach ( $entries as $entry ) :
                    $is_new  = $entry->created_at > $last_seen;
                    $fields  = $form_fields[ $entry->form_id ] ?? [];
                    $data    = $entry_data[ $entry->id ] ?? [];
                    $name    = '';
                    $email   = '';

                    // Zoek naam en email in de velddata
                    foreach ( $fields as $key => $info ) {
                        $val = $data[ $key ] ?? '';
                        if ( ! $val ) continue;
                        $lbl = strtolower( $info['label'] );
                        $typ = $info['type'] ?? '';
                        if ( ! $name && ( strpos( $lbl, 'naam' ) !== false || strpos( $lbl, 'name' ) !== false ) ) {
                            $name = $val;
                        }
                        if ( ! $email && ( $typ === 'email' || strpos( $lbl, 'mail' ) !== false ) ) {
                            $email = $val;
                        }
                    }

                    $display = $name ?: $email ?: 'Anoniem';
                    $time    = human_time_diff( strtotime( $entry->created_at ), current_time( 'timestamp' ) );
                    $form    = $entry->form_name ?: 'Formulier #' . $entry->form_id;
                ?>
                    <div class="dp-dash-forms-item <?php echo $is_new ? 'dp-dash-forms-new' : ''; ?>">
                        <div class="dp-dash-forms-avatar">
                            <?php echo esc_html( mb_strtoupper( mb_substr( $display, 0, 1 ) ) ); ?>
                        </div>
                        <div class="dp-dash-forms-info">
                            <div class="dp-dash-forms-name">
                                <?php echo esc_html( $display ); ?>
                                <?php if ( $is_new ) : ?><span class="dp-dash-forms-new-dot"></span><?php endif; ?>
                            </div>
                            <div class="dp-dash-forms-meta">
                                <?php echo esc_html( $form ); ?> &middot; <?php echo esc_html( $time ); ?> geleden
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 *  Sectie: Image Converter
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_section_converter() {
    ?>
    <div class="dp-dash-section dp-dash-converter-card">
        <div class="dp-dash-section-title">Image Converter</div>
        <div class="dp-dash-converter-hero">
            <div class="dp-dash-converter-icons">
                <span class="dp-dash-converter-format">JPG</span>
                <span class="dp-dash-converter-arrow">&#8594;</span>
                <span class="dp-dash-converter-format dp-dash-converter-format-hl">WebP</span>
            </div>
        </div>
        <div class="dp-dash-converter-body">
            <strong>Optimaliseer je afbeeldingen</strong>
            <p>Converteer naar WebP, PNG of JPG. Gratis, snel en zonder account.</p>
        </div>
        <a href="https://convert.designpixels.nl" target="_blank" rel="noopener" class="dp-dash-converter-cta">
            <span>Probeer nu</span>
            <span class="dp-dash-converter-cta-arrow">&#8599;</span>
        </a>
    </div>
    <?php
}

/* ------------------------------------------------------------------
 *  Sectie: Punch Card / Strippen
 * ------------------------------------------------------------------ */
function dp_toolbox_dashboard_section_punch() {
    $api_key = get_option( 'dp_toolbox_dashboard_api_key', '' );

    echo '<div class="dp-dash-section">';
    echo '<div class="dp-dash-section-title">Strippen</div>';

    if ( empty( $api_key ) ) {
        $url = admin_url( 'admin.php?page=dp-toolbox-dashboard-widgets' );
        echo '<div class="dp-dash-punch-notice">';
        echo '<span class="dashicons dashicons-info"></span> ';
        echo 'Configureer je API-key in de <a href="' . esc_url( $url ) . '">instellingen</a>.';
        echo '</div></div>';
        return;
    }

    // Check transient cache
    $data = get_transient( 'dp_toolbox_punch_card_data' );

    if ( false === $data ) {
        $response = wp_remote_get( DP_TOOLBOX_PUNCH_CARD_API_URL, [
            'headers' => [ 'x-api-key' => $api_key ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            echo '<div class="dp-dash-punch-notice dp-dash-punch-error">';
            echo '<span class="dashicons dashicons-warning"></span> Kan geen verbinding maken met de API.';
            echo '</div></div>';
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            echo '<div class="dp-dash-punch-notice dp-dash-punch-error">';
            echo '<span class="dashicons dashicons-warning"></span> API fout (HTTP ' . (int) $code . '). Controleer je API-key.';
            echo '</div></div>';
            return;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || ! isset( $data['strips_remaining'] ) ) {
            echo '<div class="dp-dash-punch-notice dp-dash-punch-error">';
            echo '<span class="dashicons dashicons-warning"></span> Ongeldig API-antwoord ontvangen.';
            echo '</div></div>';
            return;
        }

        set_transient( 'dp_toolbox_punch_card_data', $data, 15 * MINUTE_IN_SECONDS );
    }

    $resterend = (int) ( $data['strips_remaining'] ?? 0 );
    $minutes   = isset( $data['minutes_remaining'] ) ? (int) $data['minutes_remaining'] : null;
    $cards     = isset( $data['active_cards'] ) ? (int) $data['active_cards'] : null;
    $project   = $data['project'] ?? '';
    ?>
    <div class="dp-dash-punch">
        <div class="dp-dash-punch-ring">
            <svg viewBox="0 0 80 80" class="dp-dash-punch-svg">
                <circle cx="40" cy="40" r="34" fill="none" stroke="#e8e5f0" stroke-width="6"/>
                <circle cx="40" cy="40" r="34" fill="none" stroke="url(#dp-punch-grad)" stroke-width="6"
                    stroke-linecap="round" stroke-dasharray="213.6" stroke-dashoffset="213.6"
                    style="stroke-dashoffset: <?php echo 213.6; ?>; transition: stroke-dashoffset 1s ease;" />
                <defs><linearGradient id="dp-punch-grad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#281E5D"/><stop offset="100%" stop-color="#7c3aed"/>
                </linearGradient></defs>
            </svg>
            <div class="dp-dash-punch-ring-val"><?php echo $resterend; ?></div>
        </div>
        <div class="dp-dash-punch-details">
            <div class="dp-dash-punch-label">strippen beschikbaar</div>
            <div class="dp-dash-punch-meta-list">
                <?php if ( $minutes !== null ) : ?>
                    <div class="dp-dash-punch-meta-item">
                        <span class="dashicons dashicons-clock"></span>
                        <span><?php echo $minutes; ?> min resterend</span>
                    </div>
                <?php endif; ?>
                <?php if ( $cards !== null ) : ?>
                    <div class="dp-dash-punch-meta-item">
                        <span class="dashicons dashicons-tickets-alt"></span>
                        <span><?php echo $cards; ?> actieve kaart<?php echo $cards !== 1 ? 'en' : ''; ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var c = document.querySelector('.dp-dash-punch-svg circle:nth-child(2)');
        if(c){
            var remaining = <?php echo $resterend; ?>;
            var total = <?php echo max( 1, ( $cards ?? 1 ) * 12 ); ?>;
            var pct = Math.min(remaining / total, 1);
            setTimeout(function(){ c.style.strokeDashoffset = 213.6 * (1 - pct); }, 100);
        }
    })();
    </script>
    <a href="https://portal.designpixels.nl" target="_blank" rel="noopener" class="dp-dash-punch-portal">
        <span class="dashicons dashicons-external"></span>
        <span>Mijn klantportaal</span>
        <span class="dp-dash-punch-portal-arrow">&#8599;</span>
    </a>
    <?php
    echo '</div>';
}

// Clear transient when API key changes
add_action( 'update_option_dp_toolbox_dashboard_api_key', function () {
    delete_transient( 'dp_toolbox_punch_card_data' );
} );

/* ------------------------------------------------------------------
 *  Dashboard CSS
 * ------------------------------------------------------------------ */
add_action( 'admin_head-index.php', function () {
    ?>
    <style>
        /* ---- Widget container: full width, geen sprong ---- */
        #dp_toolbox_dashboard { width: 100% !important; max-width: 100% !important; }
        #dp_toolbox_dashboard .inside { padding: 0 !important; margin: 0 !important; }
        #dp_toolbox_dashboard .postbox-header { display: none !important; }
        #dp_toolbox_dashboard.postbox { border: none !important; box-shadow: 0 2px 12px rgba(40,30,93,0.12); border-radius: 12px !important; overflow: hidden; }

        /* Force full width: override WP dashboard column layout */
        #dashboard-widgets-wrap #dashboard-widgets .postbox-container { float: none !important; width: 100% !important; }
        #dashboard-widgets-wrap #dashboard-widgets #normal-sortables { display: flex; flex-wrap: wrap; }
        #dashboard-widgets-wrap #dashboard-widgets #normal-sortables #dp_toolbox_dashboard { flex: 0 0 100% !important; order: -1; width: 100% !important; }

        /* ---- Welkom banner ---- */
        .dp-dash-welkom { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .dp-dash-welkom-header {
            background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%);
            color: #fff; padding: 28px 32px; display: flex; align-items: center; justify-content: space-between; gap: 24px;
        }
        .dp-dash-hello { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 700; margin-bottom: 6px; }
        .dp-dash-emoji { font-size: 28px; }
        .dp-dash-sub { font-size: 14px; opacity: 0.8; }
        .dp-dash-sub strong { color: #c4b5fd; }
        .dp-dash-welkom-stats { display: flex; gap: 12px; flex-shrink: 0; }
        .dp-dash-welkom-stat { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); border-radius: 10px; padding: 14px 24px; text-align: center; min-width: 100px; }
        .dp-dash-welkom-stat-num { display: block; font-size: 28px; font-weight: 700; color: #c4b5fd; line-height: 1; margin-bottom: 4px; }
        .dp-dash-welkom-stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.7; }

        /* ---- Welkom + Analytics variant ---- */
        .dp-dash-welkom.has-analytics .dp-dash-welkom-header {
            align-items: stretch;
        }
        .dp-dash-welkom-analytics {
            display: flex; flex-direction: column; gap: 10px;
            flex-shrink: 0; min-width: 420px; max-width: 55%;
        }
        .dp-dash-welkom-stats-ia {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
        }
        .dp-dash-welkom-stats-ia .dp-dash-welkom-stat {
            padding: 10px 16px; min-width: 0;
        }
        .dp-dash-welkom-stats-ia .dp-dash-welkom-stat-num { font-size: 22px; }
        .dp-dash-welkom-stats-ia .dp-dash-welkom-stat-label { font-size: 10px; letter-spacing: 0.8px; }
        .dp-dash-welkom-chart {
            background: rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px; padding: 4px 8px;
        }
        .dp-dash-welkom-chart svg { width: 100%; height: auto; display: block; max-height: 120px; }

        .dp-dash-welkom-footer { background: #f8f7fc; padding: 14px 32px; display: flex; align-items: center; gap: 10px; }

        /* ---- Tutorials footer-balk (laatste sectie van de widget) ---- */
        .dp-dash-tutorials {
            background: #f0edf8;
            border-top: 1px solid #e0dcec;
            padding: 22px 32px 24px;
            margin-top: -4px;
        }
        .dp-dash-tutorials-title {
            display: flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700; color: #281E5D;
            text-transform: uppercase; letter-spacing: 0.8px;
            margin-bottom: 12px;
        }
        .dp-dash-tutorials-title .dashicons {
            font-size: 14px; width: 14px; height: 14px; color: #281E5D;
        }
        .dp-dash-tutorials-grid {
            display: grid; gap: 14px;
        }
        .dp-dash-tutorials-grid[data-count="1"] { grid-template-columns: 1fr; }
        .dp-dash-tutorials-grid[data-count="2"] { grid-template-columns: repeat(2, 1fr); }
        .dp-dash-tutorials-grid[data-count="3"] { grid-template-columns: repeat(3, 1fr); }
        .dp-dash-tutorial {
            position: relative;
            aspect-ratio: 16 / 9;
            background: #1d2327;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(40,30,93,0.12);
        }
        .dp-dash-tutorial iframe {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            border: 0;
        }
        @media (max-width: 960px) {
            .dp-dash-tutorials-grid[data-count="2"],
            .dp-dash-tutorials-grid[data-count="3"] { grid-template-columns: 1fr; }
        }

        /* ---- Buttons ---- */
        .dp-dash-btn { display: inline-flex; align-items: center; padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.2s; }
        .dp-dash-btn-primary { background: #281E5D; color: #fff; }
        .dp-dash-btn-primary:hover { background: #4a3a8a; color: #fff; }
        .dp-dash-btn-secondary { background: #fff; color: #281E5D; border: 1px solid #ddd; }
        .dp-dash-btn-secondary:hover { border-color: #281E5D; color: #281E5D; }
        .dp-dash-btn-sm { padding: 5px 12px; font-size: 12px; }

        /* ---- Kolommen grid: auto-fit zodat 2 of 3 kolommen altijd gelijk zijn ---- */
        .dp-dash-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            padding: 20px 28px 24px;
        }

        /* ---- Sectie-kaart: eigen border, gelijke hoogte ---- */
        .dp-dash-section {
            background: #f9f8fc;
            border: 1px solid #e4e0ef;
            border-radius: 10px;
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            min-height: 180px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-dash-section:hover {
            border-color: #c4b5fd;
            box-shadow: 0 2px 8px rgba(40,30,93,0.06);
        }
        .dp-dash-section-title {
            font-size: 11px; font-weight: 700; color: #281E5D;
            text-transform: uppercase; letter-spacing: 0.8px;
            margin-bottom: 16px; padding-bottom: 10px;
            border-bottom: 1px solid #e8e5f0;
        }

        /* ---- Formulier Inzendingen ---- */
        .dp-dash-forms-section .dp-dash-section-title { display: flex; align-items: center; gap: 6px; }
        .dp-dash-forms-section .dp-dash-section-title .dashicons { font-size: 15px; width: 15px; height: 15px; color: #281E5D; }
        .dp-dash-forms-badge { background: #d63638; color: #fff; font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; line-height: 18px; text-align: center; border-radius: 9px; padding: 0 5px; margin-left: 2px; }
        .dp-dash-forms-empty { color: #888; font-size: 13px; }
        .dp-dash-forms-list { display: flex; flex-direction: column; }
        .dp-dash-forms-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eee; }
        .dp-dash-forms-item:last-child { border-bottom: none; padding-bottom: 0; }
        .dp-dash-forms-item:first-child { padding-top: 0; }
        .dp-dash-forms-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, #e8e5f0, #d8d3e8);
            color: #281E5D; font-weight: 700; font-size: 13px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .dp-dash-forms-new .dp-dash-forms-avatar { background: linear-gradient(135deg, #281E5D, #4a3a8a); color: #fff; }
        .dp-dash-forms-info { flex: 1; min-width: 0; }
        .dp-dash-forms-name { font-size: 13px; font-weight: 600; color: #1d2327; display: flex; align-items: center; gap: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dp-dash-forms-new-dot { width: 7px; height: 7px; border-radius: 50%; background: #d63638; flex-shrink: 0; }
        .dp-dash-forms-meta { font-size: 11px; color: #999; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* ---- Converter ---- */
        .dp-dash-converter-card { align-items: center; text-align: center; }
        .dp-dash-converter-hero {
            background: linear-gradient(135deg, #1a1235 0%, #281E5D 50%, #3d2d7a 100%);
            border-radius: 8px; padding: 16px 24px; margin-bottom: 14px; width: 100%;
        }
        .dp-dash-converter-icons { display: flex; align-items: center; justify-content: center; gap: 12px; }
        .dp-dash-converter-format {
            background: rgba(255,255,255,0.12); color: #c4b5fd;
            padding: 6px 14px; border-radius: 6px;
            font-size: 13px; font-weight: 700; letter-spacing: 0.5px;
            border: 1px solid rgba(255,255,255,0.15);
        }
        .dp-dash-converter-format-hl { background: rgba(124,58,237,0.3); color: #fff; border-color: rgba(124,58,237,0.5); }
        .dp-dash-converter-arrow { color: #c4b5fd; font-size: 18px; }
        .dp-dash-converter-body { margin-bottom: 14px; }
        .dp-dash-converter-body strong { font-size: 13px; color: #1d2327; }
        .dp-dash-converter-body p { margin: 4px 0 0; color: #888; font-size: 12px; line-height: 1.5; }
        .dp-dash-converter-cta {
            display: inline-flex; align-items: center; gap: 6px;
            background: linear-gradient(135deg, #281E5D, #4a3a8a); color: #fff;
            padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 600;
            text-decoration: none; transition: all 0.2s; margin-top: auto;
        }
        .dp-dash-converter-cta:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(40,30,93,0.25); color: #fff; }
        .dp-dash-converter-cta-arrow { font-size: 15px; }

        /* ---- Punch Card / Strippen ---- */
        .dp-dash-punch-notice { padding: 4px 0; color: #666; font-size: 13px; }
        .dp-dash-punch-notice .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 4px; }
        .dp-dash-punch-notice a { color: #281E5D; }
        .dp-dash-punch-error { color: #d63638; }
        .dp-dash-punch { display: flex; align-items: center; gap: 20px; flex: 1; }
        .dp-dash-punch-ring { position: relative; width: 80px; height: 80px; flex-shrink: 0; }
        .dp-dash-punch-svg { width: 80px; height: 80px; transform: rotate(-90deg); }
        .dp-dash-punch-ring-val {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 700; color: #281E5D;
        }
        .dp-dash-punch-details { flex: 1; }
        .dp-dash-punch-label { font-size: 13px; font-weight: 600; color: #1d2327; margin-bottom: 10px; }
        .dp-dash-punch-meta-list { display: flex; flex-direction: column; gap: 6px; }
        .dp-dash-punch-meta-item {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #888;
            background: #f0edf8; padding: 5px 10px; border-radius: 6px;
        }
        .dp-dash-punch-meta-item .dashicons { font-size: 14px; width: 14px; height: 14px; color: #281E5D; }
        .dp-dash-punch-portal {
            display: inline-flex; align-items: center; gap: 6px;
            background: linear-gradient(135deg, #281E5D, #4a3a8a); color: #fff;
            padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 600;
            text-decoration: none; transition: all 0.2s; margin-top: 16px; align-self: flex-start;
        }
        .dp-dash-punch-portal:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(40,30,93,0.25); color: #fff; }
        .dp-dash-punch-portal .dashicons { font-size: 14px; width: 14px; height: 14px; }
        .dp-dash-punch-portal-arrow { font-size: 15px; }

        /* ---- Analytics (full-width rij onder welkom) ---- */
        .dp-dash-analytics { padding: 20px 28px 4px; }
        .dp-dash-analytics.is-lists-only { padding: 18px 28px 0; }
        .dp-dash-analytics-header {
            display: flex; align-items: baseline; gap: 8px;
            margin-bottom: 14px; padding-bottom: 10px;
            border-bottom: 1px solid #e8e5f0;
        }
        .dp-dash-analytics-header .dashicons {
            font-size: 16px; width: 16px; height: 16px; color: #281E5D;
            align-self: center;
        }
        .dp-dash-analytics-title {
            font-size: 11px; font-weight: 700; color: #281E5D;
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        .dp-dash-analytics-period {
            font-size: 11px; color: #999; margin-left: auto;
        }
        .dp-dash-analytics-empty {
            padding: 20px 0; color: #888; font-size: 13px; text-align: center;
        }
        .dp-dash-analytics-empty-small { color: #bbb; font-size: 12px; padding: 4px 0; }

        .dp-dash-analytics-top {
            display: grid; grid-template-columns: auto 1fr;
            gap: 24px; align-items: center; margin-bottom: 18px;
        }
        .dp-dash-analytics-stats {
            display: flex; gap: 10px; flex-shrink: 0;
        }
        .dp-dash-analytics-stat {
            background: #f9f8fc; border: 1px solid #e4e0ef; border-radius: 8px;
            padding: 10px 16px; min-width: 80px; text-align: center;
        }
        .dp-dash-analytics-num {
            display: block; font-size: 22px; font-weight: 700;
            color: #281E5D; line-height: 1; margin-bottom: 4px;
        }
        .dp-dash-analytics-lbl {
            font-size: 10px; text-transform: uppercase;
            letter-spacing: 0.5px; color: #888; font-weight: 600;
        }

        .dp-dash-analytics-chart { position: relative; min-width: 0; }
        .dp-dash-analytics-chart svg {
            width: 100%; height: auto; display: block;
            max-height: 180px;
        }
        .dp-dash-a-dot {
            cursor: pointer;
            transition: r 0.15s ease, stroke-width 0.15s ease;
        }
        .dp-dash-a-dot:hover { r: 7; stroke-width: 3; }
        .dp-dash-a-day {
            font-size: 11px; fill: #888; font-weight: 500;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .dp-dash-analytics-lists {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 18px; padding-bottom: 4px;
        }
        .dp-dash-analytics-list {
            background: #f9f8fc; border: 1px solid #e4e0ef; border-radius: 8px;
            padding: 14px 16px;
        }
        .dp-dash-analytics-list-title {
            font-size: 11px; font-weight: 700; color: #281E5D;
            text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 8px; padding-bottom: 6px;
            border-bottom: 1px solid #e8e5f0;
        }
        .dp-dash-analytics-list ol {
            margin: 0; padding: 0; list-style: none;
            counter-reset: dp-a;
        }
        .dp-dash-analytics-list li {
            counter-increment: dp-a;
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; padding: 5px 0;
            border-bottom: 1px solid #efecf6;
        }
        .dp-dash-analytics-list li:last-child { border-bottom: none; }
        .dp-dash-analytics-list li::before {
            content: counter(dp-a) '.';
            font-weight: 700; color: #bbb; min-width: 14px;
        }
        .dp-dash-analytics-link {
            flex: 1; min-width: 0; color: #1d2327;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            text-decoration: none;
        }
        a.dp-dash-analytics-link:hover { color: #281E5D; }
        .dp-dash-analytics-count {
            color: #281E5D; font-weight: 600;
            background: #eee8ff; padding: 2px 8px; border-radius: 10px;
        }

        /* ---- Responsive ---- */
        @media (max-width: 960px) {
            .dp-dash-welkom-header { flex-direction: column; align-items: flex-start; }
            .dp-dash-columns { grid-template-columns: 1fr; padding: 16px 20px 20px; }
            .dp-dash-section { min-height: auto; }
            .dp-dash-analytics-top { grid-template-columns: 1fr; }
            .dp-dash-analytics-stats { flex-wrap: wrap; }
            .dp-dash-analytics-lists { grid-template-columns: 1fr; }
        }
    </style>
    <?php
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
