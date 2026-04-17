/**
 * DP Toolbox — Media Replacement
 * Works in both the media library grid modal and the full attachment edit screen.
 */
(function ($) {
    'use strict';

    // --- Full edit screen (post.php) ---
    $(function () {
        initReplaceButtons();
    });

    // --- Grid modal: buttons are rendered dynamically, so use event delegation ---
    $(document).on('click', '.dp-mr-btn', handleReplaceClick);

    function initReplaceButtons() {
        // On the full edit screen, move the replace wrap to the sidebar
        var $sidebar = $('#postbox-container-1 .meta-box-sortables');
        if ($sidebar.length) {
            var $wrap = $('.dp-mr-wrap').first();
            if ($wrap.length) {
                var $postbox = $('<div class="postbox dp-mr-postbox">' +
                    '<div class="postbox-header"><h2>Media vervangen</h2></div>' +
                    '<div class="inside"></div></div>');
                $postbox.find('.inside').append($wrap.clone(true));
                $wrap.closest('tr').hide();
                $sidebar.prepend($postbox);
            }
        }
    }

    function handleReplaceClick(e) {
        e.preventDefault();

        var $btn    = $(this);
        var $wrap   = $btn.closest('.dp-mr-wrap');
        var $status = $wrap.find('.dp-mr-status');
        var oldId   = $wrap.data('attachment-id');

        if (!oldId) return;

        var frame = wp.media({
            title:    'Selecteer nieuw mediabestand',
            button:   { text: 'Vervangen' },
            multiple: false,
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var newId = attachment.id;

            $btn.prop('disabled', true);
            $status.text('Bezig met vervangen…').css('color', '#666');

            $.post(dpMR.ajaxUrl, {
                action:            'dp_toolbox_replace_media',
                nonce:             dpMR.nonce,
                old_attachment_id: oldId,
                new_attachment_id: newId,
            }, function (res) {
                if (res.success) {
                    $status.text('Vervangen!').css('color', '#00a32a');

                    // Refresh the view after a short delay
                    setTimeout(function () {
                        // Grid modal: refresh the library
                        if (wp.media.frame && wp.media.frame.content && wp.media.frame.content.get()) {
                            wp.media.frame.content.get().collection.props.set({ ignore: +new Date() });
                        }
                        // Always reload to show updated image
                        window.location.reload();
                    }, 600);
                } else {
                    $status.text(res.data || 'Fout bij vervangen.').css('color', '#d63638');
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                $status.text('Netwerkfout.').css('color', '#d63638');
                $btn.prop('disabled', false);
            });
        });

        frame.open();
    }

})(jQuery);
