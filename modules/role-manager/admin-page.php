<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------
 *  Menu registratie
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Role Manager',
        'Role Manager',
        'manage_options',
        'dp-toolbox-role-manager',
        'dp_toolbox_rm_page'
    );
} );

/* ------------------------------------------------------------------
 *  AJAX: opslaan menu-instellingen per rol
 * ------------------------------------------------------------------ */
add_action( 'wp_ajax_dp_toolbox_rm_save_menus', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toegang' );
    }
    check_ajax_referer( 'dp_toolbox_role_manager', 'nonce' );

    $role = sanitize_key( $_POST['role'] ?? '' );
    if ( empty( $role ) ) {
        wp_send_json_error( 'Geen rol opgegeven' );
    }

    $menus = array_map( 'sanitize_text_field', (array) ( $_POST['hidden_menus'] ?? [] ) );
    $subs_raw = (array) ( $_POST['hidden_submenus'] ?? [] );

    // Submenu's opschonen: parent_slug => [sub_slug, ...]
    $subs = [];
    foreach ( $subs_raw as $key => $slugs ) {
        $parent = sanitize_text_field( $key );
        $subs[ $parent ] = array_map( 'sanitize_text_field', (array) $slugs );
    }

    update_option( 'dp_toolbox_rm_hidden_menus_' . $role, $menus );
    update_option( 'dp_toolbox_rm_hidden_submenus_' . $role, $subs );

    wp_send_json_success( 'Opgeslagen' );
} );

/* ------------------------------------------------------------------
 *  AJAX: opslaan plugin-instellingen
 * ------------------------------------------------------------------ */
add_action( 'wp_ajax_dp_toolbox_rm_save_plugins', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toegang' );
    }
    check_ajax_referer( 'dp_toolbox_role_manager', 'nonce' );

    $plugins = array_map( 'sanitize_text_field', (array) ( $_POST['hidden_plugins'] ?? [] ) );
    update_option( 'dp_toolbox_rm_hidden_plugins', $plugins );

    wp_send_json_success( 'Opgeslagen' );
} );

/* ------------------------------------------------------------------
 *  Admin pagina render
 * ------------------------------------------------------------------ */
