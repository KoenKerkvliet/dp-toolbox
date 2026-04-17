<?php
/**
 * DP Toolbox — Alt Text Filler Admin Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Alt Text Filler',
        'Alt Text Filler',
        'manage_options',
        'dp-toolbox-alt-text',
        'dp_toolbox_alt_text_page'
    );
} );

function dp_toolbox_alt_text_page() {
    $nonce = wp_create_nonce( 'dp_toolbox_alt_filler' );
    dp_toolbox_page_start( 'Alt Text Filler', 'Vind afbeeldingen zonder alt-tekst en vul ze automatisch in.' );
    ?>

        <style>

            .dp-alt-toolbar {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .dp-alt-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 20px;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 600;
                border: none;
                cursor: pointer;
                transition: all 0.2s;
            }
            .dp-alt-btn-primary { background: #281E5D; color: #fff; }
            .dp-alt-btn-primary:hover { background: #4a3a8a; }
            .dp-alt-btn-success { background: #00a32a; color: #fff; }
            .dp-alt-btn-success:hover { background: #008a20; }
            .dp-alt-btn:disabled { opacity: 0.5; cursor: not-allowed; }

            .dp-alt-summary {
                display: none;
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 16px 24px;
                margin-bottom: 20px;
                font-size: 14px;
                color: #1d2327;
            }
            .dp-alt-summary strong { color: #281E5D; }

            .dp-alt-list { display: flex; flex-direction: column; gap: 8px; }

            .dp-alt-row {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 12px 16px;
                display: flex;
                align-items: center;
                gap: 16px;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .dp-alt-row:hover {
                border-color: #281E5D;
                box-shadow: 0 2px 8px rgba(40,30,93,0.08);
            }
            .dp-alt-row.saved {
                border-left: 4px solid #00a32a;
                background: #f9fef9;
            }

            .dp-alt-thumb {
                width: 60px;
                height: 60px;
                object-fit: cover;
                border-radius: 6px;
                flex-shrink: 0;
                background: #f5f5f5;
            }

            .dp-alt-info {
                flex: 1;
                min-width: 0;
            }
            .dp-alt-filename {
                font-size: 12px;
                color: #999;
                margin-bottom: 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .dp-alt-filename a { color: #281E5D; text-decoration: none; }
            .dp-alt-input {
                width: 100%;
                padding: 6px 10px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 13px;
                transition: border-color 0.2s;
                box-sizing: border-box;
            }
            .dp-alt-input:focus {
                border-color: #281E5D;
                outline: none;
                box-shadow: 0 0 0 2px rgba(40,30,93,0.1);
            }

            .dp-alt-row-save {
                flex-shrink: 0;
                background: none;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 6px 12px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                color: #281E5D;
                transition: all 0.2s;
            }
            .dp-alt-row-save:hover {
                background: #281E5D;
                color: #fff;
                border-color: #281E5D;
            }

            .dp-alt-empty {
                text-align: center;
                padding: 48px 24px;
                color: #666;
            }
            .dp-alt-empty .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #00a32a;
                margin-bottom: 12px;
            }
        </style>

        <p style="margin-top:0;color:#666;font-size:13px;">Vind afbeeldingen zonder alt-tekst en vul ze automatisch in.</p>

        <div class="dp-alt-toolbar">
            <button id="dp-alt-scan" class="dp-alt-btn dp-alt-btn-primary">
                <span class="dashicons dashicons-search" style="line-height:1.4;"></span> Scannen
            </button>
            <button id="dp-alt-save-all" class="dp-alt-btn dp-alt-btn-success" style="display:none;">
                <span class="dashicons dashicons-saved" style="line-height:1.4;"></span> Alles opslaan
            </button>
        </div>

        <div id="dp-alt-summary" class="dp-alt-summary"></div>
        <div id="dp-alt-list" class="dp-alt-list"></div>
        <div id="dp-alt-empty" class="dp-alt-empty" style="display:none;">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2>Alles heeft alt-tekst!</h2>
            <p>Alle afbeeldingen in de mediabibliotheek hebben al een alt-tekst.</p>
        </div>

    <script>
    (function() {
        var ajaxUrl   = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
        var nonce     = '<?php echo $nonce; ?>';
        var allItems  = [];

        var scanBtn   = document.getElementById('dp-alt-scan');
        var saveAll   = document.getElementById('dp-alt-save-all');
        var summary   = document.getElementById('dp-alt-summary');
        var list      = document.getElementById('dp-alt-list');
        var emptyMsg  = document.getElementById('dp-alt-empty');

        scanBtn.addEventListener('click', function() {
            list.innerHTML = '';
            emptyMsg.style.display = 'none';
            summary.style.display = 'none';
            saveAll.style.display = 'none';
            scanBtn.disabled = true;
            scanBtn.innerHTML = 'Scannen...';

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_scan_missing_alt');
            fd.append('nonce', nonce);

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                scanBtn.disabled = false;
                scanBtn.innerHTML = '<span class="dashicons dashicons-search" style="line-height:1.4;"></span> Opnieuw scannen';

                if (!res.success) return;
                allItems = res.data.items;

                if (allItems.length === 0) {
                    emptyMsg.style.display = 'block';
                    return;
                }

                summary.style.display = 'block';
                summary.innerHTML = '<strong>' + allItems.length + '</strong> afbeelding(en) zonder alt-tekst gevonden. Pas de suggesties aan en sla op.';
                saveAll.style.display = '';

                allItems.forEach(function(item) {
                    var row = document.createElement('div');
                    row.className = 'dp-alt-row';
                    row.dataset.id = item.id;
                    row.innerHTML =
                        '<img class="dp-alt-thumb" src="' + (item.thumb || '') + '" alt="" loading="lazy">' +
                        '<div class="dp-alt-info">' +
                            '<div class="dp-alt-filename"><a href="' + item.edit_url + '" target="_blank">' + item.filename + '</a></div>' +
                            '<input type="text" class="dp-alt-input" value="' + escHtml(item.suggestion) + '" placeholder="Alt-tekst...">' +
                        '</div>' +
                        '<button class="dp-alt-row-save" title="Alleen deze opslaan">Opslaan</button>';
                    list.appendChild(row);

                    row.querySelector('.dp-alt-row-save').addEventListener('click', function() {
                        var input = row.querySelector('.dp-alt-input');
                        saveSingle(item.id, input.value, row);
                    });
                });
            });
        });

        saveAll.addEventListener('click', function() {
            var rows = list.querySelectorAll('.dp-alt-row:not(.saved)');
            var alts = [];
            rows.forEach(function(row) {
                var val = row.querySelector('.dp-alt-input').value.trim();
                if (val) {
                    alts.push({ id: row.dataset.id, alt: val });
                }
            });

            if (!alts.length) return;

            saveAll.disabled = true;
            saveAll.innerHTML = 'Opslaan...';

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_save_alt_texts');
            fd.append('nonce', nonce);
            alts.forEach(function(a, i) {
                fd.append('alts[' + i + '][id]', a.id);
                fd.append('alts[' + i + '][alt]', a.alt);
            });

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                saveAll.disabled = false;
                if (res.success) {
                    saveAll.innerHTML = '<span class="dashicons dashicons-yes-alt" style="line-height:1.4;"></span> ' + res.data.saved + ' opgeslagen';
                    alts.forEach(function(a) {
                        var row = list.querySelector('[data-id="' + a.id + '"]');
                        if (row) row.classList.add('saved');
                    });
                    setTimeout(function() {
                        saveAll.innerHTML = '<span class="dashicons dashicons-saved" style="line-height:1.4;"></span> Alles opslaan';
                    }, 2500);
                }
            });
        });

        function saveSingle(id, alt, row) {
            if (!alt.trim()) return;
            var btn = row.querySelector('.dp-alt-row-save');
            btn.textContent = '...';

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_save_alt_texts');
            fd.append('nonce', nonce);
            fd.append('alts[0][id]', id);
            fd.append('alts[0][alt]', alt);

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    row.classList.add('saved');
                    btn.textContent = 'Opgeslagen';
                } else {
                    btn.textContent = 'Fout';
                }
            });
        }

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML.replace(/"/g, '&quot;');
        }
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}