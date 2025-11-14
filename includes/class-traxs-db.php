<?php
/*
 * File: includes/class-traxs-db.php
 * Description: Creates Traxs tables; simple helpers.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 21:21:59 EDT
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class DB {
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        $sql = [];
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}traxs_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            po_number varchar(64), po_line_id varchar(64),
            wc_order_id BIGINT, sku varchar(128),
            ordered_qty INT, received_qty INT, status varchar(16),
            user_id BIGINT, created_at datetime DEFAULT CURRENT_TIMESTAMP
        ) $charset;";
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}traxs_work_orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            wo_number varchar(64), wc_order_id BIGINT,
            status varchar(16), production_type varchar(32),
            created_at datetime DEFAULT CURRENT_TIMESTAMP
        ) $charset;";
        $sql[] = "CREATE TABLE IF NOT EXISTS {$p}traxs_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            wo_number varchar(64), wc_order_id BIGINT,
            station varchar(16), direction varchar(8),
            user_id BIGINT, result varchar(16), message text,
            ip varchar(64), ua varchar(255),
            created_at datetime DEFAULT CURRENT_TIMESTAMP
        ) $charset;";
        require_once(ABSPATH.'wp-admin/includes/upgrade.php');
        foreach($sql as $q) dbDelta($q);
    }
}
