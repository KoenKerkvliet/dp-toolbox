<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    $group = 'dp_toolbox_dashboard_settings';

    register_setting( $group, 'dp_toolbox_dashboard_welkom', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_stats', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_converter', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_punch_card', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => false,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_forms', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_analytics', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_analytics_lists', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => false,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_hide_defaults', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    register_setting( $group, 'dp_toolbox_dashboard_api_key', [
        'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '',
    ] );

    // Tutorials
    register_setting( $group, 'dp_toolbox_dashboard_tutorials', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => false,
    ] );
    // Visitekaartje
    register_setting( $group, 'dp_toolbox_dashboard_businesscard', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    // Review-kaart (master switch — verschijnt op elke site na update)
    register_setting( $group, 'dp_toolbox_dashboard_review', [
        'type' => 'boolean', 'sanitize_callback' => function ( $v ) { return (bool) $v; }, 'default' => true,
    ] );
    // (Per-user review-given state leeft in user_meta dp_toolbox_review_given — geen site-option meer.)
    register_setting( $group, 'dp_toolbox_dashboard_tutorial_urls', [
        'type'              => 'array',
        'sanitize_callback' => function ( $input ) {
            if ( ! is_array( $input ) ) return [];
            $out = [];
            foreach ( $input as $url ) {
                $url = esc_url_raw( trim( (string) $url ) );
                if ( $url && dp_toolbox_dashboard_youtube_id( $url ) ) {
                    $out[] = $url;
                }
            }
            return array_slice( $out, 0, 3 );
        },
        'default' => [],
    ] );
} );

/**
 * Register inline settings on Modules tab (vervangt vroegere add_submenu_page).
 */
add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'dashboard-widgets', 'dp_toolbox_dashboard_render_inline', [
            'title'       => 'Dashboard Widgets',
            'description' => 'Beheer welke widgets op het WordPress-dashboard worden getoond.',
        ] );
    }
} );

