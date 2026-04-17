<?php
/**
 * DP Toolbox — WebP Converter Admin Page (Tab)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'WebP Converter',
        'WebP Converter',
        'manage_options',
        'dp-toolbox-webp-converter',
        'dp_toolbox_webp_admin_page'
    );
} );

function dp_toolbox_webp_admin_page() {
    if ( isset( $_GET['set_max_width'] ) && current_user_can( 'manage_options' ) ) {
        dp_toolbox_set_max_width();
    }
    if ( isset( $_GET['cleanup_leftover_originals'] ) && current_user_can( 'manage_options' ) ) {
        dp_toolbox_cleanup_leftover_originals();
    }
    if ( isset( $_GET['clear_log'] ) && current_user_can( 'manage_options' ) ) {
        dp_toolbox_clear_log();
    }
    dp_toolbox_page_start( 'WebP Converter', 'Converteer afbeeldingen naar WebP en optimaliseer bestandsgrootte.' );
    ?>
    <p class="dp-page-desc">
        Nieuwe uploads worden automatisch geconverteerd naar WebP. Gebruik de knoppen hieronder voor bestaande afbeeldingen.
    </p>

    <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;">
            <label for="max-width-input" style="font-size:13px;">Max breedte (px):</label>
            <input type="number" id="max-width-input" value="<?php echo esc_attr( dp_toolbox_get_max_width() ); ?>" min="1" style="width:80px;font-size:13px;">
            <button id="set-max-size" class="button" style="font-size:13px;">Instellen</button>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
            <button id="start-conversion" class="button button-primary" style="font-size:13px;">Converteer naar WebP</button>
            <button id="cleanup-originals" class="button" style="font-size:13px;">Alleen hoofd + thumbnail</button>
            <button id="convert-post-images" class="button" style="font-size:13px;">Post-URLs updaten</button>
            <button id="clear-log" class="button" style="font-size:13px;">Log wissen</button>
        </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px 20px;">
        <div style="display:flex;gap:24px;margin-bottom:12px;font-size:13px;color:#666;">
            <span>Max: <strong id="max-width"><?php echo dp_toolbox_get_max_width(); ?></strong>px</span>
            <span>Totaal: <strong id="total">-</strong></span>
            <span>Geconverteerd: <strong id="converted">-</strong></span>
            <span>Voortgang: <strong id="percentage">-</strong>%</span>
        </div>
        <pre id="log" style="font-size:12px;max-height:300px;overflow-y:auto;background:#f9f9f9;padding:10px;border-radius:6px;margin:0;"></pre>
    </div>

    <script>
    (function(){
        var ajaxUrl = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
        var pageUrl = '<?php echo esc_js( admin_url( "admin.php?page=dp-toolbox-webp-converter" ) ); ?>';

        function updateStatus() {
            fetch(ajaxUrl + '?action=dp_toolbox_webp_status')
                .then(function(r){ return r.json(); })
                .then(function(d){
                    document.getElementById('max-width').textContent  = d.max_width;
                    document.getElementById('total').textContent      = d.total;
                    document.getElementById('converted').textContent  = d.converted;
                    document.getElementById('percentage').textContent = d.percentage;
                    document.getElementById('log').innerHTML          = d.log.reverse().join('<br>');
                });
        }

        function convertNext(offset) {
            fetch(ajaxUrl + '?action=dp_toolbox_webp_convert_single', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'offset=' + offset
            }).then(function(r){ return r.json(); }).then(function(d){
                if (d.success) { updateStatus(); if (!d.data.complete) convertNext(d.data.offset); }
            });
        }

        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        document.getElementById('set-max-size').onclick = function(){
            fetch(pageUrl + '&set_max_width=1&max_width=' + document.getElementById('max-width-input').value).then(updateStatus);
        };
        document.getElementById('start-conversion').onclick = function(){
            fetch(pageUrl + '&convert_existing_images_to_webp=1').then(function(){ updateStatus(); convertNext(0); });
        };
        document.getElementById('cleanup-originals').onclick = function(){
            fetch(pageUrl + '&cleanup_leftover_originals=1').then(updateStatus);
        };
        document.getElementById('convert-post-images').onclick = function(){
            if (!confirm('Alle post-afbeeldingen updaten naar WebP?')) return;
            fetch(ajaxUrl + '?action=dp_toolbox_convert_post_images', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'} })
                .then(function(r){ return r.json(); })
                .then(function(d){ alert(d.success ? d.data.message : 'Fout'); updateStatus(); });
        };
        document.getElementById('clear-log').onclick = function(){
            fetch(pageUrl + '&clear_log=1').then(updateStatus);
        };
        <?php endif; ?>

        updateStatus();
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}