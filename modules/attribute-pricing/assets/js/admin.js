(function ($) {
    'use strict';

    if (typeof DPAttributePricing === 'undefined') return;

    var d = $(document);

    var sel = {
        panel:            '#dp_attribute_pricing_panel',
        picker:           '#dp-ap-select',
        addAttribute:     '.dp-ap__add-attribute',
        wrapper:          '.dp-ap__wrapper',
        attribute:        '.dp-ap__attribute',
        removeAttribute:  '.dp-ap__remove-attribute',
        deleteRow:        '.dp-ap__delete-row',
        addCustomRow:     '.dp-ap__add-custom-row',
        addGlobalRow:     '.dp-ap__add-global-row',
        chooseAttribute:  '.dp-ap__choose-attribute',
        addCustomConfirm: '.dp-ap__add-custom-confirm',
        selectWrapper:    '.dp-ap__select-wrapper',
        inputWrapper:     '.dp-ap__input-wrapper',
    };

    var nonceMap = {
        'dp_ap_get_attribute':         'getAttribute',
        'dp_ap_check_attribute':       'checkAttribute',
        'dp_ap_add_term_to_attribute': 'addTermToAttribute',
    };

    function postAjax(action, data) {
        return $.ajax({
            method: 'POST',
            url:    DPAttributePricing.ajaxUrl,
            data:   $.extend({ action: action, nonce: DPAttributePricing.nonces[nonceMap[action]] }, data),
        });
    }

    function escapeHtml(s) {
        return $('<div>').text(s == null ? '' : s).html();
    }

    function rowHtml(global, attribute) {
        var taxonomy = attribute.woocommerce_attribute_name;
        var slug     = attribute.attribute_slug;

        var cell = global
            ? '<input type="hidden" readonly value="' + escapeHtml(attribute.attribute_value) + '" name="dp_ap[' + escapeHtml(taxonomy) + '][' + escapeHtml(slug) + '][attribute_id]">'
              + '<span data-attribute-id="' + escapeHtml(attribute.attribute_value) + '" data-slug="' + escapeHtml(slug) + '">' + escapeHtml(attribute.attribute_name) + '</span>'
            : '<input type="text" name="dp_ap_custom_name">';

        return '<tr>'
             +   '<td>' + cell + '</td>'
             +   '<td><input type="number" step="any" min="0" name="dp_ap[' + escapeHtml(taxonomy) + '][' + escapeHtml(slug) + '][attribute_price]"></td>'
             +   '<td><div class="dp-ap__delete-row">x</div></td>'
             + '</tr>';
    }

    function attributeBlockHtml(attribute, terms) {
        var rows = (terms || []).map(function (t) {
            return rowHtml(true, {
                attribute_value:            t.term_id,
                attribute_slug:             t.slug,
                attribute_name:             t.name,
                woocommerce_attribute_name: 'pa_' + attribute.slug,
            });
        }).join('');

        var i18n = DPAttributePricing.i18n;

        return '<div class="dp-ap__attribute" data-attribute-name="' + escapeHtml(attribute.slug) + '" data-attribute-id="' + escapeHtml(attribute.id) + '">'
             +   '<div class="dp-ap__attribute-head">'
             +     '<strong>' + escapeHtml(attribute.label) + '</strong>'
             +     '<button type="button" class="dp-ap__remove-attribute">x</button>'
             +   '</div>'
             +   '<table class="dp-ap__table"><thead><tr>'
             +     '<th>' + escapeHtml(i18n.value) + '</th>'
             +     '<th>' + escapeHtml(i18n.additionalPrice) + '</th>'
             +     '<th>' + escapeHtml(i18n.action) + '</th>'
             +   '</tr></thead><tbody>' + rows + '</tbody></table>'
             +   '<div class="dp-ap__add-row-buttons">'
             +     '<button type="button" class="dp-ap__add-custom-row">' + escapeHtml(i18n.addCustom) + '</button>'
             +     '<button type="button" class="dp-ap__add-global-row">' + escapeHtml(i18n.addGlobal) + '</button>'
             +   '</div>'
             + '</div>';
    }

    // Add attribute block from top picker
    d.on('click', sel.addAttribute, function () {
        var $picker  = $(sel.picker);
        var id       = $picker.val();
        if (!id) return;

        var $opt   = $picker.find(':selected');
        var slug   = $opt.data('slug');
        var label  = $opt.text();

        postAjax('dp_ap_get_attribute', { attribute_slug: slug })
            .done(function (res) {
                if (!res || !res.success) return;
                var html = attributeBlockHtml({ id: id, slug: slug, label: label }, res.data);
                $(sel.wrapper).append(html);
                $opt.prop('disabled', true);
                $picker.prop('selectedIndex', 0);
            });
    });

    // Remove an attribute block
    d.on('click', sel.removeAttribute, function (e) {
        var $block = $(e.target).closest(sel.attribute);
        var id     = $block.data('attribute-id');
        $block.remove();
        $(sel.picker).find('option[value="' + id + '"]').prop('disabled', false);
    });

    // Delete a value row
    d.on('click', sel.deleteRow, function (e) {
        $(e.target).closest('tr').remove();
    });

    // Add custom (open inline text input → confirm creates a new term)
    d.on('click', sel.addCustomRow, function (e) {
        var $block = $(e.target).closest(sel.attribute);
        if ($block.find(sel.inputWrapper).length || $block.find(sel.selectWrapper).length) {
            window.alert(DPAttributePricing.i18n.pleaseSelectFirst);
            return;
        }
        $block.append(
            '<div class="dp-ap__input-wrapper">'
            + '<input type="text" class="dp-ap__input-custom">'
            + '<button type="button" class="dp-ap__add-custom-confirm">OK</button>'
            + '</div>'
        );
    });

    d.on('click', sel.addCustomConfirm, function (e) {
        var $wrapper      = $(e.target).closest(sel.inputWrapper);
        var $block        = $(e.target).closest(sel.attribute);
        var attributeSlug = $block.data('attribute-name');
        var termName      = $.trim($wrapper.find('.dp-ap__input-custom').val());

        if (!termName) {
            window.alert(DPAttributePricing.i18n.termNameRequired);
            return;
        }

        postAjax('dp_ap_add_term_to_attribute', { attribute: attributeSlug, attribute_name: termName })
            .done(function (res) {
                if (!res || !res.success || !res.data.attribute_value) {
                    window.alert(DPAttributePricing.i18n.errorAddingTerm);
                    return;
                }
                $block.find('tbody').append(rowHtml(true, res.data));
                $wrapper.remove();
            })
            .fail(function () {
                window.alert(DPAttributePricing.i18n.errorAddingTerm);
            });
    });

    // Add global (open inline select with unused terms → confirm appends row)
    d.on('click', sel.addGlobalRow, function (e) {
        var $block = $(e.target).closest(sel.attribute);
        if ($block.find(sel.inputWrapper).length || $block.find(sel.selectWrapper).length) {
            window.alert(DPAttributePricing.i18n.pleaseSelectFirst);
            return;
        }
        var attributeSlug = $block.data('attribute-name');
        var used = [];
        $block.find('span[data-attribute-id]').each(function () {
            used.push($(this).data('attribute-id'));
        });

        postAjax('dp_ap_check_attribute', { attribute_name: attributeSlug, attributes: used })
            .done(function (res) {
                if (!res || !res.success) return;
                if (!res.data || !res.data.length) {
                    window.alert(DPAttributePricing.i18n.allUsed);
                    return;
                }
                var options = res.data.map(function (t) {
                    return '<option value="' + escapeHtml(t.term_id) + '" data-slug="' + escapeHtml(t.slug) + '">' + escapeHtml(t.name) + '</option>';
                }).join('');
                $block.append(
                    '<div class="dp-ap__select-wrapper">'
                    + '<select class="dp-ap__select-global">' + options + '</select>'
                    + '<button type="button" class="dp-ap__choose-attribute">OK</button>'
                    + '</div>'
                );
            });
    });

    d.on('click', sel.chooseAttribute, function (e) {
        var $wrapper      = $(e.target).closest(sel.selectWrapper);
        var $block        = $(e.target).closest(sel.attribute);
        var $select       = $wrapper.find('.dp-ap__select-global');
        var attributeSlug = $block.data('attribute-name');

        $block.find('tbody').append(rowHtml(true, {
            attribute_value:            $select.val(),
            attribute_name:             $select.find(':selected').text(),
            attribute_slug:             $select.find(':selected').data('slug'),
            woocommerce_attribute_name: 'pa_' + attributeSlug,
        }));
        $wrapper.remove();
    });

    /* ---------------------------------------------------------------- */
    /*  Sortable — attribute blocks AND value rows within an attribute   */
    /*  The form's $_POST['dp_ap'] keys follow DOM order, so save =      */
    /*  new order. Works automatically; no extra save step.              */
    /* ---------------------------------------------------------------- */
    function initRowsSortable($scope) {
        $scope = $scope && $scope.length ? $scope : $(document);
        $scope.find('.dp-ap__attribute table tbody').each(function () {
            var $tbody = $(this);
            if ($tbody.data('dp-ap-rows-init')) return;
            $tbody.data('dp-ap-rows-init', true);

            $tbody.sortable({
                items:                '> tr',
                handle:               'td:first-child',
                cancel:               'input, .dp-ap__delete-row',
                placeholder:          'dp-ap__row-placeholder',
                forcePlaceholderSize: true,
                tolerance:            'pointer',
                cursor:               'move',
                opacity:              0.7,
                helper: function (e, tr) {
                    var $originals = tr.children();
                    var $helper    = tr.clone();
                    $helper.children().each(function (index) {
                        $(this).width($originals.eq(index).width());
                    });
                    return $helper;
                },
            });
        });
    }

    $(function () {
        if (typeof $.fn.sortable !== 'function') return;

        $(sel.wrapper).sortable({
            items:                '> ' + sel.attribute,
            handle:               '.dp-ap__attribute-head',
            cancel:               sel.removeAttribute,
            placeholder:          'dp-ap__attribute-placeholder',
            forcePlaceholderSize: true,
            tolerance:            'pointer',
            cursor:               'move',
            opacity:              0.7,
        });

        initRowsSortable();
    });

    /* Re-init row sortable after a fresh attribute block is appended. */
    d.on('click', sel.addAttribute, function () {
        // Defer one tick so the AJAX-appended HTML is in the DOM.
        setTimeout(function () { initRowsSortable(); }, 50);
    });

})(jQuery);
