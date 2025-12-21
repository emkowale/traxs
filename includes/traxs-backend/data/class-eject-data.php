<?php
/*
 * File: includes/data/class-eject-data.php
 * Description: Data helpers for runs/POs (create/find, merge items, counters, exceptions).
 * Plugin: Eject
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-04 EDT
 */
if (!defined('ABSPATH')) exit;

class Eject_Data {

    /** Return draft run for vendor; create if missing. Always initializes `_items` JSON object. */
    public static function get_or_create_run(string $vendor): int {
        $vendor = trim($vendor);
        if ($vendor === '') return 0;

        // Try to find an open run (draft) for this vendor
        $posts = get_posts([
            'post_type'   => 'eject_run',
            'post_status' => 'draft',
            'numberposts' => -1,
            'meta_key'    => '_vendor_name',
            'meta_value'  => $vendor,
            'fields'      => 'ids',
        ]);

        $po_id = 0;

        if (!empty($posts)) {
            // Prefer exact match by case; otherwise fall back to first where lowercase matches.
            foreach ($posts as $pid) {
                $name = get_post_meta($pid, '_vendor_name', true);
                if ($name === $vendor || strtolower($name) === strtolower($vendor)) {
                    $po_id = (int)$pid;
                    break;
                }
            }
            if (!$po_id) $po_id = (int)$posts[0];
        }

        if (!$po_id) {
            $po_id = wp_insert_post([
                'post_type'   => 'eject_run',
                'post_status' => 'draft',
                'post_title'  => $vendor . ' (Run)',
            ], true);

            if (is_wp_error($po_id)) return 0;

            update_post_meta($po_id, '_vendor_name', $vendor);
            // Initialize empty items map if not present
            if (!get_post_meta($po_id, '_items', true)) {
                update_post_meta($po_id, '_items', '{}');
            }
        } else {
            // Ensure `_items` exists even on legacy runs
            if (!get_post_meta($po_id, '_items', true)) {
                update_post_meta($po_id, '_items', '{}');
            }
        }

        return (int)$po_id;
    }

    /** Merge a single WC line into the run’s `_items` JSON (key: item|color|size, lowercased). */
    public static function add_line(int $po_id, array $p): bool {
        if (!$po_id) return false;

        $item   = trim((string)($p['item'] ?? ''));
        $color  = (string)($p['color'] ?? 'N/A'); if ($color === '') $color = 'N/A';
        $size   = (string)($p['size']  ?? 'N/A'); if ($size === '')  $size  = 'N/A';
        $qty    = max(0, (int)($p['qty'] ?? 0));
        $oid    = (int)($p['order_id'] ?? 0);
        $oiid   = (int)($p['order_item_id'] ?? 0);

        if ($item === '' || $qty <= 0 || !$oiid || !$oid) return false;

        $raw   = get_post_meta($po_id, '_items', true);
        $items = $raw ? json_decode($raw, true) : [];
        if (!is_array($items)) $items = [];

        $key = strtolower($item . '|' . $color . '|' . $size);
        if (empty($items[$key])) {
            $items[$key] = [
                'item'           => $item,
                'color'          => $color,
                'size'           => $size,
                'qty'            => 0,
                'order_item_ids' => [],
                'order_ids'      => [],
            ];
        }

        $items[$key]['qty'] += $qty;

        $items[$key]['order_item_ids'][] = $oiid;
        $items[$key]['order_item_ids']   = array_values(array_unique(array_map('intval', $items[$key]['order_item_ids'])));

        $items[$key]['order_ids'][]      = $oid;
        $items[$key]['order_ids']        = array_values(array_unique(array_map('intval', $items[$key]['order_ids'])));

        update_post_meta($po_id, '_items', wp_json_encode($items));
        return true;
    }

    /** Remove a keyed line from the run (expects lowercased key). */
    public static function remove_line(int $po_id, string $key): bool {
        if (!$po_id || $key === '') return false;
        $raw   = get_post_meta($po_id, '_items', true);
        $items = $raw ? json_decode($raw, true) : [];
        if (!is_array($items) || empty($items[$key])) return false;
        unset($items[$key]);
        update_post_meta($po_id, '_items', wp_json_encode($items));
        return true;
    }

    /** Append an exception record to the run. */
    public static function add_exception(int $po_id, array $rec): bool {
        if (!$po_id) return false;
        $raw = get_post_meta($po_id, '_exceptions', true);
        $arr = $raw ? json_decode($raw, true) : [];
        if (!is_array($arr)) $arr = [];
        $arr[] = [
            'item'   => (string)($rec['item'] ?? ''),
            'color'  => (string)($rec['color'] ?? ''),
            'size'   => (string)($rec['size'] ?? ''),
            'reason' => (string)($rec['reason'] ?? 'Unavailable'),
            'ts'     => current_time('mysql'),
        ];
        update_post_meta($po_id, '_exceptions', wp_json_encode($arr));
        return true;
    }

    /** Generate the next PO number for a vendor, honoring prefix & optional daily reset. */
    public static function next_po_number(string $vendor): string {
        $opts   = get_option('eject_options', []);
        $prefix = isset($opts['prefix']) && $opts['prefix'] !== '' ? $opts['prefix'] : 'BT';
        $date   = date_i18n('Y-m-d');
        $ymd    = date_i18n('Ymd');

        // Count existing POs today for this vendor to increment the suffix
        $posts = get_posts([
            'post_type'   => 'eject_run',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => [
                ['key' => '_vendor_name', 'value' => $vendor],
                ['key' => '_po_date',     'value' => $date],
            ],
            'fields'      => 'ids',
        ]);

        $n = is_array($posts) ? count($posts) : 0;
        $n = $n + 1;
        $suffix = str_pad((string)$n, 3, '0', STR_PAD_LEFT);

        return sprintf('%s-%s-%s', $prefix, $ymd, $suffix);
    }
}
