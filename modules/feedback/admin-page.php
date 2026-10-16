<?php
/**
 * Feedback — overzicht in het DP Toolbox-paneel.
 *
 * Gebaseerd op het opmerkingenoverzicht van Pencilino by Rino de Boer
 * (https://github.com/rinothecoder/pencilino), GPL-2.0-or-later.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/*  Menu: eigen item in de DP Toolbox-zijbalk, tussen Modules en       */
/*  DP Plugins, met een teller voor open feedback.                     */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
    if ( ! function_exists( 'dp_toolbox_current_user_has_access' ) || ! dp_toolbox_current_user_has_access() ) {
        return;
    }

    dp_toolbox_fb_ensure_table();
    $open  = dp_toolbox_fb_aantallen()['open'];
    $label = 'Feedback' . ( $open ? ' <span class="update-plugins count-' . $open . '"><span class="plugin-count">' . $open . '</span></span>' : '' );

    add_submenu_page( 'dp-toolbox', 'Feedback', $label, 'manage_options', 'dp-feedback', 'dp_toolbox_fb_pagina' );
}, 20 );

/**
 * DP Toolbox sorteert zijn submenu alfabetisch (prioriteit 999), waardoor "Feedback" achter
 * "DP Plugins" zou belanden. Hier zetten we het direct onder "Modules".
 */
add_action( 'admin_menu', function () {
    global $submenu;

    if ( empty( $submenu['dp-toolbox'] ) ) {
        return;
    }

    foreach ( $submenu['dp-toolbox'] as $i => $item ) {
        if ( 'dp-feedback' === ( $item[2] ?? '' ) ) {
            unset( $submenu['dp-toolbox'][ $i ] );
            array_splice( $submenu['dp-toolbox'], 1, 0, [ $item ] );
            $submenu['dp-toolbox'] = array_values( $submenu['dp-toolbox'] );
            return;
        }
    }
}, 1000 );

function dp_toolbox_fb_pagina() {
    // Het menu verbergen blokkeert het adres niet: net als de DP Toolbox-pagina zelf hier ook controleren.
    if ( ! dp_toolbox_current_user_has_access() ) {
        wp_die( 'Je hebt geen toegang tot deze pagina.' );
    }

    dp_toolbox_page_start( 'Feedback', 'Wat klanten op de website hebben aangewezen — afvinken zodra het is opgelost.' );
    dp_toolbox_fb_render_inline();
    dp_toolbox_page_end();
}

