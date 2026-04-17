<?php
/**
 * Module Name: Plugins Sorter
 * Description: Plaatst inactieve plugins onderaan in de pluginlijst.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_footer-plugins.php', function () {
    ?>
    <script>
    (function() {
        var tbody = document.querySelector( '.wp-list-table.plugins tbody#the-list' );
        if ( ! tbody ) return;

        var rows    = Array.from( tbody.querySelectorAll( 'tr' ) );
        var active  = [];
        var inactive = [];

        rows.forEach( function( row ) {
            if ( row.classList.contains( 'inactive' ) ) {
                inactive.push( row );
            } else {
                active.push( row );
            }
        });

        active.concat( inactive ).forEach( function( row ) {
            tbody.appendChild( row );
        });
    })();
    </script>
    <?php
} );