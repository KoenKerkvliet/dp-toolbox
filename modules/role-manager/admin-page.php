<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------
 *  Menu registratie
 * ------------------------------------------------------------------ */
/**
 * De Role Manager-UI is samengevoegd in de User Manager (per-user voor admins,
 * per-rol voor overige rollen). De losse inline-UI is daarom uitgeschakeld.
 * De verberg-logica (role-manager.php) en de AJAX-handlers hieronder blijven
 * actief, zodat bestaande per-rol-instellingen blijven werken en de
 * menu-structuur-transient gevuld blijft.
 */

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
 *  AJAX: nieuwe rol aanmaken
 * ------------------------------------------------------------------ */
add_action( 'wp_ajax_dp_toolbox_rm_add_role', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toegang' );
    }
    check_ajax_referer( 'dp_toolbox_role_manager', 'nonce' );

    $slug = strtolower( trim( $_POST['slug'] ?? '' ) );
    $slug = preg_replace( '/[^a-z0-9_]/', '', $slug );
    $slug = substr( $slug, 0, 32 );
    $name = sanitize_text_field( $_POST['name'] ?? '' );
    $clone_from = sanitize_key( $_POST['clone_from'] ?? 'subscriber' );

    if ( $slug === '' ) {
        wp_send_json_error( 'Slug mag niet leeg zijn (alleen a-z, 0-9, underscore).' );
    }
    if ( $name === '' ) {
        wp_send_json_error( 'Naam mag niet leeg zijn.' );
    }
    if ( get_role( $slug ) ) {
        wp_send_json_error( 'Een rol met deze slug bestaat al.' );
    }
    if ( in_array( $slug, [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], true ) ) {
        wp_send_json_error( 'Deze slug is gereserveerd voor WordPress.' );
    }

    $base = get_role( $clone_from );
    $caps = $base ? $base->capabilities : [ 'read' => true ];

    $result = add_role( $slug, $name, $caps );
    if ( null === $result ) {
        wp_send_json_error( 'Aanmaken mislukt.' );
    }

    wp_send_json_success( 'Rol aangemaakt' );
} );

/* ------------------------------------------------------------------
 *  AJAX: rol verwijderen (incl. opschonen instellingen)
 * ------------------------------------------------------------------ */
add_action( 'wp_ajax_dp_toolbox_rm_delete_role', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Geen toegang' );
    }
    check_ajax_referer( 'dp_toolbox_role_manager', 'nonce' );

    $role = sanitize_key( $_POST['role'] ?? '' );
    if ( $role === '' ) {
        wp_send_json_error( 'Geen rol opgegeven' );
    }
    if ( in_array( $role, [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], true ) ) {
        wp_send_json_error( 'WordPress-standaardrollen kunnen niet verwijderd worden.' );
    }
    if ( ! get_role( $role ) ) {
        wp_send_json_error( 'Rol bestaat niet.' );
    }

    remove_role( $role );

    delete_option( 'dp_toolbox_rm_hidden_menus_' . $role );
    delete_option( 'dp_toolbox_rm_hidden_submenus_' . $role );

    wp_send_json_success( 'Rol verwijderd' );
} );

/* ------------------------------------------------------------------
 *  Admin pagina render
 * ------------------------------------------------------------------ */
