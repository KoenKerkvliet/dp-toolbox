<?php
/**
 * Module Name: Terug naar boven
 * Description: Zwevende knop die de bezoeker vloeiend terugbrengt naar de top van de pagina. Herkent DP Chat en gaat er automatisch netjes boven staan.
 * Category: appearance
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const DP_TOOLBOX_BTT_OPTION = 'dp_toolbox_back_to_top';

/**
 * Standaardinstellingen.
 *
 * De maat is 60 zodat de knop even groot is als de launcher van DP Chat; twee
 * cirkels van ongelijke grootte onder elkaar ogen rommelig.
 */
function dp_toolbox_btt_defaults() {
    return [
        'size'        => 60,
        'offset_side' => 24,
        'offset_bottom' => 24,
        'gap'         => 12,
        'radius'      => 50,
        'position'    => 'right',
        'bg'          => '',
        'icon_color'  => '#ffffff',
        'threshold'   => 400,
        'shadow'      => 1,
        'hide_mobile' => 0,
    ];
}

function dp_toolbox_btt_settings() {
    $stored = get_option( DP_TOOLBOX_BTT_OPTION, [] );
    return wp_parse_args( is_array( $stored ) ? $stored : [], dp_toolbox_btt_defaults() );
}

/**
 * Leest de positie van DP Chat uit, zodat deze knop erboven kan gaan staan.
 *
 * Geeft null terug wanneer DP Chat niet actief is of zijn widget niet toont.
 * We lezen bewust de instellingen en niet de DOM: dit moet server-side kloppen
 * omdat de CSS in de pagina wordt meegegeven.
 *
 * @return array|null ['bottom' => int, 'side' => int, 'size' => int, 'position' => string]
 */
function dp_toolbox_btt_detect_chat() {
    if ( ! class_exists( 'DP_Chat_Settings' ) ) {
        return null;
    }
    if ( ! DP_Chat_Settings::get( 'enabled' ) ) {
        return null;
    }

    // DP Chat verbergt zijn widget desgewenst voor ingelogde beheerders.
    if ( DP_Chat_Settings::get( 'hide_for_admins' ) && current_user_can( 'manage_options' ) ) {
        return null;
    }

    return [
        'bottom'   => (int) DP_Chat_Settings::get( 'offset_bottom', 24 ),
        'side'     => (int) DP_Chat_Settings::get( 'offset_side', 24 ),
        'size'     => 60, // vaste maat van de DP Chat-launcher
        'position' => 'left' === DP_Chat_Settings::get( 'position' ) ? 'left' : 'right',
    ];
}

/**
 * Berekent de uiteindelijke plaatsing.
 *
 * Staat DP Chat aan de dezelfde kant, dan schuift deze knop erboven en neemt
 * hij de zijafstand van DP Chat over — anders staan ze niet op één lijn.
 */
function dp_toolbox_btt_placement() {
    $s    = dp_toolbox_btt_settings();
    $chat = dp_toolbox_btt_detect_chat();

    $position = ( 'left' === $s['position'] ) ? 'left' : 'right';
    $side     = (int) $s['offset_side'];
    $bottom   = (int) $s['offset_bottom'];
    $stacked  = false;

    if ( $chat && $chat['position'] === $position ) {
        $side    = $chat['side'];
        $bottom  = $chat['bottom'] + $chat['size'] + (int) $s['gap'];
        $stacked = true;
    }

    return [
        'position' => $position,
        'side'     => max( 0, $side ),
        'bottom'   => max( 0, $bottom ),
        'stacked'  => $stacked,
    ];
}

function dp_toolbox_btt_markup() {
    return '<button type="button" class="dp-totop" aria-label="Terug naar boven">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 19V5M5 12l7-7 7 7"/></svg>'
        . '</button>';
}

