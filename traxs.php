<?php
/*
 * Plugin Name: Traxs
 * Version: 1.0.20
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



define('PLUGIN_VERSION', '1.0.20');
if (!defined('TRAXS_VERSION')) define('TRAXS_VERSION','1.0.19');
if (!defined('TRAXS_PATH')) define('TRAXS_PATH', plugin_dir_path(__FILE__));
if (!defined('TRAXS_URL')) define('TRAXS_URL', plugin_dir_url(__FILE__));
if (!defined('TRAXS_BACKEND_DIR')) define('TRAXS_BACKEND_DIR', TRAXS_PATH . 'includes/traxs-backend/');
if (!defined('TRAXS_BACKEND_URL')) define('TRAXS_BACKEND_URL', TRAXS_URL . 'assets/traxs-backend/');
if (!defined('TRAXS_BACKEND_VERSION')) define('TRAXS_BACKEND_VERSION', '2.0.6');
if (!defined('EJECT_DIR')) define('EJECT_DIR', TRAXS_BACKEND_DIR);
if (!defined('EJECT_URL')) define('EJECT_URL', TRAXS_BACKEND_URL);
if (!defined('EJECT_VER')) define('EJECT_VER', TRAXS_BACKEND_VERSION);

// Ensure login screen branding/styles always load.
require_once TRAXS_PATH . 'includes/login-style.php';
require_once TRAXS_PATH . 'includes/class-traxs-backend.php';
add_action('init', function () {
    register_post_status('wc-on-order', [
        'label'                     => _x('On Order', 'Order status', 'eject'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('On Order <span class="count">(%s)</span>', 'On Order <span class="count">(%s)</span>', 'eject'),
    ]);
});
add_filter('wc_order_statuses', function ($statuses) {
    $new = [];
    foreach ($statuses as $key => $label) {
        $new[$key] = $label;
        if ($key === 'wc-on-hold') {
            $new['wc-on-order'] = _x('On Order', 'Order status', 'eject');
        }
    }
    return $new;
});
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
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">';
        echo '<meta name="format-detection" content="telephone=no">';
        echo '<style id="traxs-admin-bar-reset">html{margin-top:0!important;}body{margin-top:0!important;}#wpadminbar{display:none!important;}</style>';
	        wp_head();
	        $body_classes = array_map('sanitize_html_class', array_merge(get_body_class(), ['traxs-app', 'traxs-kiosk']));
        echo '<body class="' . esc_attr(implode(' ', array_unique($body_classes))) . '">';
        echo '<div id="traxs-root" class="traxs-root"></div>';
        echo '<div class="traxs-portrait-lock" aria-live="polite"><div>';
        echo '<p>Please rotate your device to portrait mode to continue using Traxs.</p>';
        echo '<span class="traxs-portrait-lock__icon">↺</span>';
        echo '</div></div>';
        wp_footer();
        echo '</body></html>';
        exit;
    }
});

/**
 * Prevent pinch-to-zoom on every loaded page.
 */
add_action('wp_head', function () {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">' . "\n";
}, 0);

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

    $print_workorder_url   = esc_url_raw(admin_url('admin-post.php') . '?action=eject_print_workorder');
    $print_workorder_nonce = wp_create_nonce('eject_print_workorder');
    wp_localize_script('traxs-main', 'wpApiSettings', [
        'root'                => esc_url_raw(rest_url()),
        'nonce'               => wp_create_nonce('wp_rest'),
        'printWorkorderUrl'   => $print_workorder_url,
        'printWorkorderNonce' => $print_workorder_nonce,
        'soundBase'           => plugin_dir_url(__FILE__) . 'assets/sounds/',
        'moduleBase'          => plugin_dir_url(__FILE__) . 'assets/js/',
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
 * Enqueue admin helpers.
 */
function traxs_enqueue_admin_scripts() {
    $base = plugin_dir_url(__FILE__) . 'assets/js/';
    $dir  = plugin_dir_path(__FILE__) . 'assets/js/';
    $v = fn($f) => file_exists($dir.$f) ? filemtime($dir.$f) : time();

    wp_enqueue_script('traxs-touch', $base.'traxs-touch.js', [], $v('traxs-touch.js'), true);
}
add_action('admin_enqueue_scripts', 'traxs_enqueue_admin_scripts');

/**
 * Auto-include REST endpoints
 */
foreach (glob(plugin_dir_path(__FILE__) . 'includes/rest-*.php') as $rest_file) {
    include_once $rest_file;
}

require_once TRAXS_PATH . 'includes/class-traxs-rest-workorders.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Traxs</strong> requires WooCommerce to be active.</p></div>';
        });
        deactivate_plugins(plugin_basename(__FILE__));
        return;
    }

    \Traxs\Traxs_Backend::init();
});

add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    if (!class_exists('Eject_Service')) {
        return;
    }
    \Eject_Service::maybe_mark_order_needs_reorder($order_id, $old_status, $new_status);
}, 10, 3);

/**
 * Activation hook - flush rewrites
 */
register_activation_hook(__FILE__, function() {
    traxs_register_routes();
    \Traxs\Traxs_Backend::register_post_type();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
