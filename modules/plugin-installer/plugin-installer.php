<?php
/**
 * Module Name: Plugin Installer
 * Description: Installeer aanbevolen plugins van wordpress.org, activeer in een aparte stap.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Recommended plugin list                                            */
/* ------------------------------------------------------------------ */

function dp_toolbox_pi_get_recommended() {
    return [
        [
            'slug'        => 'autodescription',
            'file'        => 'autodescription/autodescription.php',
            'name'        => 'The SEO Framework',
            'description' => 'Snelle, schone SEO zonder nag-schermen.',
        ],
        [
            'slug'        => 'litespeed-cache',
            'file'        => 'litespeed-cache/litespeed-cache.php',
            'name'        => 'LiteSpeed Cache',
            'description' => 'Caching en page-speed optimalisaties.',
        ],
        [
            'slug'        => 'all-in-one-wp-security-and-firewall',
            'file'        => 'all-in-one-wp-security-and-firewall/wp-security.php',
            'name'        => 'All-In-One Security (AIOS)',
            'description' => 'Basisbeveiliging: login hardening, firewall, scans.',
        ],
        [
            'slug'        => 'backup-backup',
            'file'        => 'backup-backup/backup-backup.php',
            'name'        => 'BackupBliss',
            'description' => 'Backups met gratis cloud storage.',
        ],
        [
            'slug'        => 'independent-analytics',
            'file'        => 'independent-analytics/iawp.php',
            'name'        => 'Independent Analytics',
            'description' => 'Privacy-vriendelijke analytics zonder cookies.',
        ],
    ];
}

/* ------------------------------------------------------------------ */
/*  Status helpers                                                     */
/* ------------------------------------------------------------------ */

function dp_toolbox_pi_status( $plugin ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $installed = get_plugins();
    $file      = $plugin['file'];

    // Fallback: als het verwachte file-path niet bestaat, zoek op slug.
    if ( ! isset( $installed[ $file ] ) ) {
        foreach ( array_keys( $installed ) as $installed_file ) {
            if ( strpos( $installed_file, $plugin['slug'] . '/' ) === 0 ) {
                $file = $installed_file;
                break;
            }
        }
    }

    if ( ! isset( $installed[ $file ] ) ) {
        return [ 'state' => 'missing', 'file' => $plugin['file'], 'version' => '' ];
    }

    if ( is_plugin_active( $file ) ) {
        return [ 'state' => 'active', 'file' => $file, 'version' => $installed[ $file ]['Version'] ];
    }

    return [ 'state' => 'inactive', 'file' => $file, 'version' => $installed[ $file ]['Version'] ];
}

function dp_toolbox_pi_find_by_slug( $slug ) {
    foreach ( dp_toolbox_pi_get_recommended() as $p ) {
        if ( $p['slug'] === $slug ) return $p;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/*  Admin menu                                                         */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Plugin Installer',
        'Plugin Installer',
        'install_plugins',
        'dp-toolbox-plugin-installer',
        'dp_toolbox_pi_render_page'
    );
} );

/* ------------------------------------------------------------------ */
/*  Render page                                                        */
/* ------------------------------------------------------------------ */

