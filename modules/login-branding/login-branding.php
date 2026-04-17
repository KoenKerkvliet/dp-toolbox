<?php
/**
 * Module Name: Login Branding
 * Description: Past de WordPress-loginpagina aan met Design Pixels huisstijl.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom CSS on the login page.
 */
add_action( 'login_enqueue_scripts', function () {
    $logo_url = DP_TOOLBOX_URL . 'assets/dp-logo.webp';
    ?>
    <style>
        /* Background */
        body.login {
            background: linear-gradient(135deg, #1a1235 0%, #281E5D 40%, #3d2d7a 100%) !important;
        }

        /* Logo */
        .login h1 a {
            background-image: url('<?php echo esc_url( $logo_url ); ?>') !important;
            background-size: contain !important;
            background-position: center !important;
            background-repeat: no-repeat !important;
            width: 280px !important;
            height: 80px !important;
            margin-bottom: 20px !important;
        }

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
            border-color: #281E5D !important;
            box-shadow: 0 0 0 2px rgba(40, 30, 93, 0.15) !important;
        }

        /* Submit button */
        .login form .submit input[type="submit"],
        .wp-core-ui .button-primary {
            background: #281E5D !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 8px 24px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            height: auto !important;
            line-height: 1.5 !important;
            text-shadow: none !important;
            box-shadow: 0 2px 8px rgba(40, 30, 93, 0.3) !important;
            transition: background 0.2s !important;
            width: 100% !important;
        }
        .login form .submit input[type="submit"]:hover,
        .wp-core-ui .button-primary:hover {
            background: #4a3a8a !important;
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
            border-left-color: #281E5D !important;
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