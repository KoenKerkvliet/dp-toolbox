<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'not-found', 'dp_toolbox_404_render_inline', [
            'title'       => 'Niet Gevonden (404)',
            'description' => 'Wat bezoekers zochten en niet vonden — met één klik om te leiden.',
        ] );
    }
} );

function dp_toolbox_404_render_inline() {
    dp_toolbox_404_ensure_table();

    $regels      = dp_toolbox_404_regels( false, 100 );
    $tellers     = dp_toolbox_404_aantallen();
    $redirects_aan = function_exists( 'dp_toolbox_is_module_enabled' ) && dp_toolbox_is_module_enabled( 'redirects' );
    ?>
    <style>
        .dp-nf-stats { display: flex; gap: 12px; margin-bottom: 16px; }
        .dp-nf-stat { flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center; }
        .dp-nf-stat b { display: block; font-size: 24px; color: #281E5D; line-height: 1; margin-bottom: 4px; }
        .dp-nf-stat span { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
        .dp-nf-tabel { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .dp-nf-tabel table { width: 100%; border-collapse: collapse; }
        .dp-nf-tabel th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
            color: #666; padding: 10px 16px; background: #f6f7f7; border-bottom: 1px solid #e0e0e0; font-weight: 600; }
        .dp-nf-tabel td { padding: 10px 16px; font-size: 13px; border-top: 1px solid #f0f0f1; vertical-align: middle; }
        .dp-nf-pad { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;
            word-break: break-all; color: #1d2327; }
        .dp-nf-ref { display: block; font-size: 11px; color: #999; margin-top: 3px; word-break: break-all; }
        .dp-nf-hits { text-align: center; font-weight: 600; color: #281E5D; white-space: nowrap; }
        .dp-nf-tijd { color: #646970; white-space: nowrap; font-size: 12px; }
        .dp-nf-acties { text-align: right; white-space: nowrap; }
        .dp-nf-form { display: none; }
        .dp-nf-form.is-open { display: table-row; }
        .dp-nf-form td { background: #f8f7fc; }
        .dp-nf-form .dp-nf-veld { display: flex; gap: 8px; align-items: center; }
        .dp-nf-form input[type="text"] { flex: 1; border-radius: 6px; border: 1px solid #c3c4c7; padding: 7px 10px; font-size: 13px; }
        .dp-nf-leeg { padding: 28px 16px; text-align: center; color: #666; font-size: 13px; }
        .dp-nf-melding { margin: 12px 0 0; font-size: 12px; }
        .dp-nf-waarschuwing { background: #fcf9e8; border-left: 3px solid #dba617; border-radius: 0 6px 6px 0;
            padding: 10px 12px; font-size: 12px; line-height: 1.6; margin-bottom: 14px; }
        .dp-nf-mini { background: none; border: none; color: #281E5D; font-size: 12px; font-weight: 600;
            cursor: pointer; padding: 4px 8px; border-radius: 4px; }
        .dp-nf-mini:hover { background: #f0ecff; }
        .dp-nf-mini.verwijder { color: #b32d2e; }
        .dp-nf-mini.verwijder:hover { background: #fce9e9; }
    </style>

    <?php if ( ! $redirects_aan ) : ?>
        <div class="dp-nf-waarschuwing">
            De module <strong>Redirects</strong> staat uit. Je kunt hier wel zien wat er misgaat, maar
            pas met die module aan kun je er een omleiding van maken.
        </div>
    <?php endif; ?>

    <div class="dp-nf-stats">
        <div class="dp-nf-stat"><b><?php echo (int) $tellers['open']; ?></b><span>Open adressen</span></div>
        <div class="dp-nf-stat"><b><?php echo (int) $tellers['hits']; ?></b><span>Keer misgelopen</span></div>
        <div class="dp-nf-stat"><b><?php echo (int) $tellers['opgelost']; ?></b><span>Omgeleid</span></div>
    </div>

    <div class="dp-nf-tabel">
        <?php if ( empty( $regels ) ) : ?>
            <p class="dp-nf-leeg">Nog niets misgelopen. Zodra een bezoeker een adres opvraagt dat niet bestaat, verschijnt het hier.</p>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>Adres</th>
                        <th style="text-align:center;width:70px;">Keer</th>
                        <th style="width:130px;">Laatst</th>
                        <th style="width:150px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $regels as $r ) : ?>
                    <tr data-rij="<?php echo (int) $r['id']; ?>">
                        <td>
                            <span class="dp-nf-pad"><?php echo esc_html( $r['path'] ); ?></span>
                            <?php if ( ! empty( $r['referer'] ) ) : ?>
                                <span class="dp-nf-ref">via <?php echo esc_html( $r['referer'] ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="dp-nf-hits"><?php echo (int) $r['hits']; ?></td>
                        <td class="dp-nf-tijd" title="<?php echo esc_attr( wp_date( 'j F Y, H:i', strtotime( $r['last_seen'] . ' UTC' ) ) ); ?>">
                            <?php echo esc_html( human_time_diff( strtotime( $r['last_seen'] . ' UTC' ), time() ) ); ?> geleden
                        </td>
                        <td class="dp-nf-acties">
                            <?php if ( $redirects_aan ) : ?>
                                <button type="button" class="dp-nf-mini dp-nf-open">Omleiden</button>
                            <?php endif; ?>
                            <button type="button" class="dp-nf-mini verwijder dp-nf-weg">Wissen</button>
                        </td>
                    </tr>
                    <tr class="dp-nf-form" data-form="<?php echo (int) $r['id']; ?>">
                        <td colspan="4">
                            <div class="dp-nf-veld">
                                <span style="font-size:12px;color:#666;">Stuur naar</span>
                                <input type="text" class="dp-nf-doel" placeholder="/nieuwe-pagina/ of https://..." value="">
                                <button type="button" class="button button-primary dp-nf-opslaan">Opslaan</button>
                                <button type="button" class="dp-nf-mini dp-nf-annuleer">Annuleren</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $regels ) ) : ?>
        <p class="dp-nf-melding">
            <button type="button" class="dp-nf-mini verwijder" id="dp-nf-alles">Hele lijst wissen</button>
        </p>
    <?php endif; ?>

    <script>
    (function () {
        var nonce = '<?php echo esc_js( wp_create_nonce( 'dp_toolbox_404' ) ); ?>';
        var wrap  = document.currentScript.parentNode;

        function post(action, data, klaar) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.keys(data).forEach(function (k) { body.append(k, data[k]); });

            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(klaar)
                .catch(function () { alert('Er ging iets mis. Probeer het opnieuw.'); });
        }

        wrap.addEventListener('click', function (e) {
            var knop = e.target;

            if (knop.classList.contains('dp-nf-open')) {
                var id = knop.closest('tr').dataset.rij;
                var form = wrap.querySelector('.dp-nf-form[data-form="' + id + '"]');
                form.classList.add('is-open');
                form.querySelector('.dp-nf-doel').focus();
            }

            if (knop.classList.contains('dp-nf-annuleer')) {
                knop.closest('.dp-nf-form').classList.remove('is-open');
            }

            if (knop.classList.contains('dp-nf-opslaan')) {
                var rij  = knop.closest('.dp-nf-form');
                var doel = rij.querySelector('.dp-nf-doel').value.trim();
                if (!doel) { rij.querySelector('.dp-nf-doel').focus(); return; }

                knop.disabled = true;
                post('dp_toolbox_404_redirect', { id: rij.dataset.form, to: doel }, function (res) {
                    knop.disabled = false;
                    if (!res.success) { alert(res.data || 'Mislukt.'); return; }
                    var origineel = wrap.querySelector('tr[data-rij="' + rij.dataset.form + '"]');
                    if (origineel) { origineel.remove(); }
                    rij.remove();
                });
            }

            if (knop.classList.contains('dp-nf-weg')) {
                var tr = knop.closest('tr');
                post('dp_toolbox_404_verwijderen', { id: tr.dataset.rij }, function () {
                    var form = wrap.querySelector('.dp-nf-form[data-form="' + tr.dataset.rij + '"]');
                    if (form) { form.remove(); }
                    tr.remove();
                });
            }

            if (knop.id === 'dp-nf-alles') {
                if (!confirm('De hele lijst wissen? De omleidingen die je al gemaakt hebt blijven bestaan.')) { return; }
                post('dp_toolbox_404_verwijderen', { id: 0 }, function () { location.reload(); });
            }
        });
    })();
    </script>
    <?php
}
