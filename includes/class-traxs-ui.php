<?php
/*
 * File: includes/class-traxs-ui.php
 * Description: Registers base assets (theme/page prints app.js directly).
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-06 EDT
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class UI {
    public static function register_assets() {
        // Keep lightweight registrations only; no direct output here.
        if (!defined('TRAXS_VERSION')) define('TRAXS_VERSION', '1.0.0');
        wp_register_script('traxs-app', TRAXS_URL . 'assets/js/app.js', [], TRAXS_VERSION, true);
        wp_register_style('traxs-css', TRAXS_URL . 'assets/css/traxs.css', [], TRAXS_VERSION);
        // NOTE: The /traxs template shell prints the actual tags.
    }
}
