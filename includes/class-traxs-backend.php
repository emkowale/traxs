<?php
/*
 * File: includes/class-traxs-backend.php
 * Description: Entry point for Traxs backend features (formerly Eject).
 * Plugin: Traxs
 */

namespace Traxs;
if (!defined('ABSPATH')) exit;

class Traxs_Backend {
    /** @var bool */
    private static $loaded = false;

    /** Bootstrap any backend features. */
    public static function init(): void {
        self::ensure_loaded();

        add_action('init', [self::class, 'register_post_type']);
        add_action('admin_menu', [self::class, 'register_admin_menu']);
        add_action('init', [self::class, 'register_ajax']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);

        self::register_workorders();
    }

    /** Register the PO CPT (delegates to legacy class). */
    public static function register_post_type(): void {
        self::ensure_loaded();
        if (class_exists('\\Eject_CPT')) {
            \Eject_CPT::register();
        }
    }

    /** Register the admin menu for Traxs backend POs. */
    public static function register_admin_menu(): void {
        self::ensure_loaded();
        if (class_exists('\\Eject_Admin')) {
            \Eject_Admin::register_menu();
        }
    }

    /** Register the legacy AJAX endpoints. */
    public static function register_ajax(): void {
        self::ensure_loaded();
        if (class_exists('\\Eject_Ajax')) {
            \Eject_Ajax::register();
        }
    }

    /** Hook the workorder generator. */
    public static function register_workorders(): void {
        self::ensure_loaded();
        if (class_exists('\\Eject_Workorders')) {
            \Eject_Workorders::register();
        }
    }

    /** Enqueue the backend admin assets for the Traxs menu. */
    public static function enqueue_admin_assets($hook): void {
        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        $is_backend_screen = ($page === 'eject') || strpos((string)$hook, 'eject') !== false;
        if (!$is_backend_screen) {
            return;
        }

        if (!defined('TRAXS_BACKEND_URL') || !defined('TRAXS_BACKEND_VERSION')) {
            return;
        }

        wp_enqueue_style(
            'traxs-backend-admin',
            TRAXS_BACKEND_URL . 'css/admin.css',
            [],
            TRAXS_BACKEND_VERSION
        );
        wp_enqueue_script(
            'traxs-backend-admin',
            TRAXS_BACKEND_URL . 'js/admin.js',
            ['jquery'],
            TRAXS_BACKEND_VERSION,
            true
        );

        wp_localize_script('traxs-backend-admin', 'TRAXS_BACKEND', [
            'nonce'    => wp_create_nonce('eject_admin'),
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }

    /** Load legacy backend files once. */
    private static function ensure_loaded(): void {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $files = [
            'class-eject-cpt.php',
            'class-eject-service.php',
            'class-eject-admin.php',
            'data/class-eject-data.php',
            'class-eject-ajax.php',
            'class-eject-workorders.php',
            'eject-hooks.php',
        ];

        foreach ($files as $file) {
            require_once TRAXS_BACKEND_DIR . $file;
        }
    }
}
