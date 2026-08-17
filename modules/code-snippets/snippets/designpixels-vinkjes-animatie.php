<?php
/**
 * Name: Design Pixels — vinkjes-animatie homepage
 * Description: De vinkjes in de sectie "Ontdek de voordelen" op de homepage beginnen grijs en kleuren gestaffeld naar de merkkleur zodra de sectie in beeld komt. Gebonden aan Bricks-element #brxe-haytut — verdwijnt die sectie of gaat de site naar Etch, dan doet deze snippet niets meer en kan hij weg.
 * Sites: designpixels.nl
 * Status: active
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_footer', function () {
    if ( ! is_front_page() ) {
        return;
    }
    ?>
    <style>
        /* Beginstand: donkergrijs */
        #brxe-haytut .card-content-62__feature-element-icon {
            color: #3a3a3a !important;
            fill: #3a3a3a !important;
            transition: color 0.6s ease, fill 0.6s ease;
        }
        /* Staffeling per vinkje */
        #brxe-haytut .card-content-62__feature-element:nth-child(1) .card-content-62__feature-element-icon { transition-delay: 1s; }
        #brxe-haytut .card-content-62__feature-element:nth-child(2) .card-content-62__feature-element-icon { transition-delay: 1.15s; }
        #brxe-haytut .card-content-62__feature-element:nth-child(3) .card-content-62__feature-element-icon { transition-delay: 1.3s; }
        #brxe-haytut .card-content-62__feature-element:nth-child(4) .card-content-62__feature-element-icon { transition-delay: 1.45s; }
        #brxe-haytut .card-content-62__feature-element:nth-child(5) .card-content-62__feature-element-icon { transition-delay: 1.6s; }

        /* In beeld: merkkleur */
        #brxe-haytut.dp-in-view .card-content-62__feature-element-icon {
            color: var(--primary) !important;
            fill: var(--primary) !important;
        }

        /* Wie beweging liever vermijdt, krijgt de eindstand meteen. */
        @media (prefers-reduced-motion: reduce) {
            #brxe-haytut .card-content-62__feature-element-icon {
                color: var(--primary) !important;
                fill: var(--primary) !important;
                transition: none;
                transition-delay: 0s;
            }
        }
    </style>
    <script>
    (function () {
        var section = document.getElementById("brxe-haytut");
        if (!section) return;

        if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
            section.classList.add("dp-in-view");
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    section.classList.add("dp-in-view");
                    observer.unobserve(section);
                }
            });
        }, { threshold: 0.3 });

        observer.observe(section);
    })();
    </script>
    <?php
} );
