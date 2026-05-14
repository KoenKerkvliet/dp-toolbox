(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var bar = document.querySelector('.dp-sac');
        if (!bar) return;

        var trigger = document.querySelector(
            'form.cart .single_add_to_cart_button, form.cart button[type="submit"]'
        );
        if (!trigger) {
            bar.parentNode && bar.parentNode.removeChild(bar);
            return;
        }

        var btn = bar.querySelector('.dp-sac__btn');
        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                trigger.click();
            });
        }

        if (!('IntersectionObserver' in window)) {
            bar.classList.add('is-visible');
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    bar.classList.remove('is-visible');
                } else {
                    bar.classList.add('is-visible');
                }
            });
        }, {
            rootMargin: '-10% 0px -10% 0px',
            threshold: 0,
        });

        observer.observe(trigger);
    });
})();
