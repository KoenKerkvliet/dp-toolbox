<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$nonce      = wp_create_nonce( 'dp_toolbox_file_manager' );
$dl_nonce   = wp_create_nonce( 'dp_toolbox_fm_download' );
$ajax_url   = admin_url( 'admin-ajax.php' );
$start_path = str_replace( '\\', '/', ABSPATH );
?>
<div class="wrap">
<style>
    /* Layout */
    .dp-fm { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 1200px; }
    .dp-fm-header {
        background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%);
        color: #fff; padding: 20px 28px; border-radius: 10px 10px 0 0;
        display: flex; align-items: center; justify-content: space-between;
    }
    .dp-fm-header h1 { margin: 0; font-size: 20px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; }
    .dp-fm-header h1 .dashicons { font-size: 22px; width: 22px; height: 22px; }
    .dp-fm-body {
        background: #fff; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;
        min-height: 500px; display: flex; flex-direction: column;
    }

    /* Breadcrumb & toolbar */
    .dp-fm-toolbar {
        display: flex; align-items: center; gap: 8px; padding: 10px 16px;
        border-bottom: 1px solid #eee; background: #f9f9f9; flex-wrap: wrap;
    }
    .dp-fm-breadcrumb {
        flex: 1; font-size: 13px; font-family: monospace; color: #555;
        display: flex; align-items: center; gap: 2px; min-width: 0; overflow: hidden;
    }
    .dp-fm-breadcrumb a {
        color: #281E5D; text-decoration: none; white-space: nowrap; cursor: pointer;
    }
    .dp-fm-breadcrumb a:hover { text-decoration: underline; }
    .dp-fm-breadcrumb span { color: #bbb; }

    .dp-fm-tb-btn {
        background: #fff; border: 1px solid #ddd; border-radius: 5px;
        padding: 5px 12px; font-size: 12px; cursor: pointer; color: #555;
        display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;
    }
    .dp-fm-tb-btn:hover { border-color: #281E5D; color: #281E5D; }
    .dp-fm-tb-btn .dashicons { font-size: 14px; width: 14px; height: 14px; }

    /* File list */
    .dp-fm-list { flex: 1; overflow: auto; }
    .dp-fm-table { width: 100%; border-collapse: collapse; }
    .dp-fm-table th {
        text-align: left; font-size: 11px; font-weight: 600; color: #888;
        text-transform: uppercase; letter-spacing: 0.3px;
        padding: 8px 16px; border-bottom: 1px solid #eee; background: #fafafa;
        position: sticky; top: 0; z-index: 1;
    }
    .dp-fm-table td { padding: 6px 16px; border-bottom: 1px solid #f5f5f5; font-size: 13px; }
    .dp-fm-table tr:hover td { background: #f8f7fc; }
    .dp-fm-table tr.is-dir td { font-weight: 500; }

    .dp-fm-name {
        display: flex; align-items: center; gap: 8px; cursor: pointer; color: #1d2327;
    }
    .dp-fm-name:hover { color: #281E5D; }
    .dp-fm-name .dashicons { font-size: 16px; width: 16px; height: 16px; flex-shrink: 0; }
    .dp-fm-name .dashicons.dir-icon { color: #f0c040; }
    .dp-fm-name .dashicons.file-icon { color: #999; }

    .dp-fm-size { font-family: monospace; font-size: 12px; color: #888; white-space: nowrap; }
    .dp-fm-date { font-size: 12px; color: #999; white-space: nowrap; }

    .dp-fm-actions { display: flex; gap: 4px; }
    .dp-fm-actions button {
        background: none; border: 1px solid transparent; border-radius: 3px;
        padding: 3px 6px; cursor: pointer; color: #999; transition: all 0.15s;
    }
    .dp-fm-actions button:hover { border-color: #ddd; color: #281E5D; }
    .dp-fm-actions button.dp-fm-act-del:hover { color: #d63638; border-color: #fecaca; }
    .dp-fm-actions .dashicons { font-size: 14px; width: 14px; height: 14px; }

    /* Editor modal */
    .dp-fm-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
        z-index: 100000; justify-content: center; align-items: center;
    }
    .dp-fm-modal-overlay.is-open { display: flex; }
    .dp-fm-modal {
        background: #fff; border-radius: 10px; width: 90vw; max-width: 900px;
        max-height: 85vh; display: flex; flex-direction: column;
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
    }
    .dp-fm-modal-header {
        display: flex; align-items: center; gap: 10px; padding: 14px 20px;
        border-bottom: 1px solid #eee; flex-shrink: 0;
    }
    .dp-fm-modal-header h3 { margin: 0; font-size: 14px; font-weight: 700; color: #1d2327; flex: 1; font-family: monospace; }
    .dp-fm-modal-close {
        background: none; border: none; cursor: pointer; color: #999; font-size: 20px; padding: 4px;
    }
    .dp-fm-modal-close:hover { color: #d63638; }
    .dp-fm-editor {
        flex: 1; overflow: auto; padding: 0;
    }
    .dp-fm-editor textarea {
        width: 100%; height: 100%; min-height: 400px; border: none; padding: 16px;
        font-family: "Fira Code", "Consolas", "Monaco", monospace; font-size: 13px;
        line-height: 1.6; resize: none; box-sizing: border-box; tab-size: 4;
        outline: none;
    }
    .dp-fm-modal-footer {
        display: flex; align-items: center; gap: 10px; padding: 12px 20px;
        border-top: 1px solid #eee; flex-shrink: 0;
    }
    .dp-fm-save-btn {
        background: #281E5D; color: #fff; border: none; border-radius: 6px;
        padding: 7px 22px; font-size: 13px; font-weight: 600; cursor: pointer;
    }
    .dp-fm-save-btn:hover { background: #4a3a8a; }
    .dp-fm-save-status { font-size: 12px; color: #888; }

    /* Upload area */
    .dp-fm-upload-area {
        display: none; border: 2px dashed #ccc; border-radius: 8px;
        padding: 20px; text-align: center; margin: 12px 16px;
        transition: border-color 0.15s; background: #fafafa;
    }
    .dp-fm-upload-area.is-visible { display: block; }
    .dp-fm-upload-area.is-drag { border-color: #281E5D; background: #f3f0ff; }
    .dp-fm-upload-area p { margin: 0 0 10px; font-size: 13px; color: #666; }
    .dp-fm-upload-input { display: none; }

    /* Empty state */
    .dp-fm-empty { text-align: center; padding: 40px; color: #999; }

    /* Prompt modal (simple) */
    .dp-fm-prompt {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
        z-index: 100001; justify-content: center; align-items: center;
    }
    .dp-fm-prompt.is-open { display: flex; }
    .dp-fm-prompt-box {
        background: #fff; border-radius: 10px; padding: 24px; width: 400px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
    }
    .dp-fm-prompt-box h3 { margin: 0 0 12px; font-size: 15px; }
    .dp-fm-prompt-box input {
        width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;
        font-size: 13px; margin-bottom: 14px; box-sizing: border-box;
    }
    .dp-fm-prompt-box input:focus { border-color: #281E5D; outline: none; }
    .dp-fm-prompt-btns { display: flex; gap: 8px; }
    .dp-fm-prompt-btns button { padding: 7px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .dp-fm-prompt-ok { background: #281E5D; color: #fff; border: none; }
    .dp-fm-prompt-cancel { background: #fff; color: #666; border: 1px solid #ddd; }
</style>

<div class="dp-fm">
    <div class="dp-fm-header">
        <h1><span class="dashicons dashicons-portfolio"></span> DP File Manager</h1>
    </div>
    <div class="dp-fm-body">
        <div class="dp-fm-toolbar">
            <div class="dp-fm-breadcrumb" id="dp-fm-breadcrumb"></div>
            <button type="button" class="dp-fm-tb-btn" id="dp-fm-btn-new-file" title="Nieuw bestand">
                <span class="dashicons dashicons-media-text"></span> Bestand
            </button>
            <button type="button" class="dp-fm-tb-btn" id="dp-fm-btn-new-folder" title="Nieuwe map">
                <span class="dashicons dashicons-open-folder"></span> Map
            </button>
            <button type="button" class="dp-fm-tb-btn" id="dp-fm-btn-upload" title="Upload">
                <span class="dashicons dashicons-upload"></span> Upload
            </button>
        </div>

        <div class="dp-fm-upload-area" id="dp-fm-upload-area">
            <p>Sleep bestanden hierheen of klik om te uploaden</p>
            <input type="file" id="dp-fm-upload-input" class="dp-fm-upload-input" multiple>
            <button type="button" class="dp-fm-tb-btn" onclick="document.getElementById('dp-fm-upload-input').click()">Bestand kiezen</button>
        </div>

        <div class="dp-fm-list">
            <table class="dp-fm-table">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th style="width:90px;">Grootte</th>
                        <th style="width:130px;">Gewijzigd</th>
                        <th style="width:100px;">Acties</th>
                    </tr>
                </thead>
                <tbody id="dp-fm-tbody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Editor modal -->
<div class="dp-fm-modal-overlay" id="dp-fm-editor-modal">
    <div class="dp-fm-modal">
        <div class="dp-fm-modal-header">
            <h3 id="dp-fm-editor-title"></h3>
            <button type="button" class="dp-fm-modal-close" id="dp-fm-editor-close">&times;</button>
        </div>
        <div class="dp-fm-editor">
            <textarea id="dp-fm-editor-textarea" spellcheck="false"></textarea>
        </div>
        <div class="dp-fm-modal-footer">
            <button type="button" class="dp-fm-save-btn" id="dp-fm-editor-save">Opslaan</button>
            <span class="dp-fm-save-status" id="dp-fm-save-status"></span>
        </div>
    </div>
</div>

<!-- Prompt modal (for create/rename) -->
<div class="dp-fm-prompt" id="dp-fm-prompt">
    <div class="dp-fm-prompt-box">
        <h3 id="dp-fm-prompt-title"></h3>
        <input type="text" id="dp-fm-prompt-input">
        <div class="dp-fm-prompt-btns">
            <button type="button" class="dp-fm-prompt-ok" id="dp-fm-prompt-ok">OK</button>
            <button type="button" class="dp-fm-prompt-cancel" id="dp-fm-prompt-cancel">Annuleren</button>
        </div>
    </div>
</div>

<script>
(function() {
    var ajaxUrl  = '<?php echo esc_js( $ajax_url ); ?>';
    var nonce    = '<?php echo esc_js( $nonce ); ?>';
    var dlNonce  = '<?php echo esc_js( $dl_nonce ); ?>';
    var currentDir = '<?php echo esc_js( $start_path ); ?>';
    var abspath    = '<?php echo esc_js( $start_path ); ?>';

    var tbody      = document.getElementById('dp-fm-tbody');
    var breadcrumb = document.getElementById('dp-fm-breadcrumb');

    // ---------- Load directory ----------
    function loadDir(dir) {
        currentDir = dir;
        var fd = new FormData();
        fd.append('action', 'dp_toolbox_fm_list');
        fd.append('nonce', nonce);
        fd.append('dir', dir);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) { alert(res.data); return; }
                currentDir = res.data.dir;
                renderBreadcrumb(res.data.dir);
                renderList(res.data.items);
            });
    }

    function renderBreadcrumb(dir) {
        var rel = dir.replace(abspath, '');
        var parts = rel.split('/').filter(Boolean);
        var html = '<a data-path="' + abspath + '">root</a>';
        var path = abspath;
        parts.forEach(function(p) {
            path += p + '/';
            html += ' <span>/</span> <a data-path="' + path + '">' + p + '</a>';
        });
        breadcrumb.innerHTML = html;
        breadcrumb.querySelectorAll('a').forEach(function(a) {
            a.addEventListener('click', function() { loadDir(this.dataset.path); });
        });
    }

    function renderList(items) {
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="dp-fm-empty">Lege map</td></tr>';
            return;
        }
        var html = '';
        items.forEach(function(item) {
            var icon = item.is_dir ? 'dashicons-category dir-icon' : fileIcon(item.ext);
            var sizeStr = item.is_dir ? '—' : formatSize(item.size);
            var cls = item.is_dir ? 'is-dir' : '';

            html += '<tr class="' + cls + '" data-path="' + esc(item.path) + '" data-name="' + esc(item.name) + '" data-isdir="' + (item.is_dir?1:0) + '">';
            html += '<td><span class="dp-fm-name" data-action="open"><span class="dashicons ' + icon + '"></span>' + esc(item.name) + '</span></td>';
            html += '<td class="dp-fm-size">' + sizeStr + '</td>';
            html += '<td class="dp-fm-date">' + item.modified + '</td>';
            html += '<td><div class="dp-fm-actions">';
            if (item.name !== '..') {
                if (!item.is_dir) {
                    html += '<button class="dp-fm-act-dl" title="Download"><span class="dashicons dashicons-download"></span></button>';
                }
                html += '<button class="dp-fm-act-ren" title="Hernoemen"><span class="dashicons dashicons-edit"></span></button>';
                html += '<button class="dp-fm-act-del" title="Verwijderen"><span class="dashicons dashicons-trash"></span></button>';
            }
            html += '</div></td></tr>';
        });
        tbody.innerHTML = html;
    }

    // ---------- Click handlers ----------
    tbody.addEventListener('click', function(e) {
        var tr   = e.target.closest('tr');
        var btn  = e.target.closest('button');
        var name = e.target.closest('.dp-fm-name');
        if (!tr) return;
        var path  = tr.dataset.path;
        var isDir = tr.dataset.isdir === '1';

        if (name && name.dataset.action === 'open') {
            if (isDir) {
                loadDir(path);
            } else {
                openEditor(path);
            }
            return;
        }

        if (btn && btn.classList.contains('dp-fm-act-dl')) {
            window.location.href = ajaxUrl + '?action=dp_toolbox_fm_download&nonce=' + dlNonce + '&file=' + encodeURIComponent(path);
        }
        if (btn && btn.classList.contains('dp-fm-act-ren')) {
            promptInput('Hernoemen', tr.dataset.name, function(newName) {
                ajaxPost('dp_toolbox_fm_rename', { path: path, new_name: newName }, function() { loadDir(currentDir); });
            });
        }
        if (btn && btn.classList.contains('dp-fm-act-del')) {
            if (!confirm('Weet je zeker dat je "' + tr.dataset.name + '" wilt verwijderen?')) return;
            ajaxPost('dp_toolbox_fm_delete', { path: path }, function() { loadDir(currentDir); });
        }
    });

    // ---------- Editor ----------
    var editorModal    = document.getElementById('dp-fm-editor-modal');
    var editorTitle    = document.getElementById('dp-fm-editor-title');
    var editorTextarea = document.getElementById('dp-fm-editor-textarea');
    var editorStatus   = document.getElementById('dp-fm-save-status');
    var editingPath    = '';

    function openEditor(path) {
        ajaxPost('dp_toolbox_fm_read', { file: path }, function(data) {
            editingPath = data.path;
            editorTitle.textContent = data.name;
            editorTextarea.value = data.content;
            editorStatus.textContent = formatSize(data.size);
            editorModal.classList.add('is-open');
            editorTextarea.focus();
        });
    }

    document.getElementById('dp-fm-editor-close').addEventListener('click', function() {
        editorModal.classList.remove('is-open');
    });
    editorModal.addEventListener('click', function(e) { if (e.target === editorModal) editorModal.classList.remove('is-open'); });

    document.getElementById('dp-fm-editor-save').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        editorStatus.textContent = 'Opslaan...';
        ajaxPost('dp_toolbox_fm_save', { file: editingPath, content: editorTextarea.value }, function(data) {
            editorStatus.textContent = 'Opgeslagen (' + formatSize(data.size) + ')';
            btn.disabled = false;
        }, function(err) {
            editorStatus.textContent = 'Fout: ' + err;
            btn.disabled = false;
        });
    });

    // Tab key in editor
    editorTextarea.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = this.selectionStart;
            this.value = this.value.substring(0, start) + '    ' + this.value.substring(this.selectionEnd);
            this.selectionStart = this.selectionEnd = start + 4;
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.getElementById('dp-fm-editor-save').click();
        }
    });

    // ---------- Create file/folder ----------
    document.getElementById('dp-fm-btn-new-file').addEventListener('click', function() {
        promptInput('Nieuw bestand', '', function(name) {
            ajaxPost('dp_toolbox_fm_create', { dir: currentDir, name: name, type: 'file' }, function() { loadDir(currentDir); });
        });
    });
    document.getElementById('dp-fm-btn-new-folder').addEventListener('click', function() {
        promptInput('Nieuwe map', '', function(name) {
            ajaxPost('dp_toolbox_fm_create', { dir: currentDir, name: name, type: 'folder' }, function() { loadDir(currentDir); });
        });
    });

    // ---------- Upload ----------
    var uploadArea  = document.getElementById('dp-fm-upload-area');
    var uploadInput = document.getElementById('dp-fm-upload-input');

    document.getElementById('dp-fm-btn-upload').addEventListener('click', function() {
        uploadArea.classList.toggle('is-visible');
    });

    uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('is-drag'); });
    uploadArea.addEventListener('dragleave', function() { this.classList.remove('is-drag'); });
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault(); this.classList.remove('is-drag');
        uploadFiles(e.dataTransfer.files);
    });
    uploadInput.addEventListener('change', function() { uploadFiles(this.files); this.value = ''; });

    function uploadFiles(files) {
        var remaining = files.length;
        Array.from(files).forEach(function(file) {
            var fd = new FormData();
            fd.append('action', 'dp_toolbox_fm_upload');
            fd.append('nonce', nonce);
            fd.append('dir', currentDir);
            fd.append('file', file);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function() {
                    remaining--;
                    if (remaining <= 0) {
                        uploadArea.classList.remove('is-visible');
                        loadDir(currentDir);
                    }
                });
        });
    }

    // ---------- Prompt modal ----------
    var promptModal = document.getElementById('dp-fm-prompt');
    var promptInput2 = document.getElementById('dp-fm-prompt-input');
    var promptCallback = null;

    function promptInput(title, defaultVal, cb) {
        document.getElementById('dp-fm-prompt-title').textContent = title;
        promptInput2.value = defaultVal;
        promptCallback = cb;
        promptModal.classList.add('is-open');
        setTimeout(function() { promptInput2.focus(); promptInput2.select(); }, 50);
    }

    document.getElementById('dp-fm-prompt-ok').addEventListener('click', function() {
        var val = promptInput2.value.trim();
        promptModal.classList.remove('is-open');
        if (val && promptCallback) promptCallback(val);
    });
    document.getElementById('dp-fm-prompt-cancel').addEventListener('click', function() {
        promptModal.classList.remove('is-open');
    });
    promptInput2.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') document.getElementById('dp-fm-prompt-ok').click();
        if (e.key === 'Escape') document.getElementById('dp-fm-prompt-cancel').click();
    });

    // ---------- Helpers ----------
    function ajaxPost(action, data, onSuccess, onError) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        for (var k in data) fd.append(k, data[k]);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { if (onSuccess) onSuccess(res.data); }
                else { if (onError) onError(res.data); else alert(res.data || 'Fout'); }
            });
    }

    function formatSize(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function fileIcon(ext) {
        var icons = {
            php: 'dashicons-editor-code file-icon', js: 'dashicons-editor-code file-icon',
            css: 'dashicons-admin-customizer file-icon', html: 'dashicons-editor-code file-icon',
            json: 'dashicons-editor-code file-icon', xml: 'dashicons-editor-code file-icon',
            txt: 'dashicons-media-text file-icon', md: 'dashicons-media-text file-icon',
            jpg: 'dashicons-format-image file-icon', jpeg: 'dashicons-format-image file-icon',
            png: 'dashicons-format-image file-icon', gif: 'dashicons-format-image file-icon',
            svg: 'dashicons-format-image file-icon', webp: 'dashicons-format-image file-icon',
            zip: 'dashicons-media-archive file-icon', gz: 'dashicons-media-archive file-icon',
            pdf: 'dashicons-media-document file-icon',
        };
        return icons[ext] || 'dashicons-media-default file-icon';
    }

    function esc(str) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

    // ---------- Init ----------
    loadDir(currentDir);
})();
</script>
</div>
<?php