function dp_toolbox_fb_render_inline() {
    dp_toolbox_fb_ensure_table();

    $tellers   = dp_toolbox_fb_aantallen();
    $open      = dp_toolbox_fb_lijst( false, 100 );
    $klaar     = dp_toolbox_fb_lijst( true, 100 );
    $rollen    = dp_toolbox_fb_rollen();
    $migratie  = get_option( 'dp_toolbox_fb_pencilino_overgenomen' );
    $alle_rollen = wp_roles()->roles;
    ?>
    <style>
        .dp-fb-stats { display: flex; gap: 12px; margin-bottom: 16px; }
        .dp-fb-stat { flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center; }
        .dp-fb-stat b { display: block; font-size: 24px; color: #281E5D; line-height: 1; margin-bottom: 4px; }
        .dp-fb-stat span { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
        .dp-fb-tabs { display: flex; gap: 6px; margin-bottom: 12px; }
        .dp-fb-tab { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 6px 12px; font-size: 13px; cursor: pointer; color: #1d2327; }
        .dp-fb-tab.is-actief { background: #281E5D; border-color: #281E5D; color: #fff; }
        .dp-fb-lijst { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .dp-fb-item { padding: 14px 16px; border-top: 1px solid #f0f0f1; display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; }
        .dp-fb-item:first-child { border-top: 0; }
        .dp-fb-item h4 { margin: 0; font-size: 13px; color: #281E5D; }
        .dp-fb-item h4 small { font-weight: 400; color: #8c8f94; margin-left: 6px; }
        .dp-fb-tekst { grid-column: 1; margin: 0; font-size: 14px; line-height: 1.55; color: #1d2327; white-space: pre-wrap; }
        .dp-fb-meta { grid-column: 1; margin: 0; font-size: 12px; color: #646970; }
        .dp-fb-acties { grid-column: 2; grid-row: 1 / span 3; display: flex; flex-direction: column; gap: 6px; align-items: flex-end; }
        .dp-fb-mini { background: none; border: none; color: #281E5D; font-size: 12px; font-weight: 600; cursor: pointer; padding: 4px 8px; border-radius: 4px; text-decoration: none; }
        .dp-fb-mini:hover { background: #f0ecff; color: #281E5D; }
        .dp-fb-mini.verwijder { color: #b32d2e; }
        .dp-fb-mini.verwijder:hover { background: #fce9e9; }
        .dp-fb-leeg { padding: 28px 16px; text-align: center; color: #666; font-size: 13px; margin: 0; }
        .dp-fb-instellingen { margin-top: 22px; padding: 16px; background: #f8f7fc; border-radius: 8px; }
        .dp-fb-instellingen h4 { margin: 0 0 4px; font-size: 13px; }
        .dp-fb-instellingen p { margin: 0 0 10px; font-size: 12px; color: #646970; }
        .dp-fb-rollen { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-bottom: 12px; }
        .dp-fb-rollen label { font-size: 13px; }
        .dp-fb-noot { font-size: 12px; color: #646970; margin: 10px 0 0; }
    </style>

    <div class="dp-fb-stats">
        <div class="dp-fb-stat"><b data-teller="open"><?php echo (int) $tellers['open']; ?></b><span>Open</span></div>
        <div class="dp-fb-stat"><b data-teller="afgehandeld"><?php echo (int) $tellers['afgehandeld']; ?></b><span>Afgehandeld</span></div>
    </div>

    <div class="dp-fb-tabs" role="tablist">
        <button type="button" class="dp-fb-tab is-actief" data-tab="open" role="tab" aria-selected="true">Open</button>
        <button type="button" class="dp-fb-tab" data-tab="afgehandeld" role="tab" aria-selected="false">Afgehandeld</button>
    </div>

    <?php foreach ( [ 'open' => $open, 'afgehandeld' => $klaar ] as $soort => $rijen ) : ?>
        <div class="dp-fb-lijst" data-paneel="<?php echo esc_attr( $soort ); ?>"<?php echo 'open' === $soort ? '' : ' hidden'; ?>>
            <?php if ( empty( $rijen ) ) : ?>
                <p class="dp-fb-leeg"><?php echo 'open' === $soort ? 'Geen open feedback. Zodra iemand op de website iets aanwijst, staat het hier.' : 'Nog niets afgehandeld.'; ?></p>
            <?php endif; ?>
            <?php foreach ( $rijen as $rij ) :
                $item = dp_toolbox_fb_naar_array( $rij );
                $link = add_query_arg( 'dp-feedback', $item['id'], $item['pagina'] );
                ?>
                <div class="dp-fb-item" data-id="<?php echo (int) $item['id']; ?>">
                    <h4><?php echo esc_html( $item['doel']['label'] ?? '' ?: 'Element op de pagina' ); ?><small>#<?php echo (int) $item['id']; ?></small></h4>
                    <p class="dp-fb-tekst"><?php echo esc_html( $item['tekst'] ); ?></p>
                    <p class="dp-fb-meta"><?php echo esc_html( $item['auteur'] . ' · ' . ( $item['paginatitel'] ?: $item['pagina'] ) . ' · ' . $item['datum'] ); ?></p>
                    <div class="dp-fb-acties">
                        <a class="dp-fb-mini" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">Bekijk op de site</a>
                        <button type="button" class="dp-fb-mini dp-fb-status" data-afgehandeld="<?php echo 'open' === $soort ? '1' : '0'; ?>"><?php echo 'open' === $soort ? 'Afvinken' : 'Heropenen'; ?></button>
                        <button type="button" class="dp-fb-mini verwijder dp-fb-weg">Verwijderen</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="dp-fb-instellingen">
        <h4>Wie mag feedback geven?</h4>
        <p>Ingelogde gebruikers met deze rollen zien op de website de knop <strong>Feedback geven</strong>. Beheerders mogen het altijd; afvinken en verwijderen kan alleen een beheerder.</p>
        <div class="dp-fb-rollen">
            <?php foreach ( $alle_rollen as $slug => $rol ) : ?>
                <label>
                    <input type="checkbox" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rollen, true ) || 'administrator' === $slug ); ?> <?php disabled( 'administrator' === $slug ); ?>>
                    <?php echo esc_html( translate_user_role( $rol['name'] ) ); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="dp-fb-noot" style="margin:0 0 12px;">
            De rol <strong>Klant</strong> is gemaakt door deze module. Een klant kan alleen inloggen en feedback geven: geen toegang tot
            wp-admin en geen adminbalk, en na het inloggen komt hij direct op de website uit. Maak een klant aan via
            <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Gebruikers → Nieuwe gebruiker</a> en kies de rol Klant.
        </p>
        <button type="button" class="button button-primary" id="dp-fb-rollen-opslaan">Opslaan</button>
        <span id="dp-fb-rollen-melding" style="margin-left:8px;font-size:12px;color:#646970;"></span>
        <?php if ( is_array( $migratie ) && ! empty( $migratie['aantal'] ) ) : ?>
            <p class="dp-fb-noot"><?php echo esc_html( sprintf( '%d opmerking(en) overgenomen uit Pencilino op %s.', (int) $migratie['aantal'], wp_date( 'j F Y', (int) $migratie['op'] ) ) ); ?></p>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var nonce = '<?php echo esc_js( wp_create_nonce( 'dp_toolbox_fb' ) ); ?>';
        var wrap  = document.currentScript.parentNode;

        function post(action, data, klaar) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.keys(data).forEach(function (k) {
                [].concat(data[k]).forEach(function (v) { body.append(Array.isArray(data[k]) ? k + '[]' : k, v); });
            });
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(klaar)
                .catch(function () { alert('Er ging iets mis. Probeer het opnieuw.'); });
        }

        function teller(soort, verschil) {
            var el = wrap.querySelector('[data-teller="' + soort + '"]');
            if (el) { el.textContent = Math.max(0, parseInt(el.textContent, 10) + verschil); }
        }

        wrap.addEventListener('click', function (e) {
            var knop = e.target.closest('button');
            if (!knop || !wrap.contains(knop)) { return; }

            if (knop.dataset.tab) {
                wrap.querySelectorAll('.dp-fb-tab').forEach(function (t) {
                    var aan = t === knop;
                    t.classList.toggle('is-actief', aan);
                    t.setAttribute('aria-selected', aan ? 'true' : 'false');
                });
                wrap.querySelectorAll('[data-paneel]').forEach(function (p) { p.hidden = p.dataset.paneel !== knop.dataset.tab; });
                return;
            }

            var item = knop.closest('.dp-fb-item');

            if (knop.classList.contains('dp-fb-status')) {
                var afgehandeld = knop.dataset.afgehandeld === '1';
                knop.disabled = true;
                post('dp_toolbox_fb_status', { id: item.dataset.id, afgehandeld: afgehandeld ? '1' : '' }, function (res) {
                    knop.disabled = false;
                    if (!res.success) { alert(res.data || 'Mislukt.'); return; }
                    var doel = wrap.querySelector('[data-paneel="' + (afgehandeld ? 'afgehandeld' : 'open') + '"]');
                    var leeg = doel.querySelector('.dp-fb-leeg');
                    if (leeg) { leeg.remove(); }
                    knop.dataset.afgehandeld = afgehandeld ? '0' : '1';
                    knop.textContent = afgehandeld ? 'Heropenen' : 'Afvinken';
                    doel.prepend(item);
                    teller('open', afgehandeld ? -1 : 1);
                    teller('afgehandeld', afgehandeld ? 1 : -1);
                });
            }

            if (knop.classList.contains('dp-fb-weg')) {
                if (!confirm('Deze feedback definitief verwijderen?')) { return; }
                post('dp_toolbox_fb_verwijderen', { id: item.dataset.id }, function (res) {
                    if (!res.success) { alert(res.data || 'Mislukt.'); return; }
                    teller(item.closest('[data-paneel]').dataset.paneel, -1);
                    item.remove();
                });
            }

            if (knop.id === 'dp-fb-rollen-opslaan') {
                var rollen = Array.prototype.map.call(wrap.querySelectorAll('.dp-fb-rollen input:checked:not(:disabled)'), function (i) { return i.value; });
                var melding = wrap.querySelector('#dp-fb-rollen-melding');
                post('dp_toolbox_fb_rollen', { rollen: rollen.length ? rollen : [''] }, function (res) {
                    melding.textContent = res.success ? 'Opgeslagen.' : (res.data || 'Mislukt.');
                    setTimeout(function () { melding.textContent = ''; }, 2500);
                });
            }
        });
    })();
    </script>
    <?php
}
