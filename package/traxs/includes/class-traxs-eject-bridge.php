<?php
/*
 * File: includes/class-traxs-eject-bridge.php
 * Description: Read finalized POs from Eject (CPT eject_run with _po_number).
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Eject_Bridge {

    /**
     * Return finalized POs (ordered) from Eject.
     * A PO is an eject_run post in status 'publish' that has meta _po_number.
     * Output shape matches prior UI expectations.
     */
    public static function get_open_pos(): array {
        $q = new \WP_Query([
            'post_type'      => 'eject_run',
            'post_status'    => ['publish'],              // ordered
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_po_number',
                    'compare' => 'EXISTS',               // only real POs
                ],
            ],
        ]);

        $out = [];
        foreach ($q->posts as $p) {
            $vendor   = (string) get_post_meta($p->ID, '_vendor_name', true);
            $po_num   = (string) get_post_meta($p->ID, '_po_number',   true);
            $itemsRaw = (string) get_post_meta($p->ID, '_items',        true);
            $count    = 0;
            if ($itemsRaw) {
                $arr = json_decode($itemsRaw, true);
                if (is_array($arr)) $count = count($arr);
            }
            // keep keys stable for current UI
            $out[] = [
                'po_id'       => (int) $p->ID,
                'po_number'   => $po_num,
                'vendor'      => $vendor,
                'title'       => $p->post_title,
                'created'     => $p->post_date,
                'items_count' => $count,
                'status'      => 'ordered',
            ];
        }
        return $out;
    }

    /**
     * Expand PO lines for a given eject_run post ID.
     * Reads _items (JSON) and returns rows; received_qty left as 0 for now
     * unless you’ve already created the traxs_receipts table.
     */
    public static function get_po_lines(int $po_id): array {
        $items = get_post_meta($po_id, '_items', true);
        if (!$items) return [];
        $data = json_decode($items, true);
        if (!is_array($data)) return [];

        $lines = [];
        foreach ($data as $key => $row) {
            $lines[] = [
                'po_line_id'     => (string) $key,
                'item'           => (string) ($row['item']  ?? ''),
                'color'          => (string) ($row['color'] ?? ''),
                'size'           => (string) ($row['size']  ?? ''),
                'ordered_qty'    => (int)    ($row['qty']   ?? 0),
                'received_qty'   => 0, // will be filled from receipts when wired
                'order_item_ids' => array_map('intval', (array)($row['order_item_ids'] ?? [])),
                'order_ids'      => array_map('intval', (array)($row['order_ids']      ?? [])),
            ];
        }
        return $lines;
    }
}
