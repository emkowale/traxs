<?php
/*
 * File: includes/class-traxs-assets.php
 * Description: Load Traxs global CSS, per-screen CSS, and main SPA JS.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-13 EDT
 */

if (!defined('ABSPATH')) exit;

class Traxs_Assets {

    public static function init() {
        add_action('wp_enqueue_scripts',  [__CLASS__, 'enqueue_common']);
        add_action('admin_enqueue_scripts',[__CLASS__, 'enqueue_common']);
    }

    public static function enqueue_common() {
        // CSS paths
        $base_url_css  = plugin_dir_url(dirname(__FILE__)) . 'assets/css/';
        $base_path_css = plugin_dir_path(dirname(__FILE__)) . 'assets/css/';

        $ver_base  = defined('TRAXS_VERSION') ? TRAXS_VERSION : time();
        $global_ver  = @filemtime($base_path_css . 'traxs.css') ?: $ver_base;
        $receive_ver = @filemtime($base_path_css . 'traxs-receive.css') ?: $global_ver;
        $modal_ver   = @filemtime($base_path_css . 'spa-modal.css') ?: $global_ver;

        // Global SPA layout
        wp_enqueue_style(
            'traxs-global',
            $base_url_css . 'traxs.css',
            [],
            $global_ver
        );

        // Goods to Receive screen styling
        wp_enqueue_style(
            'traxs-receive',
            $base_url_css . 'traxs-receive.css',
            ['traxs-global'],
            $receive_ver
        );

        // Modal CSS
        wp_enqueue_style(
            'traxs-spa-modal',
            $base_url_css . 'spa-modal.css',
            [],
            $modal_ver
        );

        // Google Fonts
        wp_enqueue_style(
            'traxs-google-fonts',
            'https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@400;700&display=swap',
            [],
            null
        );

        // Style login submit button via classes only
        add_action('login_footer', function () {
            echo '<script>document.getElementById("wp-submit")?.classList.add("traxs-btn","primary");</script>';
        });

        // JS — main SPA (ES module)
        $base_url_js  = plugin_dir_url(dirname(__FILE__)) . 'assets/js/';
        $base_path_js = plugin_dir_path(dirname(__FILE__)) . 'assets/js/';
        $app_ver      = @filemtime($base_path_js . 'app.js') ?: $ver_base;

        wp_enqueue_script(
            'traxs-app',
            $base_url_js . 'app.js',
            [],
            $app_ver,
            true
        );
        wp_script_add_data('traxs-app', 'type', 'module');

        wp_enqueue_script(
            'traxs-receive-autosave',
            $base_url_js . 'receive-autosave.js',
            ['traxs-app'],
            @filemtime($base_path_js . 'receive-autosave.js') ?: $ver_base,
            true
        );
    }
}

Traxs_Assets::init();
