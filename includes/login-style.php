<?php
/*
 * File: includes/login-style.php
 * Description: Makes the WordPress login screen use Traxs branding, colors, and button styling.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-11 EDT
 */

namespace Traxs;
if (!defined('ABSPATH')) exit;

class Login_Style {

    public static function init() {
        add_action('login_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_filter('login_headerurl', [__CLASS__, 'logo_url']);
    }

    public static function enqueue() {
        // 1. Reuse your main CSS for colors and fonts
        wp_enqueue_style('traxs-css', TRAXS_URL . 'assets/css/traxs.css', [], TRAXS_VERSION);

        // 2. Inject the background color + logo override using your existing JS constant's value
        $logo = esc_url('https://thebeartraxs.com/wp-content/uploads/2025/05/The-Bear-Traxs-Logo.png');

        echo '<style>
            body.login {
                background-color: #0e243c !important;
                font-family: "Roboto Condensed", sans-serif !important;
            }
            #login h1 a {
                background-image: url(' . $logo . ') !important;
                background-size: contain !important;
                background-repeat: no-repeat !important;
                width: 200px !important;
                height: 200px !important;
                margin: 0 auto 20px auto !important;
            }
            .login form {
                border-radius: 0px !important;
                border: none !important;
            }
            .login #wp-submit {
                background-color: #863d3d !important;
                border: 1px solid #863d3d !important;
                color: #fff !important;
                font-weight: 700 !important;
                border-radius: 0px !important;
                text-transform: uppercase !important;
                padding: 10px 20px !important;
            }
            .login #wp-submit:hover {
                filter: brightness(0.9);
            }
        </style>';
    }

    public static function logo_url() {
        // Clicking the logo should go back to the main site
        return home_url('/');
    }
}

Login_Style::init();
