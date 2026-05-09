<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register inline settings on Modules tab (vervangt vroegere add_submenu_page).
 */
add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'code-snippets', 'dp_toolbox_snippets_admin_render_inline', [
            'title'       => 'Code Snippets',
            'description' => 'Voer eigen PHP-, JS- of CSS-snippets uit zonder een mu-plugin of theme-edit.',
        ] );
    }
} );

/**
 * Enqueue WP's eigen code-editor (CodeMirror) op deze pagina.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( (string) $hook, 'dp-toolbox-snippets' ) === false ) return;
    // Default mode = PHP; we wisselen runtime via JS bij type-change.
    $settings = wp_enqueue_code_editor( [ 'type' => 'application/x-httpd-php' ] );
    if ( false !== $settings ) {
        wp_add_inline_script(
            'code-editor',
            sprintf( 'window.dpSnippetCMSettings = %s;', wp_json_encode( $settings ) )
        );
    }
    wp_enqueue_script( 'wp-theme-plugin-editor' );
    wp_enqueue_style( 'wp-codemirror' );
} );

function dp_toolbox_snippets_admin_render_inline() {
    $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
    if ( $action === 'edit' ) {
        dp_toolbox_snippets_admin_edit();
    } else {
        dp_toolbox_snippets_admin_list();
    }
}

/* ------------------------------------------------------------------ */
/*  List view                                                          */
/* ------------------------------------------------------------------ */

