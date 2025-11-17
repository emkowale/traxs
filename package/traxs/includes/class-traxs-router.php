<?php
/*
 * File: includes/class-traxs-router.php
 * Description: Front-end /traxs route; minimal HTML shell (Traxs SPA).
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Router {
    public static function register_routes() {
        add_rewrite_rule('^traxs/?$', 'index.php?traxs_app=1', 'top');
        add_filter('query_vars', function ($vars) { $vars[] = 'traxs_app'; return $vars; });
        add_action('template_redirect', [__CLASS__, 'template']);
    }

    public static function template() {
        if (intval(get_query_var('traxs_app')) !== 1) return;

        // Access gate — redirect to WP login if not logged in or lacks permission
        if (
            !is_user_logged_in() ||
            !(
                current_user_can('traxs_access') ||
                current_user_can('traxs_manage') ||
                current_user_can('manage_options') ||
                current_user_can('manage_woocommerce')
            )
        ) {
            auth_redirect(); // sends to wp-login.php?redirect_to=<current_url>
        }

        // Simple cache-busting helper
        $ver = function ($rel) {
            $abs = TRAXS_PATH . ltrim($rel, '/');
            $m   = @filemtime($abs);
            return $m ? (string) $m : (defined('TRAXS_VERSION') ? TRAXS_VERSION : time());
        };

        // CSS
        $css_traxs   = TRAXS_URL . 'assets/css/traxs.css?v='          . $ver('assets/css/traxs.css');
        $css_receive = TRAXS_URL . 'assets/css/traxs-receive.css?v=' . $ver('assets/css/traxs-receive.css');
        $css_modal   = TRAXS_URL . 'assets/css/spa-modal.css?v='     . $ver('assets/css/spa-modal.css');

        // JS
        $js_app      = TRAXS_URL . 'assets/js/app.js?v='             . $ver('assets/js/app.js');
        $js_autosave = TRAXS_URL . 'assets/js/receive-autosave.js?v='. $ver('assets/js/receive-autosave.js');

        // REST config for app.js / autosave
        $rest_root  = esc_url_raw(rest_url());
        $rest_nonce = wp_create_nonce('wp_rest');

        status_header(200);
        nocache_headers();

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Traxs</title>';
        echo '<link rel="stylesheet" href="' . esc_url($css_traxs)   . '">';
        echo '<link rel="stylesheet" href="' . esc_url($css_receive) . '">';
        echo '<link rel="stylesheet" href="' . esc_url($css_modal)   . '">';
        echo '</head><body class="traxs-app"><div id="traxs-root"></div>';
        echo '<script>window.TRAXS={url:"' . esc_js(TRAXS_URL) . '",site:"' . esc_js(home_url()) . '"};</script>';
        echo '<script>window.wpApiSettings={root:"' . esc_js($rest_root) . '",nonce:"' . esc_js($rest_nonce) . '"};</script>';
        echo '<script type="module" src="' . esc_url($js_app) . '"></script>';
        echo '<script src="' . esc_url($js_autosave) . '"></script>';
        echo '</body></html>';
        exit;
    }
}