function dp_toolbox_pi_render_page() {
    if ( ! current_user_can( 'install_plugins' ) ) {
        wp_die( 'Je hebt geen toegang tot deze pagina.' );
    }

    $plugins  = dp_toolbox_pi_get_recommended();
    $statuses = [];
    $counts   = [ 'active' => 0, 'inactive' => 0, 'missing' => 0 ];

    foreach ( $plugins as $p ) {
        $s = dp_toolbox_pi_status( $p );
        $statuses[ $p['slug'] ] = $s;
        $counts[ $s['state'] ]++;
    }

    $nonce = wp_create_nonce( 'dp_toolbox_plugin_installer' );

    dp_toolbox_page_start(
        'Plugin Installer',
        'Installeer aanbevolen plugins in twee stappen: eerst downloaden, dan activeren.'
    );
    ?>

    <style>
        .dp-pi-stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .dp-pi-stat {
            flex: 1; background: #f8f7fc; border-radius: 8px; padding: 16px; text-align: center;
        }
        .dp-pi-stat-num {
            display: block; font-size: 28px; font-weight: 700; line-height: 1; margin-bottom: 4px;
        }
        .dp-pi-stat-num.active   { color: #281E5D; }
        .dp-pi-stat-num.inactive { color: #c48a00; }
        .dp-pi-stat-num.missing  { color: #888; }
        .dp-pi-stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }

        .dp-pi-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
        .dp-pi-card {
            display: flex; align-items: center; gap: 14px;
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 14px 18px; transition: border-color 0.15s, box-shadow 0.15s;
        }
        .dp-pi-card:hover { border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08); }
        .dp-pi-card.is-active   { border-left: 3px solid #281E5D; }
        .dp-pi-card.is-inactive { border-left: 3px solid #c48a00; }

        .dp-pi-info { flex: 1; min-width: 0; }
        .dp-pi-info h3 { margin: 0; font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-pi-info h3 .dp-pi-version {
            color: #bbb; font-size: 10px; font-weight: 400; margin-left: 4px;
        }
        .dp-pi-info p { margin: 3px 0 0; color: #888; font-size: 12px; line-height: 1.5; }

        .dp-pi-badge {
            font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 4px 9px; border-radius: 4px; white-space: nowrap;
        }
        .dp-pi-badge.active   { color: #281E5D; background: #eee8ff; }
        .dp-pi-badge.inactive { color: #c48a00; background: #fff4d6; }
        .dp-pi-badge.missing  { color: #666;    background: #f0f0f1; }
        .dp-pi-badge.working  { color: #fff;    background: #281E5D; }
        .dp-pi-badge.error    { color: #fff;    background: #d63638; }

        .dp-pi-toolbar { display: flex; align-items: center; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .dp-pi-toolbar .spacer { flex: 1; }
        .dp-pi-toolbar button[disabled] { opacity: 0.45; cursor: not-allowed; }

        .dp-pi-log {
            margin-top: 16px; background: #1d2327; color: #c8e6c9;
            border-radius: 6px; padding: 14px 18px; font-family: Menlo, Consolas, monospace;
            font-size: 12px; line-height: 1.6; display: none; max-height: 240px; overflow: auto;
        }
        .dp-pi-log.is-visible { display: block; }
        .dp-pi-log .err  { color: #ef9a9a; }
        .dp-pi-log .ok   { color: #a5d6a7; }
        .dp-pi-log .info { color: #bbdefb; }

        .dp-pi-spinner {
            display: inline-block; width: 12px; height: 12px;
            border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff;
            border-radius: 50%; animation: dp-pi-spin 0.8s linear infinite;
            vertical-align: middle; margin-right: 4px;
        }
        @keyframes dp-pi-spin { to { transform: rotate(360deg); } }
    </style>

    <div class="dp-section-header">
        <span class="dashicons dashicons-admin-plugins"></span>
        <h2>Aanbevolen plugins</h2>
    </div>

    <div class="dp-pi-stats">
        <div class="dp-pi-stat">
            <span class="dp-pi-stat-num active" data-stat="active"><?php echo $counts['active']; ?></span>
            <span class="dp-pi-stat-label">Actief</span>
        </div>
        <div class="dp-pi-stat">
            <span class="dp-pi-stat-num inactive" data-stat="inactive"><?php echo $counts['inactive']; ?></span>
            <span class="dp-pi-stat-label">Geïnstalleerd</span>
        </div>
        <div class="dp-pi-stat">
            <span class="dp-pi-stat-num missing" data-stat="missing"><?php echo $counts['missing']; ?></span>
            <span class="dp-pi-stat-label">Ontbreekt</span>
        </div>
    </div>

    <div class="dp-pi-list">
        <?php foreach ( $plugins as $p ) :
            $s       = $statuses[ $p['slug'] ];
            $state   = $s['state'];
            // Default checked: alles behalve actieve plugins
            $checked = $state !== 'active';
            $card_cls = $state === 'active' ? 'is-active' : ( $state === 'inactive' ? 'is-inactive' : '' );
        ?>
            <div class="dp-pi-card <?php echo esc_attr( $card_cls ); ?>"
                 data-slug="<?php echo esc_attr( $p['slug'] ); ?>"
                 data-state="<?php echo esc_attr( $state ); ?>">
                <div class="dp-toggle">
                    <input type="checkbox"
                           id="dp-pi-<?php echo esc_attr( $p['slug'] ); ?>"
                           class="dp-pi-check"
                           value="<?php echo esc_attr( $p['slug'] ); ?>"
                           <?php checked( $checked ); ?>
                           <?php disabled( $state === 'active' ); ?>>
                    <label for="dp-pi-<?php echo esc_attr( $p['slug'] ); ?>"></label>
                </div>
                <div class="dp-pi-info">
                    <h3>
                        <?php echo esc_html( $p['name'] ); ?>
                        <?php if ( $s['version'] ) : ?>
                            <span class="dp-pi-version">v<?php echo esc_html( $s['version'] ); ?></span>
                        <?php endif; ?>
                    </h3>
                    <p><?php echo esc_html( $p['description'] ); ?></p>
                </div>
                <span class="dp-pi-badge <?php echo esc_attr( $state ); ?>" data-badge>
                    <?php
                    echo $state === 'active'   ? 'Actief'
                       : ( $state === 'inactive' ? 'Geïnstalleerd' : 'Niet geïnstalleerd' );
                    ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="dp-pi-toolbar">
        <button type="button" class="dp-btn-secondary" id="dp-pi-select-all">Alles selecteren</button>
        <button type="button" class="dp-btn-secondary" id="dp-pi-select-none">Niets selecteren</button>
        <span class="spacer"></span>
        <button type="button" class="dp-btn-secondary" id="dp-pi-install">Stap 1 — Installeren</button>
        <button type="button" class="dp-btn-primary"   id="dp-pi-activate">Stap 2 — Activeren</button>
    </div>

    <div class="dp-pi-log" id="dp-pi-log"></div>

    <script>
    (function(){
        const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        const nonce   = <?php echo wp_json_encode( $nonce ); ?>;

        const list     = document.querySelectorAll('.dp-pi-card');
        const logEl    = document.getElementById('dp-pi-log');
        const btnInst  = document.getElementById('dp-pi-install');
        const btnAct   = document.getElementById('dp-pi-activate');

        function allChecks() {
            return Array.from(document.querySelectorAll('.dp-pi-check'));
        }

        document.getElementById('dp-pi-select-all').addEventListener('click', function(){
            allChecks().forEach(c => { if (!c.disabled) c.checked = true; });
        });
        document.getElementById('dp-pi-select-none').addEventListener('click', function(){
            allChecks().forEach(c => c.checked = false);
        });

        function log(msg, cls) {
            logEl.classList.add('is-visible');
            const line = document.createElement('div');
            if (cls) line.className = cls;
            line.innerHTML = msg;
            logEl.appendChild(line);
            logEl.scrollTop = logEl.scrollHeight;
        }

        function setBadge(card, state, label) {
            const badge = card.querySelector('[data-badge]');
            badge.className = 'dp-pi-badge ' + state;
            badge.innerHTML = label;
        }

        function setCardState(card, state) {
            card.dataset.state = state;
            card.classList.remove('is-active', 'is-inactive');
            if (state === 'active')   card.classList.add('is-active');
            if (state === 'inactive') card.classList.add('is-inactive');
        }

        function updateStats() {
            const counts = { active: 0, inactive: 0, missing: 0 };
            list.forEach(card => {
                if (counts.hasOwnProperty(card.dataset.state)) counts[card.dataset.state]++;
            });
            document.querySelector('[data-stat="active"]').textContent   = counts.active;
            document.querySelector('[data-stat="inactive"]').textContent = counts.inactive;
            document.querySelector('[data-stat="missing"]').textContent  = counts.missing;
        }

        function selectedByState(state) {
            return allChecks()
                .filter(c => c.checked && !c.disabled)
                .map(c => ({ chk: c, card: document.querySelector('.dp-pi-card[data-slug="' + c.value + '"]') }))
                .filter(x => x.card.dataset.state === state);
        }

        async function callAjax(action, slug) {
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', nonce);
            body.append('slug', slug);
            const r = await fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
            return r.json();
        }

        async function installOne(slug, card) {
            setBadge(card, 'working', '<span class="dp-pi-spinner"></span>Installeren…');
            try {
                const j = await callAjax('dp_toolbox_pi_install', slug);
                if (j.success) {
                    setCardState(card, 'inactive');
                    setBadge(card, 'inactive', 'Geïnstalleerd');
                    log('✓ ' + slug + ' geïnstalleerd', 'ok');
                    return true;
                }
                const msg = (j.data && j.data.message) ? j.data.message : 'Onbekende fout';
                setCardState(card, 'missing');
                setBadge(card, 'error', 'Fout');
                log('✗ ' + slug + ': ' + msg, 'err');
                return false;
            } catch (e) {
                setCardState(card, 'missing');
                setBadge(card, 'error', 'Fout');
                log('✗ ' + slug + ': ' + e.message, 'err');
                return false;
            }
        }

        async function activateOne(slug, card, chk) {
            setBadge(card, 'working', '<span class="dp-pi-spinner"></span>Activeren…');
            try {
                const j = await callAjax('dp_toolbox_pi_activate', slug);
                if (j.success) {
                    setCardState(card, 'active');
                    setBadge(card, 'active', 'Actief');
                    chk.checked = false;
                    chk.disabled = true;
                    log('✓ ' + slug + ' geactiveerd', 'ok');
                    return true;
                }
                const msg = (j.data && j.data.message) ? j.data.message : 'Onbekende fout';
                setCardState(card, 'inactive');
                setBadge(card, 'error', 'Fout');
                log('✗ ' + slug + ': ' + msg, 'err');
                return false;
            } catch (e) {
                setCardState(card, 'inactive');
                setBadge(card, 'error', 'Fout');
                log('✗ ' + slug + ': ' + e.message, 'err');
                return false;
            }
        }

        btnInst.addEventListener('click', async function(){
            const targets = selectedByState('missing');
            if (!targets.length) {
                logEl.classList.add('is-visible');
                logEl.innerHTML = '<div class="info">Geen nog te installeren plugins geselecteerd.</div>';
                return;
            }

            btnInst.disabled = true;
            btnAct.disabled  = true;
            logEl.innerHTML = '';
            log('Stap 1 — installeren van ' + targets.length + ' plugin(s)…', 'info');

            let ok = 0;
            for (const t of targets) {
                if (await installOne(t.chk.value, t.card)) ok++;
            }
            log('Installatie klaar: ' + ok + ' van ' + targets.length + '. Kies nu "Stap 2 — Activeren".', 'info');
            updateStats();
            btnInst.disabled = false;
            btnAct.disabled  = false;
        });

        btnAct.addEventListener('click', async function(){
            const targets = selectedByState('inactive');
            if (!targets.length) {
                logEl.classList.add('is-visible');
                logEl.innerHTML = '<div class="info">Geen geïnstalleerde plugins geselecteerd om te activeren.</div>';
                return;
            }

            btnInst.disabled = true;
            btnAct.disabled  = true;
            log('Stap 2 — activeren van ' + targets.length + ' plugin(s)…', 'info');

            let ok = 0;
            for (const t of targets) {
                if (await activateOne(t.chk.value, t.card, t.chk)) ok++;
            }
            log('Activatie klaar: ' + ok + ' van ' + targets.length + '.', 'info');
            updateStats();
            btnInst.disabled = false;
            btnAct.disabled  = false;
        });
    })();
    </script>

    <?php
    dp_toolbox_page_end();
}

/* ------------------------------------------------------------------ */
/*  Shared: load WP install/upgrade infrastructure                     */
/* ------------------------------------------------------------------ */

function dp_toolbox_pi_load_wp_admin() {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

/* ------------------------------------------------------------------ */
/*  AJAX: install only (no activate)                                   */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_pi_install', function () {
    if ( ! current_user_can( 'install_plugins' ) ) {
        wp_send_json_error( [ 'message' => 'Geen toegang.' ] );
    }
    check_ajax_referer( 'dp_toolbox_plugin_installer', 'nonce' );

    $slug   = sanitize_key( $_POST['slug'] ?? '' );
    $plugin = dp_toolbox_pi_find_by_slug( $slug );
    if ( ! $plugin ) {
        wp_send_json_error( [ 'message' => 'Plugin niet in aanbevolen lijst.' ] );
    }

    dp_toolbox_pi_load_wp_admin();

    $status = dp_toolbox_pi_status( $plugin );

    // Al geïnstalleerd (actief of inactief): niets te doen.
    if ( $status['state'] !== 'missing' ) {
        wp_send_json_success( [ 'slug' => $slug, 'status' => $status['state'], 'file' => $status['file'] ] );
    }

    $api = plugins_api( 'plugin_information', [
        'slug'   => $slug,
        'fields' => [ 'sections' => false ],
    ] );
    if ( is_wp_error( $api ) ) {
        wp_send_json_error( [ 'message' => 'wp.org API: ' . $api->get_error_message() ] );
    }

    $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
    $result   = $upgrader->install( $api->download_link );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => 'Installatie mislukt: ' . $result->get_error_message() ] );
    }
    if ( false === $result || null === $result ) {
        $skin_errors = $upgrader->skin->get_errors();
        $msg = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
            ? $skin_errors->get_error_message()
            : 'Installatie mislukt (onbekende fout — mogelijk vraagt de server om FTP-gegevens).';
        wp_send_json_error( [ 'message' => $msg ] );
    }

    $plugin_file = $upgrader->plugin_info();
    if ( ! $plugin_file ) {
        $fresh       = dp_toolbox_pi_status( $plugin );
        $plugin_file = $fresh['file'];
    }

    wp_send_json_success( [ 'slug' => $slug, 'status' => 'inactive', 'file' => $plugin_file ] );
} );

/* ------------------------------------------------------------------ */
/*  AJAX: activate only                                                */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_dp_toolbox_pi_activate', function () {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_send_json_error( [ 'message' => 'Geen toegang.' ] );
    }
    check_ajax_referer( 'dp_toolbox_plugin_installer', 'nonce' );

    $slug   = sanitize_key( $_POST['slug'] ?? '' );
    $plugin = dp_toolbox_pi_find_by_slug( $slug );
    if ( ! $plugin ) {
        wp_send_json_error( [ 'message' => 'Plugin niet in aanbevolen lijst.' ] );
    }

    dp_toolbox_pi_load_wp_admin();

    $status = dp_toolbox_pi_status( $plugin );

    if ( $status['state'] === 'missing' ) {
        wp_send_json_error( [ 'message' => 'Plugin is nog niet geïnstalleerd — voer eerst stap 1 uit.' ] );
    }
    if ( $status['state'] === 'active' ) {
        wp_send_json_success( [ 'slug' => $slug, 'status' => 'active' ] );
    }

    $activate = activate_plugin( $status['file'] );
    if ( is_wp_error( $activate ) ) {
        wp_send_json_error( [ 'message' => 'Activeren mislukt: ' . $activate->get_error_message() ] );
    }

    wp_send_json_success( [ 'slug' => $slug, 'status' => 'active', 'file' => $status['file'] ] );
} );
