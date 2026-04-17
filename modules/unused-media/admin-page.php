<?php
/**
 * DP Toolbox — Unused Media Finder Admin Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'dp-toolbox',
        'Unused Media',
        'Unused Media',
        'manage_options',
        'dp-toolbox-unused-media',
        'dp_toolbox_unused_media_page'
    );
} );

function dp_toolbox_unused_media_page() {
    $nonce = wp_create_nonce( 'dp_toolbox_unused_media' );
    dp_toolbox_page_start( 'Unused Media Finder', 'Vind en verwijder afbeeldingen die nergens op de site gebruikt worden.' );
    ?>

        <style>

            .dp-um-toolbar {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .dp-um-btn {
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
            .dp-um-btn-primary { background: #281E5D; color: #fff; }
            .dp-um-btn-primary:hover { background: #4a3a8a; }
            .dp-um-btn-danger { background: #d63638; color: #fff; }
            .dp-um-btn-danger:hover { background: #b32d2e; }
            .dp-um-btn-secondary { background: #fff; color: #281E5D; border: 1px solid #ddd; }
            .dp-um-btn-secondary:hover { border-color: #281E5D; }
            .dp-um-btn:disabled { opacity: 0.5; cursor: not-allowed; }

            .dp-um-progress {
                background: #f0f0f0;
                border-radius: 8px;
                height: 8px;
                flex: 1;
                min-width: 200px;
                overflow: hidden;
                display: none;
            }
            .dp-um-progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #281E5D, #4a3a8a);
                border-radius: 8px;
                width: 0%;
                transition: width 0.3s;
            }
            .dp-um-progress-text {
                font-size: 13px;
                color: #666;
                display: none;
            }

            .dp-um-summary {
                display: none;
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px 24px;
                margin-bottom: 20px;
                gap: 24px;
            }
            .dp-um-summary-stat {
                text-align: center;
            }
            .dp-um-summary-num {
                display: block;
                font-size: 28px;
                font-weight: 700;
                color: #281E5D;
                line-height: 1;
            }
            .dp-um-summary-label {
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .dp-um-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 12px;
            }

            .dp-um-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
                transition: border-color 0.2s, box-shadow 0.2s;
                position: relative;
            }
            .dp-um-card:hover {
                border-color: #281E5D;
                box-shadow: 0 2px 8px rgba(40,30,93,0.1);
            }
            .dp-um-card.selected {
                border-color: #d63638;
                box-shadow: 0 0 0 2px rgba(214,54,56,0.2);
            }

            .dp-um-card-check {
                position: absolute;
                top: 8px;
                left: 8px;
                z-index: 2;
                width: 20px;
                height: 20px;
                cursor: pointer;
            }

            .dp-um-card-thumb {
                width: 100%;
                height: 140px;
                object-fit: cover;
                display: block;
                background: #f5f5f5;
            }

            .dp-um-card-info {
                padding: 10px 12px;
            }
            .dp-um-card-name {
                font-size: 12px;
                font-weight: 600;
                color: #1d2327;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                margin-bottom: 2px;
            }
            .dp-um-card-meta {
                font-size: 11px;
                color: #999;
            }
            .dp-um-card-meta a {
                color: #281E5D;
                text-decoration: none;
            }

            .dp-um-empty {
                text-align: center;
                padding: 48px 24px;
                color: #666;
            }
            .dp-um-empty .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #00a32a;
                margin-bottom: 12px;
            }
        </style>

        <p style="margin-top:0;color:#666;font-size:13px;">Scan de mediabibliotheek op afbeeldingen die nergens op de site gebruikt worden.</p>

        <div class="dp-um-toolbar">
            <button id="dp-um-scan" class="dp-um-btn dp-um-btn-primary">
                <span class="dashicons dashicons-search" style="line-height:1.4;"></span> Scan starten
            </button>
            <button id="dp-um-select-all" class="dp-um-btn dp-um-btn-secondary" style="display:none;">Alles selecteren</button>
            <button id="dp-um-delete" class="dp-um-btn dp-um-btn-danger" disabled style="display:none;">
                <span class="dashicons dashicons-trash" style="line-height:1.4;"></span> Verwijder geselecteerd
            </button>
            <div class="dp-um-progress"><div class="dp-um-progress-bar"></div></div>
            <span class="dp-um-progress-text"></span>
        </div>

        <div id="dp-um-summary" class="dp-um-summary"></div>
        <div id="dp-um-results" class="dp-um-grid"></div>
        <div id="dp-um-empty" class="dp-um-empty" style="display:none;">
            <span class="dashicons dashicons-yes-alt"></span>
            <h2>Alles in gebruik!</h2>
            <p>Er zijn geen ongebruikte afbeeldingen gevonden.</p>
        </div>

    <script>
    (function() {
        var ajaxUrl  = '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
        var nonce    = '<?php echo $nonce; ?>';
        var allUnused = [];

        var scanBtn     = document.getElementById('dp-um-scan');
        var selectBtn   = document.getElementById('dp-um-select-all');
        var deleteBtn   = document.getElementById('dp-um-delete');
        var progress    = document.querySelector('.dp-um-progress');
        var progressBar = document.querySelector('.dp-um-progress-bar');
        var progressTxt = document.querySelector('.dp-um-progress-text');
        var summary     = document.getElementById('dp-um-summary');
        var results     = document.getElementById('dp-um-results');
        var emptyMsg    = document.getElementById('dp-um-empty');

        function formatBytes(b) {
            if (b === 0) return '0 B';
            var k = 1024, s = ['B','KB','MB','GB'];
            var i = Math.floor(Math.log(b) / Math.log(k));
            return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + s[i];
        }

        function scanBatch(offset) {
            var fd = new FormData();
            fd.append('action', 'dp_toolbox_scan_unused_media');
            fd.append('nonce', nonce);
            fd.append('offset', offset);

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) return;
                var d = res.data;

                if (d.unused) {
                    d.unused.forEach(function(item) {
                        allUnused.push(item);
                        renderCard(item);
                    });
                }

                var pct = d.total ? Math.round((d.scanned || d.total) / d.total * 100) : 100;
                progressBar.style.width = pct + '%';
                progressTxt.textContent = (d.scanned || d.total) + ' / ' + d.total + ' gescand';

                if (!d.complete) {
                    scanBatch(d.offset);
                } else {
                    scanBtn.disabled = false;
                    scanBtn.innerHTML = '<span class="dashicons dashicons-search" style="line-height:1.4;"></span> Opnieuw scannen';
                    progress.style.display = 'none';
                    progressTxt.style.display = 'none';

                    if (allUnused.length === 0) {
                        emptyMsg.style.display = 'block';
                    } else {
                        selectBtn.style.display = '';
                        deleteBtn.style.display = '';
                        showSummary(d.total);
                    }
                }
            });
        }

        function showSummary(total) {
            var totalSize = allUnused.reduce(function(a, b) { return a + b.size; }, 0);
            summary.style.display = 'flex';
            summary.innerHTML =
                '<div class="dp-um-summary-stat"><span class="dp-um-summary-num">' + total + '</span><span class="dp-um-summary-label">Totaal gescand</span></div>' +
                '<div class="dp-um-summary-stat"><span class="dp-um-summary-num">' + allUnused.length + '</span><span class="dp-um-summary-label">Ongebruikt</span></div>' +
                '<div class="dp-um-summary-stat"><span class="dp-um-summary-num">' + formatBytes(totalSize) + '</span><span class="dp-um-summary-label">Vrij te maken</span></div>';
        }

        function renderCard(item) {
            var div = document.createElement('div');
            div.className = 'dp-um-card';
            div.dataset.id = item.id;
            div.innerHTML =
                '<input type="checkbox" class="dp-um-card-check" data-id="' + item.id + '">' +
                '<img class="dp-um-card-thumb" src="' + (item.thumb || '') + '" alt="" loading="lazy">' +
                '<div class="dp-um-card-info">' +
                    '<div class="dp-um-card-name" title="' + item.filename + '">' + item.filename + '</div>' +
                    '<div class="dp-um-card-meta">' + formatBytes(item.size) + ' &middot; <a href="' + item.edit_url + '" target="_blank">Bekijk</a></div>' +
                '</div>';
            results.appendChild(div);

            div.querySelector('.dp-um-card-check').addEventListener('change', updateDeleteBtn);
        }

        function updateDeleteBtn() {
            var checked = results.querySelectorAll('.dp-um-card-check:checked');
            deleteBtn.disabled = checked.length === 0;
            deleteBtn.innerHTML = '<span class="dashicons dashicons-trash" style="line-height:1.4;"></span> Verwijder' +
                (checked.length > 0 ? ' (' + checked.length + ')' : ' geselecteerd');
        }

        scanBtn.addEventListener('click', function() {
            allUnused = [];
            results.innerHTML = '';
            emptyMsg.style.display = 'none';
            summary.style.display = 'none';
            selectBtn.style.display = 'none';
            deleteBtn.style.display = 'none';
            progress.style.display = '';
            progressTxt.style.display = '';
            progressBar.style.width = '0%';
            scanBtn.disabled = true;
            scanBtn.innerHTML = 'Scannen...';
            scanBatch(0);
        });

        selectBtn.addEventListener('click', function() {
            var boxes = results.querySelectorAll('.dp-um-card-check');
            var allChecked = Array.from(boxes).every(function(b) { return b.checked; });
            boxes.forEach(function(b) {
                b.checked = !allChecked;
                b.closest('.dp-um-card').classList.toggle('selected', !allChecked);
            });
            selectBtn.textContent = allChecked ? 'Alles selecteren' : 'Deselecteren';
            updateDeleteBtn();
        });

        deleteBtn.addEventListener('click', function() {
            var checked = results.querySelectorAll('.dp-um-card-check:checked');
            var ids = Array.from(checked).map(function(b) { return b.dataset.id; });
            if (!ids.length) return;
            if (!confirm('Weet je zeker dat je ' + ids.length + ' afbeelding(en) permanent wilt verwijderen?')) return;

            deleteBtn.disabled = true;
            deleteBtn.innerHTML = 'Verwijderen...';

            var fd = new FormData();
            fd.append('action', 'dp_toolbox_delete_unused_media');
            fd.append('nonce', nonce);
            ids.forEach(function(id) { fd.append('ids[]', id); });

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    ids.forEach(function(id) {
                        var card = results.querySelector('[data-id="' + id + '"]');
                        if (card) card.remove();
                        allUnused = allUnused.filter(function(u) { return u.id != id; });
                    });
                    alert(res.data.deleted + ' afbeelding(en) verwijderd. ' + formatBytes(res.data.freed) + ' vrijgemaakt.');
                    if (allUnused.length === 0) {
                        emptyMsg.style.display = 'block';
                        selectBtn.style.display = 'none';
                        deleteBtn.style.display = 'none';
                        summary.style.display = 'none';
                    } else {
                        showSummary(parseInt(summary.querySelector('.dp-um-summary-num').textContent));
                    }
                }
                updateDeleteBtn();
            });
        });

        // Click card to toggle checkbox
        results.addEventListener('click', function(e) {
            var card = e.target.closest('.dp-um-card');
            if (!card || e.target.tagName === 'INPUT' || e.target.tagName === 'A') return;
            var check = card.querySelector('.dp-um-card-check');
            check.checked = !check.checked;
            card.classList.toggle('selected', check.checked);
            updateDeleteBtn();
        });
    })();
    </script>
    <?php
    dp_toolbox_page_end();
}