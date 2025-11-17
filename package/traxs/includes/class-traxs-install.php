<?php
/*
 * File: includes/class-traxs-install.php
 * Description: Ensures the Traxs receipts table exists.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */

if (!defined('ABSPATH')) exit;

class Traxs_Install {
    const OPT = 'traxs_db_version';
    const VER = '1';

    public static function init() {
        // Run on both front/admin; cheap check and only migrates if needed.
        add_action('wp_loaded',  [__CLASS__, 'maybe_install'], 5);
        add_action('admin_init', [__CLASS__, 'maybe_install'], 5);
    }

    public static function maybe_install() {
        $have = get_option(self::OPT);
        if ($have === self::VER) return;

        global $wpdb;
        $table = $wpdb->prefix . 'traxs_receipts';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            po_number varchar(32) NOT NULL,
            po_line_id varchar(191) NOT NULL,
            received_qty int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY po_number (po_number),
            KEY po_line_id (po_line_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::OPT, self::VER);
    }
}

Traxs_Install::init();
