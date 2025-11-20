<?php
/*
 * 1.0.3
 * Plugin Name: Traxs
 * Plugin URI: https://github.com/emkowale/traxs
 * Description: Smart Purchase Order & Receiving Workflow for WordPress.
 * Author: Eric Kowalewski
 * Author URI: https://github.com/emkowale
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Update URI: https://github.com/emkowale/traxs
 *
 * GitHub Plugin URI: https://github.com/emkowale/traxs
 * Primary Branch: main
 */

if (!defined('ABSPATH')) exit;
define('TRAXS_VERSION','1.0.2');
if (!defined('TRAXS_PATH')) define('TRAXS_PATH', plugin_dir_path(__FILE__));
if (!defined('TRAXS_URL')) define('TRAXS_URL', plugin_dir_url(__FILE__));

// Ensure login screen branding/styles always load.
require_once TRAXS_PATH . 'includes/login-style.php';
/**
 * Register /traxs/ endpoint
 */
function traxs_register_routes() {
    add_rewrite_tag('%traxs%', '1');
    add_rewrite_rule('^traxs/?$', 'index.php?traxs=1', 'top');
}
add_action('init', 'traxs_register_routes');

/**
 * Render SPA container for /traxs/
 */
	add_action('template_redirect', function() {
	    global $wp_query;
	    if (isset($wp_query->query_vars['traxs'])) {
	        if (!is_user_logged_in()) {
	            auth_redirect();
	            exit;
	        }

	        if (function_exists('show_admin_bar')) {
	            show_admin_bar(false);
	        }

	        status_header(200);
	        nocache_headers();
	        echo '<!DOCTYPE html><html><head>';
	        echo '<meta charset="utf-8">';
	        echo '<style id="traxs-admin-bar-reset">html{margin-top:0!important;}body{margin-top:0!important;}#wpadminbar{display:none!important;}</style>';
	        wp_head();
	        $body_classes = array_map('sanitize_html_class', array_merge(get_body_class(), ['traxs-app', 'traxs-kiosk']));
	        echo '<body class="' . esc_attr(implode(' ', array_unique($body_classes))) . '">';
	        echo '<div id="traxs-root" class="traxs-root"></div>';
	        wp_footer();
	        echo '</body></html>';
	        exit;
	    }
});

/**
 * Enqueue scripts and styles
 */
function traxs_enqueue_scripts() {
    global $wp_query;
    if (!isset($wp_query->query_vars['traxs'])) return;

    $base = plugin_dir_url(__FILE__) . 'assets/js/';
    $dir  = plugin_dir_path(__FILE__) . 'assets/js/';
    $v = fn($f) => file_exists($dir.$f) ? filemtime($dir.$f) : time();

    // Register non-module scripts (classic helpers)
    wp_enqueue_script('traxs-receive-autosave', $base.'receive-autosave.js', [], $v('receive-autosave.js'), true);
    wp_enqueue_script('traxs-receive-complete', $base.'receive-complete.js', [], $v('receive-complete.js'), true);
    wp_enqueue_script('traxs-main', $base.'traxs-main.js', [], $v('traxs-main.js'), true);

    wp_localize_script('traxs-main', 'wpApiSettings', [
        'root'  => esc_url_raw(rest_url()),
        'nonce' => wp_create_nonce('wp_rest')
    ]);

    // ✅ Load ES module bundle (modern files)
    add_action('wp_print_footer_scripts', function() use ($base, $v) {
        $ver_ui  = $v('ui.js');
        $ver_app = $v('app.js');
        $ver_recv = $v('ui-receive.js');
        echo '<script type="module" src="' . esc_url($base . 'ui-receive.js?ver=' . $ver_recv) . '"></script>' . "\n";
        echo '<script type="module" src="' . esc_url($base . 'ui.js?ver=' . $ver_ui) . '"></script>' . "\n";
        echo '<script type="module" src="' . esc_url($base . 'app.js?ver=' . $ver_app) . '"></script>' . "\n";
    });

    // Optional CSS
    $css_file = plugin_dir_url(__FILE__) . 'assets/css/traxs.css';
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/traxs.css';
    if (file_exists($css_path)) {
        wp_enqueue_style('traxs-style', $css_file, [], filemtime($css_path));
    }

    $spinner_css = plugin_dir_url(__FILE__) . 'assets/css/traxs-spinner.css';
    $spinner_path = plugin_dir_path(__FILE__) . 'assets/css/traxs-spinner.css';
    if (file_exists($spinner_path)) {
        wp_enqueue_style('traxs-spinner', $spinner_css, [], filemtime($spinner_path));
    }
}

add_action('wp_enqueue_scripts', 'traxs_enqueue_scripts');

/**
 * Auto-include REST endpoints
 */
foreach (glob(plugin_dir_path(__FILE__) . 'includes/rest-*.php') as $rest_file) {
    include_once $rest_file;
}

/**
 * Activation hook - flush rewrites
 */
register_activation_hook(__FILE__, function() {
    traxs_register_routes();
    flush_rewrite_rules();
});
