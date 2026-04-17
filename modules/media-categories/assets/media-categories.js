/**
 * DP Toolbox — Media Categories
 * 1. Grid view filter dropdown (Backbone)
 * 2. Checkbox save logic (AJAX)
 */
(function ($, _, wp) {
    'use strict';

    /* ================================================================ */
    /*  1. Grid view — taxonomy filter dropdown                         */
    /* ================================================================ */

    if ( typeof wp !== 'undefined' && wp.media && wp.media.view ) {

        // Custom filter view extending Backbone AttachmentFilters
        var MediaCategoryFilter = wp.media.view.AttachmentFilters.extend({
            id:        'dp-media-category-filter',
            className: 'attachment-filters',

            createFilters: function () {
                var filters = {};

                // "All categories" option
                filters.all = {
                    text:     dpMC.allLabel,
                    props:    { dp_media_category: 0 },
                    priority: 10,
                };

                // One option per term
                _.each( dpMC.terms, function ( term ) {
                    filters[ 'term_' + term.id ] = {
                        text:     term.name + ( term.count > 0 ? ' (' + term.count + ')' : '' ),
                        props:    { dp_media_category: term.id },
                        priority: 20,
                    };
                });

                this.filters = filters;
            },
        });

        // Inject filter into the media library toolbar
        var OriginalBrowser = wp.media.view.AttachmentsBrowser;

        wp.media.view.AttachmentsBrowser = OriginalBrowser.extend({
            createToolbar: function () {
                OriginalBrowser.prototype.createToolbar.call( this );

                // Only add if we have terms
                if ( ! dpMC.terms || dpMC.terms.length === 0 ) {
                    return;
                }

                this.toolbar.set( 'dpMediaCategoryFilter', new MediaCategoryFilter({
                    controller: this.controller,
                    model:      this.collection.props,
                    priority:   -75,
                }).render() );
            },
        });
    }

    /* ================================================================ */
    /*  2. Checkbox save — AJAX on change                               */
    /* ================================================================ */

    $(document).on( 'change', '.dp-mc-checkboxes input[type="checkbox"]', function () {
        var $wrap  = $(this).closest('.dp-mc-checkboxes');
        var attId  = $wrap.data('attachment-id');
        var terms  = [];

        $wrap.find('input[type="checkbox"]:checked').each(function () {
            terms.push( $(this).data('term-id') );
        });

        $.post( dpMC.ajaxUrl, {
            action:        'dp_toolbox_save_media_categories',
            nonce:         dpMC.nonce,
            attachment_id: attId,
            terms:         terms,
        });
    });

})(jQuery, _, wp);