function dp_toolbox_btt_css() {
    $s     = dp_toolbox_btt_settings();
    $place = dp_toolbox_btt_placement();

    $size   = max( 32, min( 96, (int) $s['size'] ) );
    $radius = max( 0, min( 50, (int) $s['radius'] ) );
    $icon   = max( 14, (int) round( $size * 0.34 ) );

    $bg     = $s['bg'] ? $s['bg'] : 'var(--primary, #9e86ff)';
    $shadow = $s['shadow'] ? '0 12px 28px -10px rgba(0,0,0,.45)' : 'none';

    $css = '.dp-totop{position:fixed;' . esc_attr( $place['position'] ) . ':' . (int) $place['side'] . 'px;'
        . 'bottom:' . (int) $place['bottom'] . 'px;z-index:9990;'
        . 'width:' . $size . 'px;height:' . $size . 'px;padding:0;margin:0;border:0;'
        . 'border-radius:' . $radius . '%;cursor:pointer;display:grid;place-items:center;'
        . 'background:' . $bg . ';box-shadow:' . $shadow . ';'
        . 'opacity:0;visibility:hidden;transform:translateY(12px);'
        . 'transition:opacity .25s ease,transform .25s ease,visibility .25s,background .2s ease}'
        . '.dp-totop.is-visible{opacity:1;visibility:visible;transform:translateY(0)}'
        . '.dp-totop:hover{transform:translateY(-3px)}'
        . '.dp-totop:focus-visible{outline:2px solid #fff;outline-offset:3px}'
        . '.dp-totop svg{width:' . $icon . 'px;height:' . $icon . 'px;stroke:' . esc_attr( $s['icon_color'] ) . ';'
        . 'fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}'
        . 'html.dp-no-anchor,html.dp-no-anchor *{overflow-anchor:none!important}'
        . '@media print{.dp-totop{display:none}}';

    if ( $s['hide_mobile'] ) {
        $css .= '@media (max-width:520px){.dp-totop{display:none}}';
    }

    $css .= '@media (prefers-reduced-motion:reduce){.dp-totop{transition:opacity .01ms}.dp-totop:hover{transform:none}}';

    return $css;
}

function dp_toolbox_btt_js() {
    $s         = dp_toolbox_btt_settings();
    $threshold = max( 50, (int) $s['threshold'] );

    ob_start();
    ?>
(function(){
    var btn = null, raf = null, animating = false;

    function y(){ return window.pageYOffset || document.documentElement.scrollTop || 0; }
    function setY(v){ document.documentElement.scrollTop = v; if (document.body) document.body.scrollTop = v; }

    function stop(){
        if (raf) cancelAnimationFrame(raf);
        raf = null; animating = false;
        document.documentElement.classList.remove('dp-no-anchor');
    }

    function run(){
        if (animating) return;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { setY(0); return; }
        animating = true;
        document.documentElement.classList.add('dp-no-anchor');
        var start = y(), t0 = null, dur = Math.min(700, Math.max(250, start / 3));
        function frame(now){
            if (null === t0) t0 = now;
            var p = Math.min(1, (now - t0) / dur);
            /* Uitlopende curve: start * (1-p)^3 loopt van start naar 0.
               Let op de richting — start * p^3 laat de pagina juist wegspringen. */
            setY(Math.round(start * Math.pow(1 - p, 3)));
            if (p < 1) { raf = requestAnimationFrame(frame); } else { setY(0); stop(); }
        }
        raf = requestAnimationFrame(frame);
    }

    function onScroll(){ if (btn) btn.classList.toggle('is-visible', y() > <?php echo (int) $threshold; ?>); }

    function init(){
        btn = document.querySelector('.dp-totop');
        if (!btn) return;
        btn.addEventListener('click', function(e){ e.preventDefault(); run(); });
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('wheel', stop, { passive: true });
        window.addEventListener('touchstart', stop, { passive: true });
        onScroll();
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
    <?php
    return trim( ob_get_clean() );
}

/**
 * Eén enkele uitvoer in de footer.
 *
 * De vorige mu-plugin printte de knop tweemaal; de JS pakte met querySelector
 * alleen de eerste, waardoor de tweede onzichtbaar in de DOM bleef hangen.
 */
add_action( 'wp_footer', function () {
    if ( is_admin() ) {
        return;
    }
    if ( ! apply_filters( 'dp_toolbox_btt_show', true ) ) {
        return;
    }

    echo dp_toolbox_btt_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<style id="dp-totop-css">' . dp_toolbox_btt_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<script id="dp-totop-js">' . dp_toolbox_btt_js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
}, 20 );

/**
 * Waarschuwt wanneer de oude mu-plugin er nog staat.
 *
 * Die haakt met een anonieme closure aan wp_footer en is daardoor niet
 * betrouwbaar te verwijderen; het bestand moet weg. Automatisch alle
 * footer-hooks opruimen is geen optie — dan sneuvelen ook WordPress' eigen
 * output en de DP Chat-widget.
 */
add_action( 'admin_notices', function () {
    if ( ! function_exists( 'dp_totop_markup' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>Terug naar boven:</strong> '
        . 'de oude mu-plugin <code>dp-back-to-top.php</code> is nog actief. Verwijder dat bestand uit '
        . '<code>wp-content/mu-plugins/</code>, anders staan er twee knoppen op je site.</p></div>';
} );

if ( is_admin() ) {
    require_once __DIR__ . '/admin-page.php';
}
