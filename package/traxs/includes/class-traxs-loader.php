<?php
/*
 * File: includes/class-traxs-loader.php
 * Description: Loads modules; central hooks; boots assets.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-16 09:30 EDT
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Loader {

    /** Activation: DB + roles */
    public static function activate() {
        require_once TRAXS_PATH . 'includes/class-traxs-db.php';
        DB::install();

        require_once TRAXS_PATH . 'includes/class-traxs-roles.php';
        Roles::register();
    }

    /** Runtime boot */
    public static function init() {
        // --- Core modules ---
        require_once TRAXS_PATH . 'includes/class-traxs-roles.php';
        Roles::register();

        // Custom login page styling
        require_once TRAXS_PATH . 'includes/login-style.php';

        require_once TRAXS_PATH . 'includes/class-traxs-router.php';
        require_once TRAXS_PATH . 'includes/class-traxs-ui.php';
        require_once TRAXS_PATH . 'includes/class-traxs-settings.php';
        require_once TRAXS_PATH . 'includes/class-traxs-logs.php';
        require_once TRAXS_PATH . 'includes/class-traxs-db.php';
        require_once TRAXS_PATH . 'includes/class-traxs-wc-bridge.php';
        require_once TRAXS_PATH . 'includes/class-traxs-eject-bridge.php';

        // New receive engine (keep this; do NOT load the legacy file)
        require_once TRAXS_PATH . 'includes/class-traxs-receive.php';

        // Printing + QR
        require_once TRAXS_PATH . 'includes/class-traxs-print.php';
        require_once TRAXS_PATH . 'includes/class-traxs-qr.php';
        // Work orders PDF REST endpoint (orders based on received POs)
        require_once TRAXS_PATH . 'includes/class-traxs-rest-workorders.php';

        // Emails, events, scan
        require_once TRAXS_PATH . 'includes/class-traxs-emails.php';
        require_once TRAXS_PATH . 'includes/class-traxs-events.php';
        require_once TRAXS_PATH . 'includes/class-traxs-scan.php';

        // REST layer shim (registers routes); this replaces the legacy class-traxs-rest-receive.php
        require_once TRAXS_PATH . 'includes/rest-traxs.php';
        require_once TRAXS_PATH . 'includes/rest-traxs-drafts.php';

        // --- Assets bootstrap (adds CSS + app.js + pos-list.js) ---
        require_once TRAXS_PATH . 'includes/class-traxs-assets.php';
        \Traxs_Assets::init();

        // --- Hooks ---
        add_action('init', ['\\Traxs\\Router', 'register_routes']);
        add_action('init', ['\\Traxs\\UI', 'register_assets']);
        add_action('admin_init', ['\\Traxs\\Settings', 'register']);
        add_action('admin_menu', ['\\Traxs\\Settings', 'menu']);
    }
}

\Traxs\Loader::init();
