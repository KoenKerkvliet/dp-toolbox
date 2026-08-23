<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'mail-log', 'dp_toolbox_maillog_render_inline', [
            'title'       => 'Mail Log',
            'description' => 'Welke mail de site verstuurde, en of het lukte.',
        ] );
    }
} );

function dp_toolbox_maillog_render_inline() {
    dp_toolbox_maillog_ensure_table();

    $regels  = dp_toolbox_maillog_regels( 50 );
    $tellers = dp_toolbox_maillog_aantallen();
    ?>
    <style>
        .dp-ml-log-stats { display: flex; gap: 12px; margin-bottom: 16px; }
        .dp-ml-log-stat { flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center; }
        .dp-ml-log-stat b { display: block; font-size: 24px; color: #281E5D; line-height: 1; margin-bottom: 4px; }
        .dp-ml-log-stat span { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
        .dp-ml-log-stat.fout b { color: #b32d2e; }
        .dp-ml-log-tabel { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .dp-ml-log-tabel table { width: 100%; border-collapse: collapse; }
        .dp-ml-log-tabel th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
            color: #666; padding: 10px 16px; background: #f6f7f7; border-bottom: 1px solid #e0e0e0; font-weight: 600; }
        .dp-ml-log-tabel td { padding: 10px 16px; font-size: 13px; border-top: 1px solid #f0f0f1; vertical-align: top; }
        .dp-ml-log-stip { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 7px; }
        .dp-ml-log-stip.ok { background: #00a32a; }
        .dp-ml-log-stip.fail { background: #d63638; }
        .dp-ml-log-ontv { font-size: 12px; color: #646970; word-break: break-all; }
        .dp-ml-log-fout { display: block; margin-top: 4px; font-size: 11px; color: #b32d2e;
            background: #fce9e9; border-radius: 4px; padding: 6px 8px; line-height: 1.5; }
        .dp-ml-log-tijd { white-space: nowrap; color: #646970; font-size: 12px; text-align: right; }
        .dp-ml-log-leeg { padding: 28px 16px; text-align: center; color: #666; font-size: 13px; }
        .dp-ml-log-note { font-size: 12px; color: #666; line-height: 1.6; margin: 12px 0 0;
            display: flex; align-items: center; gap: 12px; justify-content: space-between; }
        .dp-ml-log-wis { background: none; border: none; color: #b32d2e; font-size: 12px; font-weight: 600;
            cursor: pointer; padding: 4px 8px; border-radius: 4px; }
        .dp-ml-log-wis:hover { background: #fce9e9; }
    </style>

    <div class="dp-ml-log-stats">
        <div class="dp-ml-log-stat"><b><?php echo (int) $tellers['dag']; ?></b><span>Laatste 24 uur</span></div>
        <div class="dp-ml-log-stat <?php echo $tellers['fouten'] ? 'fout' : ''; ?>"><b><?php echo (int) $tellers['fouten']; ?></b><span>Mislukt</span></div>
        <div class="dp-ml-log-stat"><b><?php echo (int) $tellers['totaal']; ?></b><span>In het log</span></div>
    </div>

    <div class="dp-ml-log-tabel">
        <?php if ( empty( $regels ) ) : ?>
            <p class="dp-ml-log-leeg">
                Nog geen mail geregistreerd. Zodra de site iets verstuurt — een formulier, een
                wachtwoordreset, een inloglink — verschijnt het hier.
            </p>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>Onderwerp en ontvanger</th>
                        <th style="width:130px;text-align:right;">Wanneer</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $regels as $r ) :
                    $mislukt = 'fail' === $r['status'];
                ?>
                    <tr>
                        <td>
                            <span class="dp-ml-log-stip <?php echo $mislukt ? 'fail' : 'ok'; ?>"
                                  title="<?php echo $mislukt ? 'Mislukt' : 'Verstuurd'; ?>"></span>
                            <strong><?php echo esc_html( $r['subject'] ?: '(geen onderwerp)' ); ?></strong>
                            <span class="dp-ml-log-ontv"><?php echo esc_html( $r['recipient'] ); ?></span>
                            <?php if ( $mislukt && ! empty( $r['error'] ) ) : ?>
                                <span class="dp-ml-log-fout"><?php echo esc_html( $r['error'] ); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php $ts = strtotime( $r['created'] . ' UTC' ); ?>
                        <td class="dp-ml-log-tijd"
                            title="<?php echo esc_attr( wp_date( 'j F Y, H:i', $ts ) ); ?>">
                            <?php echo esc_html( human_time_diff( $ts, time() ) ); ?> geleden
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="dp-ml-log-note">
        <span>
            Alleen ontvanger, onderwerp en uitkomst worden bewaard &mdash; nooit de inhoud van de mail.
            De laatste <?php echo (int) DP_TOOLBOX_MAILLOG_MAX; ?> regels blijven staan.
        </span>
        <?php if ( ! empty( $regels ) ) : ?>
            <button type="button" class="dp-ml-log-wis" id="dp-maillog-wis">Log wissen</button>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var knop = document.getElementById('dp-maillog-wis');
        if (!knop) { return; }

        knop.addEventListener('click', function () {
            if (!confirm('Het hele mail-log wissen?')) { return; }

            var body = new URLSearchParams();
            body.append('action', 'dp_toolbox_maillog_wissen');
            body.append('nonce', '<?php echo esc_js( wp_create_nonce( 'dp_toolbox_maillog' ) ); ?>');

            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function () { location.reload(); })
                .catch(function () { alert('Er ging iets mis.'); });
        });
    })();
    </script>
    <?php
}
