<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    if ( function_exists( 'dp_toolbox_register_module_settings' ) ) {
        dp_toolbox_register_module_settings( 'last-login', 'dp_toolbox_ll_render_inline', [
            'title'       => 'Laatste Login',
            'description' => 'Wie logt er in, en wie nog nooit?',
        ] );
    }
} );

function dp_toolbox_ll_render_inline() {
    $sinds  = dp_toolbox_ll_since();
    $nooit  = dp_toolbox_ll_aantal_nooit();
    $totaal = (int) count_users()['total_users'];
    $wel    = max( 0, $totaal - $nooit );

    // Meest recente en langst afwezige gebruikers.
    $recent = get_users( [
        'meta_key' => DP_TOOLBOX_LL_META,
        'orderby'  => 'meta_value_num',
        'order'    => 'DESC',
        'number'   => 5,
    ] );
    ?>
    <style>
        .dp-ll-stats { display: flex; gap: 12px; margin-bottom: 16px; }
        .dp-ll-stat { flex: 1; background: #f8f7fc; border-radius: 8px; padding: 14px; text-align: center; }
        .dp-ll-stat b { display: block; font-size: 24px; color: #281E5D; line-height: 1; margin-bottom: 4px; }
        .dp-ll-stat span { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        .dp-ll-stat.waarschuwing b { color: #b32d2e; }
        .dp-ll-lijst { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .dp-ll-lijst table { width: 100%; border-collapse: collapse; }
        .dp-ll-lijst td { padding: 9px 16px; font-size: 13px; border-top: 1px solid #f0f0f1; }
        .dp-ll-lijst tr:first-child td { border-top: none; }
        .dp-ll-lijst td:last-child { text-align: right; color: #646970; }
        .dp-ll-note { font-size: 12px; color: #666; line-height: 1.6; margin: 12px 0 0; }
    </style>

    <div class="dp-ll-stats">
        <div class="dp-ll-stat"><b><?php echo (int) $wel; ?></b><span>Ingelogd geweest</span></div>
        <div class="dp-ll-stat <?php echo $nooit ? 'waarschuwing' : ''; ?>"><b><?php echo (int) $nooit; ?></b><span>Nog niet</span></div>
        <div class="dp-ll-stat"><b><?php echo (int) $totaal; ?></b><span>Gebruikers</span></div>
    </div>

    <?php if ( $recent ) : ?>
        <div class="dp-ll-lijst">
            <table>
                <?php foreach ( $recent as $u ) :
                    $ts = (int) get_user_meta( $u->ID, DP_TOOLBOX_LL_META, true );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $u->display_name ); ?></strong></td>
                        <td title="<?php echo esc_attr( wp_date( 'j F Y, H:i', $ts ) ); ?>">
                            <?php echo esc_html( human_time_diff( $ts, time() ) ); ?> geleden
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <p class="dp-ll-note">
        De volledige lijst staat bij <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Gebruikers</a>,
        met een sorteerbare kolom en een snelfilter op wie nog niet is ingelogd.
        <?php if ( $sinds ) : ?>
            <br>Bijgehouden sinds <?php echo esc_html( wp_date( 'j F Y', $sinds ) ); ?>. Wie daarvoor inlogde en
            sindsdien niet meer, telt hier als &ldquo;nog niet&rdquo; — behalve als er toen nog een sessie openstond,
            die hebben we bij het aanzetten uitgelezen.
        <?php endif; ?>
    </p>
    <?php
}
