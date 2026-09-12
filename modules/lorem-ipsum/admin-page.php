<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'lorem-ipsum', 'dp_toolbox_ipsum_render_inline', [
            'title'       => 'Lorem ipsum',
            'description' => 'Opvultekst invoegen met een code, en zien waar hij nog staat.',
        ] );
    }
} );

/* "Opnieuw zoeken": de bewaarde uitkomst weggooien. */
add_action( 'admin_post_dp_toolbox_ipsum_zoek', function () {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dp_toolbox_ipsum_zoek' ) ) {
        wp_die( 'Geen toegang.' );
    }
    delete_transient( 'dp_toolbox_opvultekst' );
    wp_safe_redirect( admin_url( 'admin.php?page=dp-toolbox#settings-lorem-ipsum' ) );
    exit;
} );

function dp_toolbox_ipsum_render_inline() {
    $plekken = dp_toolbox_opvultekst_vindplaatsen();
    $codes   = [
        '{ipsum1s}' => 'één zin',
        '{ipsum3s}' => 'drie zinnen',
        '{ipsum5w}' => 'vijf woorden, voor een kop of knop',
        '{ipsum2p}' => 'twee alinea\'s',
    ];
    ?>
    <style>
        .dp-li-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px; }
        .dp-li-card + .dp-li-card { margin-top: 16px; }
        .dp-li-card h4 { margin: 0 0 10px; font-size: 14px; color: #1d2327; }
        .dp-li-card p { margin: 0 0 12px; color: #50575e; line-height: 1.55; }
        .dp-li-codes { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0 0 14px; }
        .dp-li-codes code { background: #f8f7fc; color: #281E5D; padding: 2px 8px; border-radius: 4px; font-size: 13px; }
        .dp-li-codes span { color: #50575e; align-self: center; }
        .dp-li-hint { font-size: 12px; color: #888; margin: 0 !important; }
        .dp-li-kop { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .dp-li-kop h4 { margin: 0; }
        .dp-li-teller { display: inline-block; min-width: 22px; padding: 1px 8px; border-radius: 10px; background: #fcf0f1; color: #b32d2e; font-weight: 600; text-align: center; }
        .dp-li-teller.is-nul { background: #edfaef; color: #00794c; }
        .dp-li-lijst { width: 100%; border-collapse: collapse; }
        .dp-li-lijst th, .dp-li-lijst td { padding: 8px 10px; border-top: 1px solid #f0f0f1; text-align: left; vertical-align: top; font-size: 13px; }
        .dp-li-lijst th { color: #888; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; border-top: 0; }
        .dp-li-waar { color: #888; }
        .dp-li-btn { background: #fff; color: #281E5D; border: 1px solid #281E5D; border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .dp-li-btn:hover { background: #281E5D; color: #fff; }
    </style>

    <div class="dp-li-card">
        <h4>Opvultekst invoegen</h4>
        <p>Typ een code in een willekeurig tekstveld. Zodra je de sluitaccolade typt, staat de tekst er, elke keer met andere zinnen.</p>
        <div class="dp-li-codes">
            <?php foreach ( $codes as $code => $uitleg ) : ?>
                <code><?php echo esc_html( $code ); ?></code><span><?php echo esc_html( $uitleg ); ?></span>
            <?php endforeach; ?>
        </div>
        <p class="dp-li-hint">Maximaal 20. Alinea's worden echte alinea's in teksteditors (Gutenberg, klassieke editor, Bricks-teksteditor); in een veld van één regel of een kop wordt het doorlopende tekst. Werkt voor beheerders, in wp-admin en in de Bricks-builder. Niet in code-editors.</p>
    </div>

    <div class="dp-li-card">
        <div class="dp-li-kop">
            <h4>Nog opvultekst op de site <span class="dp-li-teller<?php echo $plekken ? '' : ' is-nul'; ?>"><?php echo (int) count( $plekken ); ?></span></h4>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="dp_toolbox_ipsum_zoek">
                <?php wp_nonce_field( 'dp_toolbox_ipsum_zoek' ); ?>
                <button type="submit" class="dp-li-btn">Opnieuw zoeken</button>
            </form>
        </div>
        <?php if ( ! $plekken ) : ?>
            <p>Geen opvultekst gevonden in pagina's, producten, velden, menu's, termen of instellingen.</p>
        <?php else : ?>
            <table class="dp-li-lijst">
                <thead><tr><th>Waar</th><th>Soort</th><th>In</th></tr></thead>
                <tbody>
                <?php foreach ( $plekken as $p ) : ?>
                    <tr>
                        <td><?php if ( $p['link'] ) : ?><a href="<?php echo esc_url( $p['link'] ); ?>"><?php echo esc_html( $p['titel'] ); ?></a><?php else : echo esc_html( $p['titel'] ); endif; ?></td>
                        <td><?php echo esc_html( $p['soort'] ); ?></td>
                        <td class="dp-li-waar"><?php echo esc_html( implode( ', ', $p['waar'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="dp-li-hint" style="margin-top:12px !important;">Zoekt naar typische lorem-ipsumwoorden. Ook opvultekst die niet met deze module is ingevoegd (bijvoorbeeld van Quick Setup of een sjabloon) staat hier. Staat ook in de Oplevercheck.</p>
    </div>
    <?php
}
