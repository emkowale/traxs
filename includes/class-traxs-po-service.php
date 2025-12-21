<?php
/*
 * File: includes/class-traxs-po-service.php
 * Description: Exposes ordered Traxs POs and their lines that still require receiving.
 * Plugin: Traxs
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Traxs_PO_Service {

    public static function get_open_pos(int $limit = 50): array {
        $args = [
            'post_type'      => 'eject_po',
            'post_status'    => ['publish'],
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_key'       => '_ordered',
            'meta_value'     => '1',
        ];

        $q = new \WP_Query($args);
        $out = [];

        foreach ($q->posts as $p) {
            $po_number = self::display_po_number($p->ID);
            $items = self::get_po_items($p->ID);
            if (!self::needs_receive($items, self::po_number_string($p->ID))) {
                continue;
            }

            $out[] = [
                'po_id'       => (int) $p->ID,
                'po_post_id'  => (int) $p->ID,
                'po_number'   => $po_number,
                'vendor'      => (string) \get_post_meta($p->ID, '_vendor_id', true),
                'title'       => (string) $p->post_title,
                'created'     => $p->post_date,
                'items_count' => \count($items),
                'status'      => $p->post_status,
            ];
        }

        return $out;
    }

    public static function get_po_lines(int $po_id): array {
        if ($po_id <= 0) {
            return [];
        }

        $items = self::get_po_items($po_id);
        if (empty($items)) {
            return [];
        }

        $po_number = self::po_number_string($po_id);
        $lines = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $line_id = self::build_line_id($item);
            if ($line_id === '') {
                continue;
            }

            $order_ids = [];
            if (!empty($item['order_ids']) && \is_array($item['order_ids'])) {
                foreach ($item['order_ids'] as $oid) {
                    $oid = (int) $oid;
                    if ($oid > 0) {
                        $order_ids[$oid] = $oid;
                    }
                }
            }
            $order_ids_array = \array_values($order_ids);

            $lines[] = [
                'po_line_id'   => $line_id,
                'item'         => (string) ($item['vendor_item'] ?? $item['product'] ?? ''),
                'color'        => (string) ($item['color'] ?? ''),
                'size'         => (string) ($item['size'] ?? ''),
                'ordered_qty'  => max(0, (int) ($item['qty'] ?? 0)),
                'received_qty' => self::received_qty($po_number, $line_id),
                'order_ids'    => $order_ids_array,
                'orders'       => self::build_order_refs($order_ids_array),
            ];
        }

        return $lines;
    }

    private static function get_po_items(int $po_id): array {
        $raw   = (string) \get_post_meta($po_id, '_items', true);
        $items = $raw ? \json_decode($raw, true) : [];
        return \is_array($items) ? $items : [];
    }

    private static function needs_receive(array $items, string $po_number): bool {
        if ($po_number === '') {
            return true;
        }

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $ordered = max(0, (int) ($item['qty'] ?? 0));
            if ($ordered <= 0) {
                continue;
            }

            $line_id = self::build_line_id($item);
            if ($line_id === '') {
                continue;
            }

            $received = self::received_qty($po_number, $line_id);
            if ($received < $ordered) {
                return true;
            }
        }

        return false;
    }

    private static function po_number_string(int $po_id): string {
        $po_number = (string) \get_post_meta($po_id, '_po_number', true);
        return $po_number !== '' ? $po_number : (string) $po_id;
    }

    private static function display_po_number(int $po_id): string {
        $po_number = self::po_number_string($po_id);
        if ($po_number !== '') {
            return $po_number;
        }
        $post = \get_post($po_id);
        if ($post && $post->post_title) {
            return (string) $post->post_title;
        }
        return (string) $po_id;
    }

    private static function receipts_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'traxs_receipts';
    }

    private static function receipts_table_exists(): bool {
        static $exists;
        if (isset($exists)) {
            return $exists;
        }
        global $wpdb;
        $table = self::receipts_table();
        $like  = $wpdb->esc_like($table);
        $exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like));
        return $exists;
    }

    private static function received_qty(string $po_number, string $line_id): int {
        if ($po_number === '' || $line_id === '') {
            return 0;
        }

        if (!self::receipts_table_exists()) {
            return 0;
        }

        global $wpdb;
        $table = self::receipts_table();
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(received_qty) FROM {$table} WHERE po_number=%s AND po_line_id=%s",
            $po_number,
            $line_id
        ));
        return $sum ? (int) $sum : 0;
    }

    private static function build_line_id(array $item): string {
        $parts = [
            self::normalize_segment((string) ($item['vendor_item'] ?? $item['product'] ?? ''), 'item'),
            self::normalize_segment((string) ($item['color'] ?? ''), 'n/a'),
            self::normalize_segment((string) ($item['size'] ?? ''), 'n/a'),
        ];
        return \implode('|', $parts);
    }

    private static function normalize_segment(string $value, string $fallback): string {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return $fallback;
        }
        return \str_replace('|', ' ', $value);
    }

    private static function build_order_refs(array $order_ids): array {
        $refs = [];
        foreach ($order_ids as $order_id) {
            $order_id = (int) $order_id;
            if ($order_id <= 0) {
                continue;
            }
            $refs[] = [
                'order_id'     => $order_id,
                'order_number' => self::order_number_for_id($order_id),
            ];
        }
        return $refs;
    }

    private static function order_number_for_id(int $order_id): string {
        static $cache = [];
        if (isset($cache[$order_id])) {
            return $cache[$order_id];
        }
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order) {
            $cache[$order_id] = (string) $order->get_order_number();
        } else {
            $cache[$order_id] = (string) $order_id;
        }
        return $cache[$order_id];
    }
}