function dp_toolbox_snippets_admin_list() {
    $snippets = dp_toolbox_db_snippets_get_all();
    $files    = dp_toolbox_snippets_discover();
    $nonce    = wp_create_nonce( 'dp_toolbox_snippets' );
    $ajax_url = admin_url( 'admin-ajax.php' );

    $total   = count( $snippets );
    $active  = count( array_filter( $snippets, function ( $s ) { return ! empty( $s['active'] ); } ) );
    $errors  = count( array_filter( $snippets, function ( $s ) { return ! empty( $s['has_error'] ); } ) );

    $new_url = admin_url( 'admin.php?page=dp-toolbox#settings-code-snippets&action=edit' );
    ?>
    <style>
        .dp-sn-stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .dp-sn-stat { flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center; }
        .dp-sn-stat-num { display: block; font-size: 24px; font-weight: 700; color: #281E5D; }
        .dp-sn-stat-num.has-errors { color: #d63638; }
        .dp-sn-stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.3px; }

        .dp-sn-toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; }
        .dp-sn-btn { background: #281E5D; color: #fff; border: none; border-radius: 6px;
            padding: 8px 20px; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background 0.15s; display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; }
        .dp-sn-btn:hover { background: #4a3a8a; color: #fff; }
        .dp-sn-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }

        .dp-sn-table { width: 100%; border-collapse: collapse; }
        .dp-sn-table th { text-align: left; font-size: 11px; font-weight: 600; color: #888;
            text-transform: uppercase; letter-spacing: 0.3px; padding: 8px 12px; border-bottom: 2px solid #281E5D; }
        .dp-sn-table td { padding: 10px 12px; border-bottom: 1px solid #eee;
            font-size: 13px; color: #1d2327; vertical-align: middle; }
        .dp-sn-table tr:hover td { background: #faf9ff; }
        .dp-sn-table tr.is-inactive td { opacity: 0.5; }
        .dp-sn-table tr.is-inactive:hover td { opacity: 1; }
        .dp-sn-table tr.has-error td { background: #fef2f2; }
        .dp-sn-table tr.has-error:hover td { background: #fee; }

        .dp-sn-title { font-weight: 600; color: #281E5D; }
        .dp-sn-title a { color: inherit; text-decoration: none; }
        .dp-sn-title a:hover { text-decoration: underline; }
        .dp-sn-desc { font-size: 11px; color: #888; margin-top: 2px; }

        .dp-sn-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px;
            border-radius: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
        .dp-sn-badge.type-php { background: #eee8ff; color: #281E5D; }
        .dp-sn-badge.type-js  { background: #fef9c3; color: #854d0e; }
        .dp-sn-badge.type-css { background: #dbeafe; color: #1e40af; }
        .dp-sn-badge.error   { background: #fecaca; color: #991b1b; margin-left: 6px; }
        .dp-sn-badge.file    { background: #f3f4f6; color: #6b7280; }

        .dp-sn-scope, .dp-sn-sites { font-size: 12px; color: #666; }
        .dp-sn-prio  { text-align: center; font-size: 12px; color: #888; }

        .dp-sn-actions { display: flex; gap: 6px; }
        .dp-sn-actions a, .dp-sn-actions button {
            background: none; border: 1px solid #ddd; border-radius: 4px;
            padding: 4px 8px; cursor: pointer; font-size: 12px; color: #666;
            transition: all 0.15s; text-decoration: none; line-height: 1; display: inline-flex; align-items: center;
        }
        .dp-sn-actions a:hover, .dp-sn-actions button:hover { border-color: #281E5D; color: #281E5D; }
        .dp-sn-actions .dp-sn-delete:hover { border-color: #d63638; color: #d63638; }

        .dp-sn-empty { text-align: center; padding: 40px; color: #999; font-size: 14px; }
        .dp-sn-empty .dashicons { font-size: 40px; width: 40px; height: 40px; color: #ddd; display: block; margin: 0 auto 12px; }

        .dp-sn-err-msg { font-size: 11px; color: #b91c1c; margin-top: 4px; font-family: monospace; }

        .dp-sn-section-h { font-size: 12px; font-weight: 700; color: #555; text-transform: uppercase;
            letter-spacing: 0.5px; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #ddd; }
        .dp-sn-section-h:first-of-type { margin-top: 0; }
    </style>

    <div class="dp-sn-stats">
        <div class="dp-sn-stat">
            <span class="dp-sn-stat-num"><?php echo $total; ?></span>
            <span class="dp-sn-stat-label">Totaal</span>
        </div>
        <div class="dp-sn-stat">
            <span class="dp-sn-stat-num"><?php echo $active; ?></span>
            <span class="dp-sn-stat-label">Actief</span>
        </div>
        <div class="dp-sn-stat">
            <span class="dp-sn-stat-num <?php echo $errors > 0 ? 'has-errors' : ''; ?>"><?php echo $errors; ?></span>
            <span class="dp-sn-stat-label">Met fout</span>
        </div>
    </div>

    <div class="dp-sn-toolbar">
        <a class="dp-sn-btn" href="<?php echo esc_url( $new_url ); ?>">
            <span class="dashicons dashicons-plus-alt2"></span> Nieuwe snippet
        </a>
        <?php if ( ! empty( $_GET['dp_safe_mode'] ) ) : ?>
            <span style="background:#fef3cd;color:#854d0e;padding:6px 12px;border-radius:6px;font-size:12px;">
                <strong>Safe-mode actief</strong> — snippets worden bij deze request niet uitgevoerd.
            </span>
        <?php endif; ?>
    </div>

    <div class="dp-sn-section-h">Eigen snippets (database)</div>
    <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;">
        <table class="dp-sn-table">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>Titel</th>
                    <th style="width:60px;">Type</th>
                    <th style="width:140px;">Scope</th>
                    <th style="width:140px;">Sites</th>
                    <th style="width:60px;text-align:center;">Prio</th>
                    <th style="width:90px;">Acties</th>
                </tr>
            </thead>
            <tbody id="dp-sn-tbody">
                <?php if ( empty( $snippets ) ) : ?>
                    <tr><td colspan="7">
                        <div class="dp-sn-empty">
                            <span class="dashicons dashicons-editor-code"></span>
                            Nog geen snippets aangemaakt.
                        </div>
                    </td></tr>
                <?php else :
                    // Sort by priority for display
                    uasort( $snippets, function ( $a, $b ) {
                        return ( (int) ( $a['priority'] ?? 10 ) ) <=> ( (int) ( $b['priority'] ?? 10 ) );
                    } );
                    foreach ( $snippets as $id => $s ) :
                        $edit_url = admin_url( 'admin.php?page=dp-toolbox#settings-code-snippets&action=edit&id=' . urlencode( $id ) );
                        $row_class = '';
                        if ( empty( $s['active'] ) ) $row_class .= ' is-inactive';
                        if ( ! empty( $s['has_error'] ) ) $row_class .= ' has-error';
                        $type = $s['type'] ?? 'php';
                        $scope_labels = [
                            'everywhere'      => 'Overal',
                            'admin'           => 'Alleen admin',
                            'frontend'        => 'Alleen frontend',
                            'frontend_head'   => 'Frontend (head)',
                            'frontend_footer' => 'Frontend (footer)',
                        ];
                        $scope_label = $scope_labels[ $s['scope'] ?? '' ] ?? ( $s['scope'] ?? '' );
                ?>
                    <tr data-id="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( trim( $row_class ) ); ?>">
                        <td>
                            <div class="dp-toggle" style="transform:scale(0.8);">
                                <input type="checkbox" id="dp-sn-active-<?php echo esc_attr( $id ); ?>"
                                       <?php checked( ! empty( $s['active'] ) ); ?>
                                       <?php disabled( ! empty( $s['has_error'] ) && empty( $s['active'] ) ); ?>
                                       class="dp-sn-toggle-active">
                                <label for="dp-sn-active-<?php echo esc_attr( $id ); ?>"></label>
                            </div>
                        </td>
                        <td>
                            <div class="dp-sn-title">
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $s['title'] ?? '(zonder titel)' ); ?></a>
                                <?php if ( ! empty( $s['has_error'] ) ) : ?>
                                    <span class="dp-sn-badge error">fout</span>
                                <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $s['description'] ) ) : ?>
                                <div class="dp-sn-desc"><?php echo esc_html( $s['description'] ); ?></div>
                            <?php endif; ?>
                            <?php if ( ! empty( $s['has_error'] ) && ! empty( $s['error_msg'] ) ) : ?>
                                <div class="dp-sn-err-msg"><?php echo esc_html( $s['error_msg'] ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="dp-sn-badge type-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( strtoupper( $type ) ); ?></span></td>
                        <td><span class="dp-sn-scope"><?php echo esc_html( $scope_label ); ?></span></td>
                        <td><span class="dp-sn-sites"><?php echo esc_html( ! empty( $s['sites'] ) ? $s['sites'] : '— alle —' ); ?></span></td>
                        <td class="dp-sn-prio"><?php echo (int) ( $s['priority'] ?? 10 ); ?></td>
                        <td>
                            <div class="dp-sn-actions">
                                <a href="<?php echo esc_url( $edit_url ); ?>" title="Bewerken">
                                    <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span>
                                </a>
                                <button type="button" class="dp-sn-delete" title="Verwijderen">
                                    <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ( ! empty( $files ) ) : ?>
        <div class="dp-sn-section-h">Bundled snippets (bestand) <span style="font-weight:400;color:#888;">— uitgeleverd via plugin-update, niet bewerkbaar via UI</span></div>
        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;">
            <table class="dp-sn-table">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th style="width:140px;">Sites</th>
                        <th style="width:80px;">Status</th>
                        <th>Bestand</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $files as $f ) : ?>
                        <tr>
                            <td>
                                <div class="dp-sn-title"><?php echo esc_html( $f['name'] ); ?></div>
                                <?php if ( ! empty( $f['description'] ) ) : ?>
                                    <div class="dp-sn-desc"><?php echo esc_html( $f['description'] ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="dp-sn-sites"><?php echo esc_html( $f['sites'] ?: '— alle —' ); ?></span></td>
                            <td><span class="dp-sn-badge file"><?php echo esc_html( $f['status'] ); ?></span></td>
                            <td style="font-family:monospace;font-size:11px;color:#888;"><?php echo esc_html( $f['slug'] ); ?>.php</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';

        document.getElementById('dp-sn-tbody').addEventListener('click', function(e) {
            var btn = e.target.closest('button');
            if (!btn) return;
            var tr = btn.closest('tr');
            var id = tr ? tr.dataset.id : '';
            if (!id) return;

            if (btn.classList.contains('dp-sn-delete')) {
                if (!confirm('Deze snippet definitief verwijderen?')) return;
                var fd = new FormData();
                fd.append('action', 'dp_toolbox_snippet_delete');
                fd.append('nonce', nonce);
                fd.append('id', id);
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) { if (res.success) location.reload(); });
            }
        });

        document.getElementById('dp-sn-tbody').addEventListener('change', function(e) {
            if (!e.target.classList.contains('dp-sn-toggle-active')) return;
            var tr = e.target.closest('tr');
            var id = tr ? tr.dataset.id : '';
            if (!id) return;

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_snippet_toggle');
            fd.append('nonce', nonce);
            fd.append('id', id);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        tr.classList.toggle('is-inactive', !res.data.active);
                    } else {
                        alert(res.data || 'Fout bij activeren');
                        e.target.checked = !e.target.checked;
                    }
                });
        });
    })();
    </script>
    <?php
}

/* ------------------------------------------------------------------ */
/*  Edit view                                                          */
/* ------------------------------------------------------------------ */

function dp_toolbox_snippets_admin_edit() {
    $id  = sanitize_text_field( $_GET['id'] ?? '' );
    $all = dp_toolbox_db_snippets_get_all();

    $editing = $id !== '' && isset( $all[ $id ] );
    $s = $editing ? $all[ $id ] : [
        'id' => '', 'title' => '', 'description' => '',
        'type' => 'php', 'code' => '', 'scope' => 'everywhere',
        'priority' => 10, 'sites' => '', 'active' => false,
        'has_error' => false, 'error_msg' => '',
    ];

    $nonce    = wp_create_nonce( 'dp_toolbox_snippets' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    $list_url = admin_url( 'admin.php?page=dp-toolbox#settings-code-snippets' );

    ?>
    <h3 style="margin:0 0 4px;font-size:14px;font-weight:700;color:#1d2327;">
        <?php echo $editing ? 'Snippet bewerken' : 'Nieuwe snippet'; ?>
    </h3>
    <p style="margin:0 0 14px;font-size:12px;color:#888;">
        <?php echo $editing ? esc_html( $s['title'] ?? '' ) : 'Plak je PHP, JS of CSS hieronder.'; ?>
    </p>
    <style>
        .dp-sn-form { display: grid; grid-template-columns: 1fr 280px; gap: 20px; }
        .dp-sn-form-main, .dp-sn-form-side {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 18px 20px;
        }
        .dp-sn-row { margin-bottom: 14px; }
        .dp-sn-row label { display: block; font-size: 12px; font-weight: 600; color: #555;
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; }
        .dp-sn-row input[type="text"], .dp-sn-row input[type="number"], .dp-sn-row textarea, .dp-sn-row select {
            width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; box-sizing: border-box; font-family: inherit;
        }
        .dp-sn-row input:focus, .dp-sn-row select:focus, .dp-sn-row textarea:focus {
            border-color: #281E5D; outline: none; box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
        }
        .dp-sn-row .dp-sn-hint { font-size: 11px; color: #888; margin-top: 4px; }
        .dp-sn-form-actions { display: flex; gap: 10px; margin-top: 20px; align-items: center; }
        .dp-sn-cancel { background: #fff; color: #666; border: 1px solid #ddd; border-radius: 6px;
            padding: 8px 20px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-block; }
        .dp-sn-error-banner { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
        .dp-sn-error-banner code { background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 3px; font-size: 12px; }

        /* CodeMirror sizing */
        .CodeMirror { height: 480px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
        .CodeMirror-focused { border-color: #281E5D; box-shadow: 0 0 0 2px rgba(40,30,93,0.1); }

        .dp-sn-side-h { font-size: 11px; font-weight: 700; color: #555; text-transform: uppercase;
            letter-spacing: 0.5px; margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid #eee; }
        .dp-sn-side-toggle { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .dp-sn-side-toggle label.dp-sn-toggle-label {
            text-transform: none; letter-spacing: 0; font-size: 13px; font-weight: 500; color: #1d2327; margin: 0;
        }
        .dp-sn-form-feedback { font-size: 12px; color: #888; }
        .dp-sn-form-feedback.is-error { color: #d63638; font-weight: 600; }
    </style>

    <?php if ( $editing && ! empty( $s['has_error'] ) ) : ?>
        <div class="dp-sn-error-banner">
            <strong>Deze snippet is automatisch gedeactiveerd:</strong>
            <code><?php echo esc_html( $s['error_msg'] ?? '(onbekende fout)' ); ?></code>
            <br><small>Pas de code aan en sla op — dan wordt de fout-status gewist en kun je 'm weer activeren.</small>
        </div>
    <?php endif; ?>

    <form id="dp-sn-form" onsubmit="return false;">
        <div class="dp-sn-form">

            <!-- Main column -->
            <div class="dp-sn-form-main">
                <div class="dp-sn-row">
                    <label for="dp-sn-title">Titel</label>
                    <input type="text" id="dp-sn-title" value="<?php echo esc_attr( $s['title'] ?? '' ); ?>" placeholder="Bijv. Preload LCP image bghoefveld home" autofocus>
                </div>

                <div class="dp-sn-row">
                    <label for="dp-sn-description">Omschrijving (optioneel)</label>
                    <textarea id="dp-sn-description" rows="2" placeholder="Korte uitleg waarom deze snippet bestaat"><?php echo esc_textarea( $s['description'] ?? '' ); ?></textarea>
                </div>

                <div class="dp-sn-row">
                    <label for="dp-sn-code">Code</label>
                    <textarea id="dp-sn-code"><?php echo esc_textarea( $s['code'] ?? '' ); ?></textarea>
                    <p class="dp-sn-hint">PHP: <code>&lt;?php</code> aan het begin is optioneel. CSS/JS: zonder <code>&lt;style&gt;</code> of <code>&lt;script&gt;</code> tags.</p>
                </div>

                <div class="dp-sn-form-actions">
                    <button type="button" class="dp-sn-btn" id="dp-sn-save">Opslaan</button>
                    <a href="<?php echo esc_url( $list_url ); ?>" class="dp-sn-cancel">Annuleren</a>
                    <span class="dp-sn-form-feedback" id="dp-sn-feedback"></span>
                </div>
            </div>

            <!-- Side column -->
            <div class="dp-sn-form-side">
                <div class="dp-sn-row dp-sn-side-toggle">
                    <label class="dp-sn-toggle-label" for="dp-sn-active">Actief</label>
                    <div class="dp-toggle">
                        <input type="checkbox" id="dp-sn-active" <?php checked( ! empty( $s['active'] ) ); ?>>
                        <label for="dp-sn-active"></label>
                    </div>
                </div>

                <h3 class="dp-sn-side-h">Type & uitvoering</h3>

                <div class="dp-sn-row">
                    <label for="dp-sn-type">Type</label>
                    <select id="dp-sn-type">
                        <option value="php" <?php selected( $s['type'] ?? '', 'php' ); ?>>PHP</option>
                        <option value="js"  <?php selected( $s['type'] ?? '', 'js'  ); ?>>JavaScript</option>
                        <option value="css" <?php selected( $s['type'] ?? '', 'css' ); ?>>CSS</option>
                    </select>
                </div>

                <div class="dp-sn-row">
                    <label for="dp-sn-scope">Waar</label>
                    <select id="dp-sn-scope" data-current="<?php echo esc_attr( $s['scope'] ?? '' ); ?>">
                        <!-- options gevuld via JS -->
                    </select>
                </div>

                <div class="dp-sn-row">
                    <label for="dp-sn-priority">Priority</label>
                    <input type="number" id="dp-sn-priority" min="1" max="999" value="<?php echo (int) ( $s['priority'] ?? 10 ); ?>">
                    <p class="dp-sn-hint">Lager = eerder uitgevoerd. Default: 10.</p>
                </div>

                <h3 class="dp-sn-side-h">Site-targeting</h3>

                <div class="dp-sn-row">
                    <label for="dp-sn-sites">Sites</label>
                    <input type="text" id="dp-sn-sites" value="<?php echo esc_attr( $s['sites'] ?? '' ); ?>" placeholder="bghoefveld.nl, andersite.nl">
                    <p class="dp-sn-hint">Hostnames, comma-gescheiden. Leeg of <code>*</code> = overal actief.</p>
                </div>

                <p class="dp-sn-hint" style="margin-top:14px;">
                    <strong>Vastgelopen?</strong> Voeg <code>?dp_safe_mode=1</code> aan een admin-URL toe om alle snippets tijdelijk te skippen (alleen voor admins).
                </p>
            </div>
        </div>
    </form>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';
        var snippetId = <?php echo wp_json_encode( $editing ? $id : '' ); ?>;

        var typeEl     = document.getElementById('dp-sn-type');
        var scopeEl    = document.getElementById('dp-sn-scope');
        var codeEl     = document.getElementById('dp-sn-code');
        var saveBtn    = document.getElementById('dp-sn-save');
        var feedback   = document.getElementById('dp-sn-feedback');

        // Scope-options per type
        var SCOPES = {
            php: [
                { value: 'everywhere', label: 'Overal' },
                { value: 'admin',      label: 'Alleen admin' },
                { value: 'frontend',   label: 'Alleen frontend' }
            ],
            js: [
                { value: 'frontend_footer', label: 'Frontend (footer)' },
                { value: 'frontend_head',   label: 'Frontend (head)' },
                { value: 'admin',           label: 'Alleen admin' }
            ],
            css: [
                { value: 'frontend', label: 'Frontend' },
                { value: 'admin',    label: 'Alleen admin' }
            ]
        };

        function rebuildScopes(preserve) {
            var type = typeEl.value;
            var current = preserve || scopeEl.dataset.current || (SCOPES[type][0].value);
            scopeEl.innerHTML = '';
            SCOPES[type].forEach(function(opt) {
                var o = document.createElement('option');
                o.value = opt.value; o.textContent = opt.label;
                if (opt.value === current) o.selected = true;
                scopeEl.appendChild(o);
            });
            // If current not valid for this type, fall back to first
            if (!SCOPES[type].some(function(o){ return o.value === scopeEl.value; })) {
                scopeEl.value = SCOPES[type][0].value;
            }
        }
        rebuildScopes();

        // CodeMirror init
        var TYPE_TO_MODE = {
            php: 'application/x-httpd-php',
            js:  'text/javascript',
            css: 'text/css'
        };

        var cm = null;
        function initEditor() {
            if (!window.wp || !wp.codeEditor || !window.dpSnippetCMSettings) return;
            var settings = JSON.parse(JSON.stringify(window.dpSnippetCMSettings));
            settings.codemirror = settings.codemirror || {};
            settings.codemirror.mode = TYPE_TO_MODE[typeEl.value] || 'application/x-httpd-php';
            settings.codemirror.indentUnit = 4;
            settings.codemirror.tabSize = 4;
            settings.codemirror.lineNumbers = true;
            settings.codemirror.lineWrapping = true;
            cm = wp.codeEditor.initialize(codeEl, settings);
        }
        initEditor();

        // Wisselen van type ⇒ scope-opties opnieuw bouwen + editor-mode wisselen
        typeEl.addEventListener('change', function() {
            scopeEl.dataset.current = '';
            rebuildScopes();
            if (cm && cm.codemirror) {
                cm.codemirror.setOption('mode', TYPE_TO_MODE[typeEl.value]);
            }
        });

        // Opslaan
        saveBtn.addEventListener('click', function() {
            saveBtn.disabled = true;
            feedback.className = 'dp-sn-form-feedback';
            feedback.textContent = 'Opslaan...';

            // Sync CM → textarea
            if (cm && cm.codemirror) cm.codemirror.save();

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_snippet_save');
            fd.append('nonce', nonce);
            fd.append('id', snippetId);
            fd.append('title',       document.getElementById('dp-sn-title').value);
            fd.append('description', document.getElementById('dp-sn-description').value);
            fd.append('type',        typeEl.value);
            fd.append('scope',       scopeEl.value);
            fd.append('code',        codeEl.value);
            fd.append('priority',    document.getElementById('dp-sn-priority').value);
            fd.append('sites',       document.getElementById('dp-sn-sites').value);
            fd.append('active',      document.getElementById('dp-sn-active').checked ? '1' : '');

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        // Redirect terug naar lijst
                        window.location.href = <?php echo wp_json_encode( $list_url ); ?>;
                    } else {
                        feedback.className = 'dp-sn-form-feedback is-error';
                        feedback.textContent = res.data || 'Fout bij opslaan.';
                        saveBtn.disabled = false;
                    }
                })
                .catch(function() {
                    feedback.className = 'dp-sn-form-feedback is-error';
                    feedback.textContent = 'Netwerkfout.';
                    saveBtn.disabled = false;
                });
        });
    })();
    </script>
    <?php
}