function dp_toolbox_dashboard_render_inline() {
    $welkom            = get_option( 'dp_toolbox_dashboard_welkom', true );
    $analytics         = get_option( 'dp_toolbox_dashboard_analytics', true );
    $analytics_lists   = get_option( 'dp_toolbox_dashboard_analytics_lists', false );
    $forms        = get_option( 'dp_toolbox_dashboard_forms', true );
    $converter    = get_option( 'dp_toolbox_dashboard_converter', true );
    $punch_card   = get_option( 'dp_toolbox_dashboard_punch_card', false );
    $hide_wp      = get_option( 'dp_toolbox_dashboard_hide_defaults', true );
    $api_key      = get_option( 'dp_toolbox_dashboard_api_key', '' );
    $tutorials    = get_option( 'dp_toolbox_dashboard_tutorials', false );
    $tut_urls     = (array) get_option( 'dp_toolbox_dashboard_tutorial_urls', [] );
    $tut_urls     = array_pad( $tut_urls, 3, '' );
    $businesscard = get_option( 'dp_toolbox_dashboard_businesscard', true );
    $review       = get_option( 'dp_toolbox_dashboard_review', true );
    $ia_available = function_exists( 'dp_toolbox_dashboard_ia_available' ) && dp_toolbox_dashboard_ia_available();
    $pi_url       = admin_url( 'admin.php?page=dp-toolbox#settings-plugin-installer' );
    ?>
    <style>
        .dp-dw-cards { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
        .dp-dw-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 16px 20px; display: flex; align-items: center;
            justify-content: space-between; transition: border-color 0.2s, box-shadow 0.2s;
        }
        .dp-dw-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-dw-info { flex: 1; }
        .dp-dw-title { font-size: 14px; font-weight: 600; color: #1d2327; margin-bottom: 2px; }
        .dp-dw-desc { font-size: 12px; color: #888; }
        .dp-dw-toggle input[type="checkbox"] { display: none; }
        .dp-dw-toggle label {
            display: block; width: 42px; height: 22px; background: #ccc;
            border-radius: 11px; position: relative; cursor: pointer;
            transition: background 0.2s; flex-shrink: 0;
        }
        .dp-dw-toggle label::after {
            content: ''; position: absolute; top: 3px; left: 3px;
            width: 16px; height: 16px; background: #fff; border-radius: 50%;
            transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .dp-dw-toggle input:checked + label { background: #281E5D; }
        .dp-dw-toggle input:checked + label::after { transform: translateX(20px); }
        .dp-dw-card.is-disabled { opacity: 0.65; }
        .dp-dw-card.is-disabled:hover { border-color: #e0e0e0; box-shadow: none; }
        .dp-dw-toggle.is-disabled label { background: #e0e0e0; cursor: not-allowed; }
        .dp-dw-hint {
            margin-top: 6px; font-size: 11px; color: #c48a00;
            display: flex; align-items: center; gap: 4px;
        }
        .dp-dw-hint .dashicons { font-size: 14px; width: 14px; height: 14px; }
        .dp-dw-hint a { color: #281E5D; font-weight: 600; }
        .dp-dw-section { margin-top: 28px; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #1d2327; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0; }
        .dp-dw-section .dashicons { color: #281E5D; font-size: 18px; width: 18px; height: 18px; }
        .dp-dw-api-section { margin-top: 16px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; }
        .dp-dw-api-section label { display: block; font-size: 13px; font-weight: 600; color: #1d2327; margin-bottom: 6px; }
        .dp-dw-api-section input[type="text"] { width: 100%; max-width: 500px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: monospace; }
        .dp-dw-api-section input[type="text"]:focus { border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.12); }
        .dp-dw-api-help { font-size: 12px; color: #888; margin-top: 6px; }
        .dp-dw-btn {
            margin-top: 24px; background: #281E5D; color: #fff; border: none;
            border-radius: 6px; padding: 8px 24px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background 0.2s;
        }
        .dp-dw-btn:hover { background: #4a3a8a; }

        /* Review status per user */
        .dp-dw-user-role {
            display: inline-block; margin-left: 8px;
            padding: 2px 8px; background: #f5f3fb; color: #281E5D;
            font-size: 10px; font-weight: 600;
            border-radius: 10px; text-transform: uppercase; letter-spacing: 0.5px;
            vertical-align: middle;
        }
        #dp-dw-review-users { gap: 8px; margin-top: 0; }
        .dp-dw-review-user-card { padding: 12px 18px; }
        .dp-dw-review-user-card .dp-dw-title { font-size: 13px; }
        .dp-dw-review-user-card .dp-dw-desc { font-size: 11px; }
    </style>

    <form method="post" action="options.php">
        <?php settings_fields( 'dp_toolbox_dashboard_settings' ); ?>

        <!-- Sectie 1: Dashboard Widgets -->
        <div class="dp-dw-section">
            <span class="dashicons dashicons-dashboard"></span> Dashboard Widgets
        </div>

        <div class="dp-dw-cards">
            <?php
            $widgets = [
                [ 'id' => 'welkom',     'name' => 'dp_toolbox_dashboard_welkom',     'val' => $welkom,     'title' => 'Welkom',            'desc' => 'Persoonlijke begroeting met berichten- en paginateller.' ],
                [ 'id' => 'analytics',  'name' => 'dp_toolbox_dashboard_analytics',  'val' => $analytics,  'title' => 'Analytics',         'desc' => 'Bezoekers, pageviews en grafiek van de laatste 7 dagen (via DP Analytics).', 'requires_ia' => true ],
                [ 'id' => 'analytics_lists', 'name' => 'dp_toolbox_dashboard_analytics_lists', 'val' => $analytics_lists, 'title' => 'Analytics — top lijsten', 'desc' => 'Toon onderaan ook de top pagina\'s en top referrers van de laatste 7 dagen.', 'requires_analytics' => true ],
                [ 'id' => 'forms',      'name' => 'dp_toolbox_dashboard_forms',      'val' => $forms,      'title' => 'Inzendingen',       'desc' => 'Recente formulier-inzendingen via Bit Form.' ],
                [ 'id' => 'converter',  'name' => 'dp_toolbox_dashboard_converter',  'val' => $converter,  'title' => 'Image Converter',   'desc' => 'Promotie voor de gratis tool convert.designpixels.nl.' ],
                [ 'id' => 'tutorials',  'name' => 'dp_toolbox_dashboard_tutorials',  'val' => $tutorials,  'title' => 'Tutorials',         'desc' => 'Toon 1 tot 3 YouTube tutorial-video\'s onderaan de welkom-widget.' ],
                [ 'id' => 'punch_card', 'name' => 'dp_toolbox_dashboard_punch_card', 'val' => $punch_card, 'title' => 'Strippen',          'desc' => 'Toon het aantal beschikbare strippen via de API.' ],
                [ 'id' => 'review',     'name' => 'dp_toolbox_dashboard_review',     'val' => $review,     'title' => 'Review aanvragen',  'desc' => 'Vraag elke gebruiker om een review op feedback.designpixels.nl. Per user te verbergen in de "Review status per user" sectie hieronder.' ],
                [ 'id' => 'businesscard','name'=> 'dp_toolbox_dashboard_businesscard','val'=> $businesscard,'title'=> 'Visitekaartje',      'desc' => 'Toon een klein Design Pixels visitekaartje onderaan het dashboard.' ],
            ];

            foreach ( $widgets as $w ) :
                $needs_ia        = ! empty( $w['requires_ia'] ) && ! $ia_available;
                $needs_analytics = ! empty( $w['requires_analytics'] ) && ( ! $analytics || ! $ia_available );
                $is_disabled     = $needs_ia || $needs_analytics;
                $card_cls        = 'dp-dw-card' . ( $is_disabled ? ' is-disabled' : '' );
            ?>
                <div class="<?php echo esc_attr( $card_cls ); ?>">
                    <div class="dp-dw-info">
                        <div class="dp-dw-title"><?php echo esc_html( $w['title'] ); ?></div>
                        <div class="dp-dw-desc"><?php echo esc_html( $w['desc'] ); ?></div>
                        <?php if ( $needs_ia ) : ?>
                            <div class="dp-dw-hint">
                                <span class="dashicons dashicons-info"></span>
                                Activeer eerst <a href="<?php echo esc_url( $pi_url ); ?>">DP Analytics</a>.
                            </div>
                        <?php elseif ( $needs_analytics ) : ?>
                            <div class="dp-dw-hint">
                                <span class="dashicons dashicons-info"></span>
                                Activeer eerst de Analytics widget hierboven.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="dp-dw-toggle <?php echo $is_disabled ? 'is-disabled' : ''; ?>">
                        <input type="hidden" name="<?php echo esc_attr( $w['name'] ); ?>" value="0">
                        <input type="checkbox"
                               id="dp-dw-<?php echo esc_attr( $w['id'] ); ?>"
                               name="<?php echo esc_attr( $w['name'] ); ?>"
                               value="1"
                               <?php checked( $w['val'] && ! $is_disabled ); ?>
                               <?php disabled( $is_disabled ); ?>>
                        <label for="dp-dw-<?php echo esc_attr( $w['id'] ); ?>"></label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Sectie 2: WordPress Widgets -->
        <div class="dp-dw-section">
            <span class="dashicons dashicons-wordpress"></span> WordPress Widgets
        </div>

        <div class="dp-dw-cards">
            <div class="dp-dw-card">
                <div class="dp-dw-info">
                    <div class="dp-dw-title">Standaard widgets verbergen</div>
                    <div class="dp-dw-desc">Verbergt alle standaard WordPress dashboard-widgets (Snel concept, Activiteit, etc.).</div>
                </div>
                <div class="dp-dw-toggle">
                    <input type="hidden" name="dp_toolbox_dashboard_hide_defaults" value="0">
                    <input type="checkbox" id="dp-dw-hide_defaults" name="dp_toolbox_dashboard_hide_defaults" value="1" <?php checked( $hide_wp ); ?>>
                    <label for="dp-dw-hide_defaults"></label>
                </div>
            </div>
        </div>

        <!-- Sectie 3: Punch Card API -->
        <div id="dp-dw-api-section" class="dp-dw-api-section" style="<?php echo $punch_card ? '' : 'display:none'; ?>">
            <div class="dp-dw-section" style="margin-top:0; border-bottom: none; padding-bottom: 4px;">
                <span class="dashicons dashicons-admin-network"></span> Punch Card API
            </div>
            <label for="dp-dw-api-key">API Key</label>
            <input type="text" id="dp-dw-api-key" name="dp_toolbox_dashboard_api_key" value="<?php echo esc_attr( $api_key ); ?>" placeholder="Voer je API-key in">
            <div class="dp-dw-api-help">De API-key vind je in je klantportaal. De data wordt 15 minuten gecachet.</div>
        </div>

        <!-- Sectie: Review status per user -->
        <?php
        $review_users = get_users( [
            'role__in' => [ 'administrator', 'editor', 'author', 'contributor' ],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ] );
        $user_toggle_nonce = wp_create_nonce( 'dp_toolbox_review_user_toggle' );
        ?>
        <div id="dp-dw-review-section" class="dp-dw-api-section" style="<?php echo $review ? '' : 'display:none'; ?>">
            <div class="dp-dw-section" style="margin-top:0; border-bottom: none; padding-bottom: 4px;">
                <span class="dashicons dashicons-star-filled"></span> Review status per user
            </div>
            <p class="dp-dw-api-help" style="margin-top:0; margin-bottom:12px;">
                De review-kaart is per gebruiker. Vink aan zodra een user heeft gereviewd — de kaart verdwijnt op zijn/haar dashboard. Wijzigingen worden direct opgeslagen.
            </p>

            <?php if ( empty( $review_users ) ) : ?>
                <div class="dp-dw-api-help">Geen gebruikers gevonden met dashboard-toegang (administrator, editor, author of contributor).</div>
            <?php else : ?>
                <div class="dp-dw-cards" id="dp-dw-review-users" data-nonce="<?php echo esc_attr( $user_toggle_nonce ); ?>" style="margin-top:0;">
                    <?php foreach ( $review_users as $u ) :
                        $given     = (bool) get_user_meta( $u->ID, 'dp_toolbox_review_given', true );
                        $role_slug = ! empty( $u->roles[0] ) ? $u->roles[0] : '';
                        $role_name = '';
                        if ( $role_slug ) {
                            $wp_role   = wp_roles()->roles[ $role_slug ] ?? null;
                            $raw_label = $wp_role['name'] ?? $role_slug;
                            $role_name = function_exists( 'translate_user_role' ) ? translate_user_role( $raw_label ) : $raw_label;
                        }
                    ?>
                        <div class="dp-dw-card dp-dw-review-user-card">
                            <div class="dp-dw-info">
                                <div class="dp-dw-title">
                                    <?php echo esc_html( $u->display_name ); ?>
                                    <?php if ( $role_name ) : ?>
                                        <span class="dp-dw-user-role"><?php echo esc_html( $role_name ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="dp-dw-desc"><?php echo esc_html( $u->user_email ); ?></div>
                            </div>
                            <div class="dp-dw-toggle">
                                <input type="checkbox"
                                       id="dp-dw-review-user-<?php echo (int) $u->ID; ?>"
                                       class="dp-dw-review-user-toggle"
                                       data-user-id="<?php echo (int) $u->ID; ?>"
                                       value="1"
                                       <?php checked( $given ); ?>>
                                <label for="dp-dw-review-user-<?php echo (int) $u->ID; ?>"></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sectie 4: Tutorials URL's -->
        <div id="dp-dw-tutorials-section" class="dp-dw-api-section" style="<?php echo $tutorials ? '' : 'display:none'; ?>">
            <div class="dp-dw-section" style="margin-top:0; border-bottom: none; padding-bottom: 4px;">
                <span class="dashicons dashicons-video-alt3"></span> Tutorial video's
            </div>
            <p class="dp-dw-api-help" style="margin-top:0; margin-bottom:14px;">
                Vul 1 tot 3 YouTube-URL's in. Ze verschijnen onderaan de welkom-widget, naast elkaar op desktop en onder elkaar op mobiel.
                Accepteert <code>youtube.com/watch?v=…</code>, <code>youtu.be/…</code> en <code>youtube.com/embed/…</code>.
            </p>
            <?php for ( $i = 0; $i < 3; $i++ ) : ?>
                <div style="margin-bottom:10px;">
                    <label for="dp-dw-tut-<?php echo $i; ?>">Video <?php echo $i + 1; ?></label>
                    <input type="url" id="dp-dw-tut-<?php echo $i; ?>"
                           name="dp_toolbox_dashboard_tutorial_urls[]"
                           value="<?php echo esc_attr( $tut_urls[ $i ] ); ?>"
                           placeholder="https://www.youtube.com/watch?v=...">
                </div>
            <?php endfor; ?>
        </div>

        <button type="submit" class="dp-dw-btn">Opslaan</button>
    </form>

    <script>
    (function() {
        function toggle(cbId, sectionId) {
            var cb = document.getElementById(cbId);
            var section = document.getElementById(sectionId);
            if (cb && section) {
                cb.addEventListener('change', function() {
                    section.style.display = this.checked ? '' : 'none';
                });
            }
        }
        toggle('dp-dw-punch_card', 'dp-dw-api-section');
        toggle('dp-dw-tutorials',  'dp-dw-tutorials-section');
        toggle('dp-dw-review',     'dp-dw-review-section');

        // Live AJAX-toggle voor de per-user review-status.
        var usersRoot = document.getElementById('dp-dw-review-users');
        if (usersRoot) {
            var nonce = usersRoot.dataset.nonce;
            usersRoot.querySelectorAll('.dp-dw-review-user-toggle').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var uid   = cb.dataset.userId;
                    var given = cb.checked;
                    cb.disabled = true;
                    var fd = new FormData();
                    fd.append('action', 'dp_toolbox_dashboard_review_user_toggle');
                    fd.append('_wpnonce', nonce);
                    fd.append('user_id', uid);
                    fd.append('given', given ? '1' : '0');
                    fetch(window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            if (!json || !json.success) {
                                cb.checked = !given;
                                alert('Wijzigen mislukt.');
                            }
                        })
                        .catch(function () { cb.checked = !given; alert('Netwerkfout.'); })
                        .finally(function () { cb.disabled = false; });
                });
            });
        }
    })();
    </script>
    <?php
}
