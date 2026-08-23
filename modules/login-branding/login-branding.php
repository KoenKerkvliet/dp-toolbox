<?php
/**
 * Module Name: Login Branding
 * Description: Geeft de WordPress-loginpagina een eigen uiterlijk, met het logo van de site of van Design Pixels.
 * Category: appearance
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom CSS on the login page.
 */
add_action( 'login_enqueue_scripts', function () {
    $logo_url     = dp_toolbox_branding_logo_url();
    $accent       = dp_toolbox_branding_color( 'accent' );
    $accent_hover = dp_toolbox_branding_color( 'accent_hover' );
    $gradient     = dp_toolbox_branding_color( 'gradient' );
    ?>
    <style>
        /* Background */
        body.login {
            background: <?php echo esc_attr( $gradient ); ?> !important;
        }

        /* Logo — of de sitenaam als tekst wanneer er geen logo is */
        <?php if ( $logo_url ) : ?>
        .login h1 a {
            background-image: url('<?php echo esc_url( $logo_url ); ?>') !important;
            background-size: contain !important;
            background-position: center !important;
            background-repeat: no-repeat !important;
            width: 280px !important;
            height: 80px !important;
            margin-bottom: 20px !important;
        }
        <?php else : ?>
        .login h1 a {
            background-image: none !important;
            width: auto !important;
            height: auto !important;
            text-indent: 0 !important;
            overflow: visible !important;
            font-size: 20px !important;
            font-weight: 600 !important;
            line-height: 1.3 !important;
            color: #fff !important;
            text-decoration: none !important;
            margin-bottom: 20px !important;
        }
        <?php endif; ?>

        /* Form container */
        .login form {
            background: rgba(255, 255, 255, 0.95) !important;
            border: none !important;
            border-radius: 12px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3) !important;
            padding: 28px 24px !important;
        }

        /* Labels */
        .login form .forgetmenot label,
        .login label {
            color: #1d2327 !important;
            font-size: 13px !important;
        }

        /* Inputs */
        .login form input[type="text"],
        .login form input[type="password"] {
            border: 1px solid #ddd !important;
            border-radius: 6px !important;
            padding: 8px 12px !important;
            font-size: 14px !important;
            box-shadow: none !important;
            transition: border-color 0.2s !important;
        }
        .login form input[type="text"]:focus,
        .login form input[type="password"]:focus {
            border-color: <?php echo esc_attr( $accent ); ?> !important;
            box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.12) !important;
        }

        /* Submit button */
        .login form .submit input[type="submit"],
        .wp-core-ui .button-primary {
            background: <?php echo esc_attr( $accent ); ?> !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 8px 24px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            height: auto !important;
            line-height: 1.5 !important;
            text-shadow: none !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18) !important;
            transition: background 0.2s !important;
            width: 100% !important;
        }
        .login form .submit input[type="submit"]:hover,
        .wp-core-ui .button-primary:hover {
            background: <?php echo esc_attr( $accent_hover ); ?> !important;
        }

        /* Links */
        .login #nav a,
        .login #backtoblog a {
            color: rgba(255, 255, 255, 0.7) !important;
            transition: color 0.2s !important;
        }
        .login #nav a:hover,
        .login #backtoblog a:hover {
            color: #fff !important;
        }

        /* Error / messages */
        .login .message,
        .login .success {
            border-left-color: <?php echo esc_attr( $accent ); ?> !important;
            border-radius: 6px !important;
        }

        /* Privacy policy link */
        .login .privacy-policy-page-link a {
            color: rgba(255, 255, 255, 0.5) !important;
        }

        /* Language switcher */
        .language-switcher {
            background: rgba(255, 255, 255, 0.1) !important;
            border: none !important;
            border-radius: 8px !important;
        }
    </style>
    <?php
} );

/**
 * Remove the language switcher on the login page.
 */
add_filter( 'login_display_language_dropdown', '__return_false' );

/**
 * Change the login logo URL to the site URL (instead of wordpress.org).
 */
add_filter( 'login_headerurl', function () {
    return home_url( '/' );
} );

/**
 * Change the login logo title to the site name.
 */
add_filter( 'login_headertext', function () {
    return get_bloginfo( 'name' );
} );