function dp_toolbox_rm_page() {
    $nonce = wp_create_nonce( 'dp_toolbox_role_manager' );

    // Alle rollen behalve administrator
    $all_roles = wp_roles()->role_names;
    unset( $all_roles['administrator'] );

    // Eerste rol als default
    $active_role = array_key_first( $all_roles ) ?: 'editor';

    // Menu-structuur ophalen uit transient
    $all_menus   = get_transient( 'dp_toolbox_rm_all_menus' ) ?: [];
    $all_subs    = get_transient( 'dp_toolbox_rm_all_submenus' ) ?: [];

    // Plugins ophalen
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins    = get_plugins();
    $hidden_plugins = get_option( 'dp_toolbox_rm_hidden_plugins', [] );

    // Sorteer menu op positie
    ksort( $all_menus );

    // Verborgen menu's/subs per rol voorbereiden (JSON voor JS)
    $hidden_per_role = [];
    foreach ( array_keys( $all_roles ) as $role_slug ) {
        $hidden_per_role[ $role_slug ] = [
            'menus'    => get_option( 'dp_toolbox_rm_hidden_menus_' . $role_slug, [] ),
            'submenus' => get_option( 'dp_toolbox_rm_hidden_submenus_' . $role_slug, [] ),
        ];
    }

    dp_toolbox_page_start( 'Role Manager', 'Beheer welke menu-items en plugins zichtbaar zijn per gebruikersrol.' );
    ?>
    <style>
        /* Tabs */
        .dp-rm-tabs { display: flex; gap: 0; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; }
        .dp-rm-tab { padding: 10px 20px; font-size: 13px; font-weight: 600; color: #666; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
        .dp-rm-tab:hover { color: #281E5D; }
        .dp-rm-tab.active { color: #281E5D; border-bottom-color: #281E5D; }
        .dp-rm-panel { display: none; }
        .dp-rm-panel.active { display: block; }

        /* Rol selector */
        .dp-rm-role-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .dp-rm-role-bar label { font-size: 13px; font-weight: 600; color: #1d2327; }
        .dp-rm-role-bar select { padding: 6px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }

        /* Menu lijst */
        .dp-rm-list { display: flex; flex-direction: column; gap: 0; }
        .dp-rm-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 16px; border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .dp-rm-item:hover { background: #f9f8fc; }
        .dp-rm-item:last-child { border-bottom: none; }
        .dp-rm-item.dp-rm-sub { padding-left: 44px; }
        .dp-rm-item.dp-rm-sub .dp-rm-label::before { content: '└ '; color: #ccc; }
        .dp-rm-item input[type="checkbox"] { width: 16px; height: 16px; accent-color: #281E5D; cursor: pointer; flex-shrink: 0; }
        .dp-rm-label { font-size: 13px; color: #1d2327; flex: 1; }
        .dp-rm-slug { font-size: 11px; color: #aaa; font-family: monospace; }
        .dp-rm-item.dp-rm-hidden { background: #fef7f7; }
        .dp-rm-item.dp-rm-hidden .dp-rm-label { color: #d63638; text-decoration: line-through; }

        /* Card wrapper */
        .dp-rm-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            overflow: hidden;
        }

        /* Plugin items */
        .dp-rm-plugin-desc { font-size: 11px; color: #888; margin-top: 2px; }

        /* Buttons */
        .dp-rm-btn {
            margin-top: 16px; background: #281E5D; color: #fff; border: none;
            border-radius: 6px; padding: 8px 24px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background 0.2s;
        }
        .dp-rm-btn:hover { background: #4a3a8a; }
        .dp-rm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Toast */
        .dp-rm-toast {
            display: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
            background: #00a32a; color: #fff; margin-top: 12px;
        }
    </style>

    <!-- Tabs -->
    <div class="dp-rm-tabs">
        <div class="dp-rm-tab active" data-tab="menus">
            <span class="dashicons dashicons-menu" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
            Menu Beheer
        </div>
        <div class="dp-rm-tab" data-tab="plugins">
            <span class="dashicons dashicons-admin-plugins" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
            Plugin Zichtbaarheid
        </div>
    </div>

    <!-- Panel: Menu Beheer -->
    <div class="dp-rm-panel active" id="dp-rm-menus">
        <div class="dp-rm-role-bar">
            <label for="dp-rm-role">Rol:</label>
            <select id="dp-rm-role">
                <?php foreach ( $all_roles as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="dp-rm-card">
            <div class="dp-rm-list" id="dp-rm-menu-list">
                <?php foreach ( $all_menus as $pos => $item ) :
                    if ( empty( $item[0] ) || empty( $item[2] ) ) continue;
                    $label     = wp_strip_all_tags( $item[0] );
                    $menu_slug = $item[2];
                    if ( $label === '' || $menu_slug === '' ) continue;
                    // Verberg separators
                    if ( strpos( $item[4] ?? '', 'wp-menu-separator' ) !== false ) continue;
                ?>
                    <div class="dp-rm-item" data-type="menu" data-slug="<?php echo esc_attr( $menu_slug ); ?>">
                        <input type="checkbox" class="dp-rm-check">
                        <span class="dp-rm-label"><?php echo esc_html( $label ); ?></span>
                        <span class="dp-rm-slug"><?php echo esc_html( $menu_slug ); ?></span>
                    </div>

                    <?php // Submenu items
                    if ( isset( $all_subs[ $menu_slug ] ) ) :
                        foreach ( $all_subs[ $menu_slug ] as $sub ) :
                            $sub_label = wp_strip_all_tags( $sub[0] ?? '' );
                            $sub_slug  = $sub[2] ?? '';
                            if ( $sub_label === '' || $sub_slug === '' ) continue;
                    ?>
                        <div class="dp-rm-item dp-rm-sub" data-type="submenu" data-parent="<?php echo esc_attr( $menu_slug ); ?>" data-slug="<?php echo esc_attr( $sub_slug ); ?>">
                            <input type="checkbox" class="dp-rm-check">
                            <span class="dp-rm-label"><?php echo esc_html( $sub_label ); ?></span>
                            <span class="dp-rm-slug"><?php echo esc_html( $sub_slug ); ?></span>
                        </div>
                    <?php endforeach; endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="dp-rm-btn" id="dp-rm-save-menus">Opslaan</button>
        <div class="dp-rm-toast" id="dp-rm-toast-menus">Opgeslagen!</div>
    </div>

    <!-- Panel: Plugin Zichtbaarheid -->
    <div class="dp-rm-panel" id="dp-rm-plugins">
        <p style="font-size:13px;color:#666;margin-bottom:16px;">Aangevinkte plugins worden verborgen voor alle niet-administrators.</p>

        <div class="dp-rm-card">
            <div class="dp-rm-list">
                <?php foreach ( $all_plugins as $path => $plugin ) : ?>
                    <div class="dp-rm-item">
                        <input type="checkbox" class="dp-rm-plugin-check" value="<?php echo esc_attr( $path ); ?>"
                            <?php checked( in_array( $path, (array) $hidden_plugins, true ) ); ?>>
                        <div>
                            <span class="dp-rm-label"><?php echo esc_html( $plugin['Name'] ); ?></span>
                            <?php if ( ! empty( $plugin['Description'] ) ) : ?>
                                <div class="dp-rm-plugin-desc"><?php echo esc_html( wp_trim_words( $plugin['Description'], 12 ) ); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="dp-rm-btn" id="dp-rm-save-plugins">Opslaan</button>
        <div class="dp-rm-toast" id="dp-rm-toast-plugins">Opgeslagen!</div>
    </div>

    <script>
    (function() {
        var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';
        var hiddenPerRole = <?php echo json_encode( $hidden_per_role ); ?>;

        // --- Tab switching ---
        document.querySelectorAll('.dp-rm-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.dp-rm-tab').forEach(function(t) { t.classList.remove('active'); });
                document.querySelectorAll('.dp-rm-panel').forEach(function(p) { p.classList.remove('active'); });
                tab.classList.add('active');
                document.getElementById('dp-rm-' + tab.dataset.tab).classList.add('active');
            });
        });

        // --- Rol wisselen: checkboxes updaten ---
        function applyRoleSettings(role) {
            var data = hiddenPerRole[role] || { menus: [], submenus: {} };
            var menus = data.menus || [];
            var subs  = data.submenus || {};

            document.querySelectorAll('#dp-rm-menu-list .dp-rm-item').forEach(function(item) {
                var cb   = item.querySelector('.dp-rm-check');
                var type = item.dataset.type;
                var slug = item.dataset.slug;
                var checked = false;

                if (type === 'menu') {
                    checked = menus.indexOf(slug) !== -1;
                } else if (type === 'submenu') {
                    var parent = item.dataset.parent;
                    checked = subs[parent] && subs[parent].indexOf(slug) !== -1;
                }

                cb.checked = checked;
                item.classList.toggle('dp-rm-hidden', checked);
            });
        }

        // Toggle visuele feedback bij aan/uitvinken
        document.querySelectorAll('#dp-rm-menu-list .dp-rm-check').forEach(function(cb) {
            cb.addEventListener('change', function() {
                this.closest('.dp-rm-item').classList.toggle('dp-rm-hidden', this.checked);
            });
        });

        var roleSelect = document.getElementById('dp-rm-role');
        roleSelect.addEventListener('change', function() { applyRoleSettings(this.value); });
        applyRoleSettings(roleSelect.value);

        // --- Menu opslaan ---
        document.getElementById('dp-rm-save-menus').addEventListener('click', function() {
            var btn  = this;
            var role = roleSelect.value;
            var fd   = new FormData();
            fd.append('action', 'dp_toolbox_rm_save_menus');
            fd.append('nonce', nonce);
            fd.append('role', role);

            document.querySelectorAll('#dp-rm-menu-list .dp-rm-item').forEach(function(item) {
                var cb = item.querySelector('.dp-rm-check');
                if (!cb.checked) return;

                if (item.dataset.type === 'menu') {
                    fd.append('hidden_menus[]', item.dataset.slug);
                } else {
                    fd.append('hidden_submenus[' + item.dataset.parent + '][]', item.dataset.slug);
                }
            });

            btn.disabled = true;
            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                if (res.success) {
                    // Update lokale data
                    var menus = [], subs = {};
                    document.querySelectorAll('#dp-rm-menu-list .dp-rm-item').forEach(function(item) {
                        if (!item.querySelector('.dp-rm-check').checked) return;
                        if (item.dataset.type === 'menu') menus.push(item.dataset.slug);
                        else {
                            if (!subs[item.dataset.parent]) subs[item.dataset.parent] = [];
                            subs[item.dataset.parent].push(item.dataset.slug);
                        }
                    });
                    hiddenPerRole[role] = { menus: menus, submenus: subs };

                    var toast = document.getElementById('dp-rm-toast-menus');
                    toast.style.display = 'inline-block';
                    setTimeout(function() { toast.style.display = 'none'; }, 2000);
                }
            });
        });

        // --- Plugins opslaan ---
        document.getElementById('dp-rm-save-plugins').addEventListener('click', function() {
            var btn = this;
            var fd  = new FormData();
            fd.append('action', 'dp_toolbox_rm_save_plugins');
            fd.append('nonce', nonce);

            document.querySelectorAll('.dp-rm-plugin-check:checked').forEach(function(cb) {
                fd.append('hidden_plugins[]', cb.value);
            });

            btn.disabled = true;
            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                if (res.success) {
                    var toast = document.getElementById('dp-rm-toast-plugins');
                    toast.style.display = 'inline-block';
                    setTimeout(function() { toast.style.display = 'none'; }, 2000);
                }
            });
        });
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}
