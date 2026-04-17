<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Quick Setup',
        'Quick Setup',
        'manage_options',
        'dp-toolbox-quick-setup',
        'dp_toolbox_quick_setup_page'
    );
} );

function dp_toolbox_quick_setup_page() {
    $defaults = dp_toolbox_qs_get_defaults();
    $nonce    = wp_create_nonce( 'dp_toolbox_quick_setup' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    // Group settings
    $groups = [
        'general'  => [ 'label' => 'Algemeen',        'icon' => 'dashicons-admin-settings' ],
        'datetime' => [ 'label' => 'Datum & Tijd',     'icon' => 'dashicons-clock' ],
        'seo'      => [ 'label' => 'SEO',              'icon' => 'dashicons-search' ],
        'content'    => [ 'label' => 'Content',          'icon' => 'dashicons-admin-post' ],
        'thumbnails' => [ 'label' => 'Thumbnails (Page Builder)', 'icon' => 'dashicons-format-image' ],
        'homepage'   => [ 'label' => 'Homepage',         'icon' => 'dashicons-admin-home' ],
    ];

    $grouped = [];
    foreach ( $defaults as $key => $setting ) {
        $grouped[ $setting['group'] ][ $key ] = $setting;
    }

    // Check current values
    $current = [];
    foreach ( $defaults as $key => $setting ) {
        $current[ $key ] = get_option( $key, '' );
    }

    dp_toolbox_page_start( 'Quick Setup', 'Configureer een nieuwe WordPress-installatie met één klik.' );
    ?>
    <style>
        .dp-qs-intro {
            background: #f8f7fc; border: 1px solid #e8e4f0; border-radius: 8px;
            padding: 16px 20px; margin-bottom: 20px;
            font-size: 13px; color: #555; line-height: 1.6;
        }
        .dp-qs-intro strong { color: #281E5D; }

        .dp-qs-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 20px 24px; margin-bottom: 12px;
        }
        .dp-qs-card h3 {
            margin: 0 0 14px; font-size: 14px; font-weight: 700; color: #1d2327;
            padding-bottom: 8px; border-bottom: 2px solid #281E5D;
            display: flex; align-items: center; gap: 8px;
        }
        .dp-qs-card h3 .dashicons { color: #281E5D; font-size: 16px; width: 16px; height: 16px; }

        .dp-qs-items { display: flex; flex-direction: column; gap: 6px; }

        .dp-qs-item {
            display: flex; align-items: center; gap: 12px; padding: 8px 12px;
            border-radius: 6px; transition: background 0.1s;
        }
        .dp-qs-item:hover { background: #faf9ff; }

        .dp-qs-item input[type="checkbox"] { margin: 0; flex-shrink: 0; }
        .dp-qs-item-label { flex: 1; font-size: 13px; font-weight: 500; color: #1d2327; }
        .dp-qs-item-value {
            font-size: 12px; color: #281E5D; font-weight: 600;
            background: #f3f0ff; padding: 2px 10px; border-radius: 4px;
        }
        .dp-qs-item-current {
            font-size: 11px; color: #999; white-space: nowrap;
        }
        .dp-qs-item-current.is-match { color: #16a34a; }
        .dp-qs-item-current.is-diff { color: #c48a00; }

        .dp-qs-actions {
            display: flex; gap: 10px; align-items: center; margin-top: 20px;
        }
        .dp-qs-btn {
            border: none; border-radius: 6px; padding: 10px 28px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background 0.15s; display: inline-flex; align-items: center; gap: 6px;
        }
        .dp-qs-btn-primary { background: #281E5D; color: #fff; }
        .dp-qs-btn-primary:hover { background: #4a3a8a; }
        .dp-qs-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .dp-qs-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }

        .dp-qs-btn-select {
            background: #fff; color: #281E5D; border: 1px solid #ddd; border-radius: 6px;
            padding: 6px 14px; font-size: 12px; font-weight: 500; cursor: pointer;
        }
        .dp-qs-btn-select:hover { border-color: #281E5D; }

        .dp-qs-result {
            margin-top: 16px; padding: 14px 18px; border-radius: 8px;
            font-size: 13px; display: none;
        }
        .dp-qs-result.is-visible { display: block; }
        .dp-qs-result.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .dp-qs-result.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .dp-qs-result ul { margin: 8px 0 0; padding: 0 0 0 18px; }
        .dp-qs-result li { font-size: 12px; margin-bottom: 2px; }
    </style>

    <div class="dp-qs-intro">
        <strong>Design Pixels standaard-profiel</strong> — selecteer welke instellingen je wilt toepassen
        op deze WordPress-installatie. Instellingen die al overeenkomen zijn groen gemarkeerd.
    </div>

    <div style="display:flex;gap:8px;margin-bottom:16px;">
        <button type="button" class="dp-qs-btn-select" id="dp-qs-select-all">Alles selecteren</button>
        <button type="button" class="dp-qs-btn-select" id="dp-qs-select-diff">Alleen afwijkend</button>
        <button type="button" class="dp-qs-btn-select" id="dp-qs-select-none">Niets selecteren</button>
    </div>

    <?php foreach ( $groups as $group_key => $group ) :
        if ( empty( $grouped[ $group_key ] ) ) continue;
    ?>
        <div class="dp-qs-card">
            <h3><span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span> <?php echo esc_html( $group['label'] ); ?></h3>
            <div class="dp-qs-items">
                <?php foreach ( $grouped[ $group_key ] as $key => $setting ) :
                    $cur_val = $current[ $key ] ?? '';
                    $is_match = ( (string) $cur_val === (string) $setting['value'] );
                ?>
                    <label class="dp-qs-item">
                        <input type="checkbox" name="dp_qs_settings[]" value="<?php echo esc_attr( $key ); ?>"
                               <?php checked( ! $is_match ); ?>
                               data-match="<?php echo $is_match ? '1' : '0'; ?>">
                        <span class="dp-qs-item-label"><?php echo esc_html( $setting['label'] ); ?></span>
                        <span class="dp-qs-item-value"><?php echo esc_html( $setting['display'] ); ?></span>
                        <span class="dp-qs-item-current <?php echo $is_match ? 'is-match' : 'is-diff'; ?>">
                            <?php echo $is_match ? '✓ OK' : '≠ anders'; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Cleanup -->
    <?php
    $hello_exists  = get_page_by_title( 'Hello world!', OBJECT, 'post' );
    if ( ! $hello_exists ) { $p1 = get_post( 1 ); $hello_exists = ( $p1 && $p1->post_type === 'post' && strpos( strtolower( $p1->post_title ), 'hello' ) !== false ) ? $p1 : null; }
    $sample_exists = get_page_by_title( 'Sample Page', OBJECT, 'page' );
    if ( ! $sample_exists ) $sample_exists = get_page_by_title( 'Voorbeeldpagina', OBJECT, 'page' );
    $active_theme  = get_stylesheet();
    $parent_theme  = get_template();
    $all_themes    = wp_get_themes();
    $inactive_themes = [];
    foreach ( $all_themes as $slug => $theme ) {
        if ( $slug !== $active_theme && $slug !== $parent_theme ) {
            $inactive_themes[ $slug ] = $theme->get( 'Name' );
        }
    }
    $trash_count = count( get_posts( [ 'post_status' => 'trash', 'post_type' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
    $tastewp_active = ! get_option( 'hide_tastewp_notice' ) || ! get_option( 'hide_tastewp_notice_small' ) || ! get_option( 'dp_toolbox_hide_tastewp_banners' );
    $welcome_panel_visible = (int) get_user_meta( get_current_user_id(), 'show_welcome_panel', true ) !== 0;
    $metaboxes_not_empty = ! get_option( 'dp_toolbox_qs_empty_metaboxes' );
    $has_cleanup = $hello_exists || $sample_exists || ! empty( $inactive_themes ) || $trash_count > 0 || $tastewp_active || $welcome_panel_visible || $metaboxes_not_empty;
    ?>
    <?php if ( $has_cleanup ) : ?>
    <div class="dp-qs-card">
        <h3><span class="dashicons dashicons-trash"></span> Opruimen</h3>
        <p style="margin:0 0 12px;font-size:12px;color:#888;">Verwijder standaard WordPress content en ongebruikte thema's.</p>
        <div class="dp-qs-items">
            <?php if ( $hello_exists ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="hello_world" checked data-match="0">
                    <span class="dp-qs-item-label">"Hello world!" bericht verwijderen</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;">Verwijderen</span>
                    <span class="dp-qs-item-current is-diff">aanwezig</span>
                </label>
            <?php endif; ?>
            <?php if ( $sample_exists ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="sample_page" checked data-match="0">
                    <span class="dp-qs-item-label">"<?php echo esc_html( $sample_exists->post_title ); ?>" verwijderen</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;">Verwijderen</span>
                    <span class="dp-qs-item-current is-diff">aanwezig</span>
                </label>
            <?php endif; ?>
            <?php if ( ! empty( $inactive_themes ) ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="inactive_themes" checked data-match="0">
                    <span class="dp-qs-item-label">Inactieve thema's verwijderen</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;"><?php echo count( $inactive_themes ); ?> thema's</span>
                    <span class="dp-qs-item-current is-diff"><?php echo esc_html( implode( ', ', $inactive_themes ) ); ?></span>
                </label>
            <?php endif; ?>
            <?php if ( $trash_count > 0 ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="empty_trash" checked data-match="0">
                    <span class="dp-qs-item-label">Prullenbak legen</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;"><?php echo $trash_count; ?> item(s)</span>
                    <span class="dp-qs-item-current is-diff">aanwezig</span>
                </label>
            <?php endif; ?>
            <?php if ( $tastewp_active ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="tastewp_notices" checked data-match="0">
                    <span class="dp-qs-item-label">TasteWP admin-notices verbergen</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;">Dismiss</span>
                    <span class="dp-qs-item-current is-diff">zichtbaar</span>
                </label>
            <?php endif; ?>
            <?php if ( $welcome_panel_visible ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="welcome_panel" checked data-match="0">
                    <span class="dp-qs-item-label">"Welkom bij WordPress!" panel verbergen</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;">Dismiss</span>
                    <span class="dp-qs-item-current is-diff">zichtbaar</span>
                </label>
            <?php endif; ?>
            <?php if ( $metaboxes_not_empty ) : ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_cleanup[]" value="empty_metaboxes" checked data-match="0">
                    <span class="dp-qs-item-label">Dashboard-metaboxen leegmaken</span>
                    <span class="dp-qs-item-value" style="background:#fef2f2;color:#991b1b;">Leegmaken</span>
                    <span class="dp-qs-item-current is-diff">niet leeg</span>
                </label>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Starter Content -->
    <?php
    $starter = dp_toolbox_qs_get_starter_content();
    $pages = array_filter( $starter, function( $s ) { return $s['post_type'] === 'page'; } );
    $posts = array_filter( $starter, function( $s ) { return $s['post_type'] === 'post'; } );
    ?>
    <div class="dp-qs-card">
        <h3><span class="dashicons dashicons-welcome-add-page"></span> Starter Content</h3>
        <p style="margin:0 0 12px;font-size:12px;color:#888;">Maak standaard pagina's en berichten aan. Items die al bestaan worden overgeslagen.</p>
        <div class="dp-qs-items">
            <?php foreach ( $pages as $key => $item ) :
                $exists = get_page_by_title( $item['title'], OBJECT, 'page' );
            ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_content[]" value="<?php echo esc_attr( $key ); ?>"
                           <?php echo $exists ? '' : 'checked'; ?>
                           data-match="<?php echo $exists ? '1' : '0'; ?>">
                    <span class="dp-qs-item-label"><?php echo esc_html( $item['label'] ); ?></span>
                    <span class="dp-qs-item-value">Lege pagina</span>
                    <span class="dp-qs-item-current <?php echo $exists ? 'is-match' : 'is-diff'; ?>">
                        <?php echo $exists ? '✓ bestaat' : '+ nieuw'; ?>
                    </span>
                </label>
            <?php endforeach; ?>
            <?php foreach ( $posts as $key => $item ) :
                $exists = get_page_by_title( $item['title'], OBJECT, 'post' );
            ?>
                <label class="dp-qs-item">
                    <input type="checkbox" name="dp_qs_content[]" value="<?php echo esc_attr( $key ); ?>"
                           <?php echo $exists ? '' : 'checked'; ?>
                           data-match="<?php echo $exists ? '1' : '0'; ?>">
                    <span class="dp-qs-item-label"><?php echo esc_html( $item['label'] ); ?></span>
                    <span class="dp-qs-item-value">Met lorem ipsum</span>
                    <span class="dp-qs-item-current <?php echo $exists ? 'is-match' : 'is-diff'; ?>">
                        <?php echo $exists ? '✓ bestaat' : '+ nieuw'; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="dp-qs-actions">
        <button type="button" class="dp-qs-btn dp-qs-btn-primary" id="dp-qs-apply">
            <span class="dashicons dashicons-yes"></span> Toepassen
        </button>
    </div>

    <div class="dp-qs-result" id="dp-qs-result"></div>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';
        var settingBoxes = document.querySelectorAll('input[name="dp_qs_settings[]"]');
        var contentBoxes = document.querySelectorAll('input[name="dp_qs_content[]"]');
        var cleanupBoxes = document.querySelectorAll('input[name="dp_qs_cleanup[]"]');
        var allBoxes     = document.querySelectorAll('input[name="dp_qs_settings[]"], input[name="dp_qs_content[]"], input[name="dp_qs_cleanup[]"]');

        // Select helpers
        document.getElementById('dp-qs-select-all').addEventListener('click', function() {
            allBoxes.forEach(function(cb) { cb.checked = true; });
        });
        document.getElementById('dp-qs-select-none').addEventListener('click', function() {
            allBoxes.forEach(function(cb) { cb.checked = false; });
        });
        document.getElementById('dp-qs-select-diff').addEventListener('click', function() {
            allBoxes.forEach(function(cb) { cb.checked = cb.dataset.match === '0'; });
        });

        // Apply
        document.getElementById('dp-qs-apply').addEventListener('click', function() {
            var btn      = this;
            var result   = document.getElementById('dp-qs-result');
            var selected = [];

            var contentSelected = [];
            var cleanupSelected = [];

            settingBoxes.forEach(function(cb) {
                if (cb.checked) selected.push(cb.value);
            });
            contentBoxes.forEach(function(cb) {
                if (cb.checked) contentSelected.push(cb.value);
            });
            cleanupBoxes.forEach(function(cb) {
                if (cb.checked) cleanupSelected.push(cb.value);
            });

            var totalSelected = selected.length + contentSelected.length + cleanupSelected.length;

            if (totalSelected === 0) {
                alert('Selecteer minimaal één instelling of content-item.');
                return;
            }

            if (!confirm('Wil je ' + totalSelected + ' item(s) toepassen?')) return;

            btn.disabled = true;
            btn.querySelector('.dashicons').className = 'dashicons dashicons-update dp-spin';
            result.classList.remove('is-visible');

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_qs_apply');
            fd.append('nonce', nonce);
            selected.forEach(function(s) { fd.append('settings[]', s); });
            contentSelected.forEach(function(s) { fd.append('content[]', s); });
            cleanupSelected.forEach(function(s) { fd.append('cleanup[]', s); });

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    result.classList.add('is-visible');

                    if (res.success) {
                        var html = '<strong>' + res.data.message + '</strong>';
                        if (res.data.applied.length > 0) {
                            html += '<ul>';
                            res.data.applied.forEach(function(a) { html += '<li>✓ ' + a + '</li>'; });
                            html += '</ul>';
                        }
                        if (res.data.errors.length > 0) {
                            res.data.errors.forEach(function(e) { html += '<br>⚠ ' + e; });
                        }
                        result.className = 'dp-qs-result is-visible success';
                        result.innerHTML = html;

                        // Refresh after 1.5s to show updated status
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        result.className = 'dp-qs-result is-visible error';
                        result.textContent = res.data || 'Fout bij toepassen.';
                    }

                    btn.disabled = false;
                    btn.querySelector('.dashicons').className = 'dashicons dashicons-yes';
                });
        });
    })();
    </script>
    <style>.dp-spin { animation: dp-spin 0.6s linear infinite; } @keyframes dp-spin { to { transform: rotate(360deg); } }</style>
    <?php
    dp_toolbox_page_end();
}
