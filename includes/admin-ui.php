<?php
/**
 * DP Toolbox — Shared Admin UI helpers
 * Provides consistent page wrapper for all module admin pages.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Open a module admin page with styled header + content area.
 *
 * @param string $title       Page title
 * @param string $description Short description below title
 */
function dp_toolbox_page_start( $title, $description = '' ) {
    ?>
    <div class="wrap dp-page-wrap">
        <style>
            .dp-page-wrap { max-width: 860px; }
            .dp-page-header {
                background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%);
                color: #fff; padding: 28px 32px; border-radius: 10px 10px 0 0; margin-bottom: 0;
            }
            .dp-page-header h1 { margin: 0 0 6px; font-size: 22px; font-weight: 700; color: #fff; }
            .dp-page-header p  { margin: 0; opacity: 0.75; font-size: 13px; }
            .dp-page-content {
                background: #f0f0f1; border-radius: 0 0 10px 10px;
                padding: 24px 32px; border: 1px solid #ddd; border-top: none;
            }

            /* Shared card styles */
            .dp-card {
                background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
                padding: 18px 22px; margin-bottom: 10px;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .dp-card:hover {
                border-color: #281E5D; box-shadow: 0 2px 8px rgba(40,30,93,0.08);
            }

            /* Section headers */
            .dp-section-header {
                display: flex; align-items: center; gap: 8px;
                margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #281E5D;
            }
            .dp-section-header .dashicons { color: #281E5D; font-size: 18px; width: 18px; height: 18px; }
            .dp-section-header h2 { margin: 0; font-size: 15px; font-weight: 700; color: #1d2327; }

            /* Toggle switch */
            .dp-toggle input[type="checkbox"] { display: none; }
            .dp-toggle label {
                display: block; width: 42px; height: 22px; background: #ccc;
                border-radius: 11px; position: relative; cursor: pointer; transition: background 0.2s; flex-shrink: 0;
            }
            .dp-toggle label::after {
                content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;
                background: #fff; border-radius: 50%; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            .dp-toggle input:checked + label { background: #281E5D; }
            .dp-toggle input:checked + label::after { transform: translateX(20px); }

            /* Buttons */
            .dp-page-wrap .button-primary,
            .dp-btn-primary {
                background: #281E5D !important; border-color: #281E5D !important; border-radius: 6px;
                padding: 6px 22px; font-size: 13px; height: auto; line-height: 1.6;
                color: #fff !important; font-weight: 600; cursor: pointer; transition: background 0.2s;
                text-decoration: none; display: inline-block; border: none;
            }
            .dp-page-wrap .button-primary:hover,
            .dp-btn-primary:hover {
                background: #4a3a8a !important; border-color: #4a3a8a !important;
            }
            .dp-btn-secondary {
                background: #fff; color: #281E5D; border: 1px solid #ddd; border-radius: 6px;
                padding: 6px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
                transition: all 0.2s; text-decoration: none; display: inline-block;
            }
            .dp-btn-secondary:hover { border-color: #281E5D; }
            .dp-btn-danger {
                background: #fff; color: #d63638; border: 1px solid #d63638; border-radius: 6px;
                padding: 6px 18px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s;
            }
            .dp-btn-danger:hover { background: #d63638; color: #fff; }

            /* Stat boxes */
            .dp-stats { display: flex; gap: 12px; margin-bottom: 20px; }
            .dp-stat-box {
                flex: 1; background: #f8f7fc; border-radius: 8px; padding: 16px; text-align: center;
            }
            .dp-stat-num {
                display: block; font-size: 28px; font-weight: 700; color: #281E5D; line-height: 1; margin-bottom: 4px;
            }
            .dp-stat-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }

            /* Toolbar */
            .dp-toolbar {
                display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;
            }

            /* Description text */
            .dp-page-desc { margin-top: 0; color: #666; font-size: 13px; margin-bottom: 16px; }
        </style>

        <div class="dp-page-notices"></div>
        <div class="dp-page-header">
            <h1><?php echo esc_html( $title ); ?></h1>
            <?php if ( $description ) : ?>
                <p><?php echo esc_html( $description ); ?></p>
            <?php endif; ?>
        </div>
        <div class="dp-page-content">
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var wrap = document.querySelector('.dp-page-wrap');
        var target = wrap.querySelector('.dp-page-notices');
        if (!wrap || !target) return;
        // Move all admin notices above our header
        var notices = wrap.parentNode.querySelectorAll('.notice, .updated, .error, .is-dismissible');
        notices.forEach(function(n) {
            if (!wrap.contains(n)) return;
            target.appendChild(n);
        });
        // Also catch notices that WordPress injects as siblings
        var prev = wrap.previousElementSibling;
        while (prev && (prev.classList.contains('notice') || prev.classList.contains('updated') || prev.classList.contains('error'))) {
            var p = prev.previousElementSibling;
            target.appendChild(prev);
            prev = p;
        }
    });
    </script>
    <?php
}

/**
 * Close the module admin page wrapper.
 */
function dp_toolbox_page_end() {
    ?>
        </div><!-- .dp-page-content -->
    </div><!-- .dp-page-wrap -->
    <?php
}