function dp_toolbox_rm_render_inline() {
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

    // Custom rollen (alle rollen behalve WordPress-standaarden)
    $wp_default_roles = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
    $user_counts      = count_users();
    $custom_roles     = [];
    foreach ( wp_roles()->role_names as $slug => $name ) {
        if ( in_array( $slug, $wp_default_roles, true ) ) continue;
        $custom_roles[ $slug ] = [
            'name'       => $name,
            'user_count' => (int) ( $user_counts['avail_roles'][ $slug ] ?? 0 ),
        ];
    }
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
        .dp-rm-toast.dp-rm-toast-error { background: #d63638; }

        /* Rollen tab specifiek */
        .dp-rm-section-title { margin: 0 0 12px; font-size: 14px; font-weight: 600; color: #1d2327; }
        .dp-rm-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; padding: 16px; }
        .dp-rm-form-field { display: flex; flex-direction: column; }
        .dp-rm-form-field label { font-size: 12px; font-weight: 600; color: #555; margin-bottom: 4px; }
        .dp-rm-form-field input[type="text"],
        .dp-rm-form-field select {
            padding: 6px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; min-width: 180px;
        }
        .dp-rm-form-hint { font-size: 11px; color: #888; margin-top: 4px; }
        .dp-rm-section { margin-top: 24px; }
        .dp-rm-empty { padding: 24px; text-align: center; color: #888; font-size: 13px; }
        .dp-rm-btn-delete {
            background: #fff; color: #d63638; border: 1px solid #d63638;
            padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600;
            cursor: pointer; transition: all 0.2s; flex-shrink: 0;
        }
        .dp-rm-btn-delete:hover { background: #d63638; color: #fff; }
    </style>

    <!-- Tabs -->
    <div class="dp-rm-tabs">
        <div class="dp-rm-tab active" data-tab="roles">
            <span class="dashicons dashicons-businessman" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
            Rollen
        </div>
        <div class="dp-rm-tab" data-tab="menus">
            <span class="dashicons dashicons-menu" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
            Menu Beheer
        </div>
        <div class="dp-rm-tab" data-tab="plugins">
            <span class="dashicons dashicons-admin-plugins" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
            Plugin Zichtbaarheid
        </div>
    </div>

    <!-- Panel: Rollen -->
    <div class="dp-rm-panel active" id="dp-rm-roles">
        <h3 class="dp-rm-section-title">Nieuwe rol aanmaken</h3>
        <div class="dp-rm-card">
            <form class="dp-rm-form" id="dp-rm-new-role-form">
                <div class="dp-rm-form-field">
                    <label for="dp-rm-new-role-slug">Slug (intern)</label>
                    <input type="text" id="dp-rm-new-role-slug" placeholder="fotograaf" pattern="[a-z0-9_]+" maxlength="32" required>
                    <span class="dp-rm-form-hint">a-z, 0-9, underscore</span>
                </div>
                <div class="dp-rm-form-field">
                    <label for="dp-rm-new-role-name">Naam (zichtbaar)</label>
                    <input type="text" id="dp-rm-new-role-name" placeholder="Fotograaf" required>
                    <span class="dp-rm-form-hint">Wat de gebruiker te zien krijgt</span>
                </div>
                <div class="dp-rm-form-field">
                    <label for="dp-rm-new-role-clone">Capabilities kopi&euml;ren van</label>
                    <select id="dp-rm-new-role-clone">
                        <option value="subscriber">Subscriber (alleen lezen)</option>
                        <option value="contributor">Contributor</option>
                        <option value="author">Author</option>
                        <option value="editor">Editor</option>
                    </select>
                    <span class="dp-rm-form-hint">Basisset rechten</span>
                </div>
                <button type="submit" class="dp-rm-btn">Rol aanmaken</button>
            </form>
            <div style="padding: 0 16px 16px;"><div class="dp-rm-toast" id="dp-rm-toast-roles">Rol aangemaakt!</div></div>
        </div>

        <div class="dp-rm-section">
            <h3 class="dp-rm-section-title">Bestaande custom rollen</h3>
            <div class="dp-rm-card">
                <div class="dp-rm-list" id="dp-rm-custom-roles-list">
                    <?php if ( empty( $custom_roles ) ) : ?>
                        <div class="dp-rm-empty">Nog geen custom rollen aangemaakt.</div>
                    <?php else : foreach ( $custom_roles as $slug => $info ) : ?>
                        <div class="dp-rm-item">
                            <div style="flex: 1;">
                                <div class="dp-rm-label"><?php echo esc_html( $info['name'] ); ?></div>
                                <div class="dp-rm-slug"><?php echo esc_html( $slug ); ?> &middot; <?php echo (int) $info['user_count']; ?> gebruiker<?php echo $info['user_count'] === 1 ? '' : 's'; ?></div>
                            </div>
                            <button class="dp-rm-btn-delete" data-role="<?php echo esc_attr( $slug ); ?>" data-users="<?php echo (int) $info['user_count']; ?>">Verwijderen</button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel: Menu Beheer -->
    <div class="dp-rm-panel" id="dp-rm-menus">
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

        // --- Nieuwe rol aanmaken ---
        var newRoleForm = document.getElementById('dp-rm-new-role-form');
        if (newRoleForm) {
            newRoleForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var slugEl  = document.getElementById('dp-rm-new-role-slug');
                var nameEl  = document.getElementById('dp-rm-new-role-name');
                var cloneEl = document.getElementById('dp-rm-new-role-clone');
                var toast   = document.getElementById('dp-rm-toast-roles');
                var btn     = newRoleForm.querySelector('button[type="submit"]');

                var fd = new FormData();
                fd.append('action', 'dp_toolbox_rm_add_role');
                fd.append('nonce', nonce);
                fd.append('slug', slugEl.value.trim());
                fd.append('name', nameEl.value.trim());
                fd.append('clone_from', cloneEl.value);

                btn.disabled = true;
                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    if (res.success) {
                        toast.textContent = 'Rol aangemaakt!';
                        toast.classList.remove('dp-rm-toast-error');
                        toast.style.display = 'inline-block';
                        setTimeout(function() { location.reload(); }, 600);
                    } else {
                        toast.textContent = res.data || 'Aanmaken mislukt.';
                        toast.classList.add('dp-rm-toast-error');
                        toast.style.display = 'inline-block';
                        setTimeout(function() { toast.style.display = 'none'; }, 3500);
                    }
                });
            });
        }

        // --- Rol verwijderen ---
        document.querySelectorAll('.dp-rm-btn-delete').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var role  = this.dataset.role;
                var users = parseInt(this.dataset.users, 10) || 0;
                var msg   = 'Weet je zeker dat je de rol "' + role + '" wilt verwijderen?';
                if (users > 0) {
                    msg += '\n\nLet op: ' + users + ' gebruiker' + (users === 1 ? '' : 's') +
                           ' heeft deze rol. Die behoud' + (users === 1 ? 't' : 'en') +
                           ' geen rol meer en kan' + (users === 1 ? '' : 'nen') + ' niet meer inloggen.\nWijs ze eerst een andere rol toe via Gebruikers.';
                }
                msg += '\n\nDe Role Manager-instellingen voor deze rol worden ook verwijderd.';
                if (!confirm(msg)) return;

                var fd = new FormData();
                fd.append('action', 'dp_toolbox_rm_delete_role');
                fd.append('nonce', nonce);
                fd.append('role', role);

                btn.disabled = true;
                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Verwijderen mislukt.');
                        btn.disabled = false;
                    }
                });
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
}
