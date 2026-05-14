(function () {
    'use strict';

    if (typeof DPAttributePricing === 'undefined') return;

    var cfg = DPAttributePricing;

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.dp-ap-options');
        if (!root) return;

        var selects = root.querySelectorAll('.dp-ap-options__select');
        if (!selects.length) return;

        var priceEl = findPriceElement();

        function findPriceElement() {
            // Try the most specific selectors first; bail to null if nothing matches.
            var candidates = [
                'form.cart .price',
                '.product .summary .price',
                '.entry-summary > .price',
                '.product > .price',
                '.product .price'
            ];
            for (var i = 0; i < candidates.length; i++) {
                var el = document.querySelector(candidates[i]);
                if (el) return el;
            }
            return null;
        }

        function formatPrice(amount) {
            var decimals = Math.max(0, parseInt(cfg.decimals, 10) || 0);
            var abs = Math.abs(amount).toFixed(decimals);
            var parts = abs.split('.');
            var integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousandSep);
            var decimalPart = parts[1] ? cfg.decimalSep + parts[1] : '';
            var num = integerPart + decimalPart;
            var sym = cfg.currency;
            switch (cfg.symbolPos) {
                case 'left':        return sym + num;
                case 'left_space':  return sym + ' ' + num;
                case 'right':       return num + sym;
                case 'right_space': return num + ' ' + sym;
                default:            return sym + num;
            }
        }

        function getSelectedPrice(select) {
            var opt = select.options[select.selectedIndex];
            if (!opt) return 0;
            return parseFloat(opt.getAttribute('data-price')) || 0;
        }

        function updateSurcharge(select) {
            var price = getSelectedPrice(select);
            var span = select.parentNode.querySelector('.dp-ap-options__surcharge');
            if (!span) return;
            if (price > 0) {
                span.textContent = '+ ' + formatPrice(price);
                span.classList.add('is-visible');
            } else {
                span.textContent = '';
                span.classList.remove('is-visible');
            }
        }

        function recomputeTotal() {
            if (!priceEl) return;
            var total = parseFloat(cfg.basePrice) || 0;
            for (var i = 0; i < selects.length; i++) {
                total += getSelectedPrice(selects[i]);
            }
            // Cache the original markup so we never lose other markup (sale dashes, suffixes).
            if (!priceEl.hasAttribute('data-dp-ap-touched')) {
                priceEl.setAttribute('data-dp-ap-touched', '1');
            }
            priceEl.innerHTML =
                '<span class="woocommerce-Price-amount amount"><bdi>'
                + escapeHtml(formatPrice(total))
                + '</bdi></span>';
            document.documentElement.classList.add('dp-ap-price-updated');
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function refresh() {
            for (var i = 0; i < selects.length; i++) {
                updateSurcharge(selects[i]);
            }
            recomputeTotal();
        }

        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', refresh);
        }

        refresh();
    });
})();
