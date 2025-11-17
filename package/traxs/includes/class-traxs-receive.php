<?php
/*
 * File: includes/class-traxs-receive.php
 * Description: Persist receive events and flip orders / work-order readiness based on PO receipt.
 */
namespace Traxs;
if (!defined('ABSPATH')) exit;

class Receive {

    /**
     * Handle a receive payload for a single PO.
     *
     * Expected payload (current wire format):
     * [
     *   'po_id'  => (int) eject_po post ID,
     *   'po_number' => 'BT-20251106-001',          // optional but preferred
     *   'lines'  => [
     *      [
     *        'po_line_id' => '996m|black|2xl',
     *        'add_qty'    => 3,        // increment received by this amount
     *      ],
     *      ...
     *   ]
     * ]
     *
     * This method:
     *  - Inserts receipt rows into traxs_receipts (incremental).
     *  - Re-reads PO lines via Eject_Bridge::get_po_lines($po_id).
     *  - Computes:
     *      - PO fully received vs partial (your scenarios 1/2 vs 3).
     *      - Per-order readiness:
     *          * READY_NORMAL  → all goods for that order in this PO are at least fully received.
     *          * READY_MISSING → some goods for that order in this PO are short.
     *  - Sets order meta for Print Work Orders:
     *      - _traxs_ready_for_workorder      = 'yes'
     *      - _traxs_workorder_missing_goods  = 'yes' (only for partial / missing-goods orders)
     *  - If the PO is fully received (no short lines), closes the PO so it drops out
     *    of the “Receive Goods” list.
     */
    public static function handle_receive(array $payload): array {
        global $wpdb;

        $user_id = get_current_user_id();
        $po_id   = (int)($payload['po_id'] ?? 0);
        $lines   = (array)($payload['lines'] ?? []);

        if (!$po_id || !$user_id || empty($lines)) {
            return ['ok' => false, 'msg' => 'invalid payload'];
        }

        // --- Derive the correct PO number string for traxs_receipts.po_number ---
        // Prefer the wire value from the SPA, then the post meta _po_number, then fall back to ID.
        $po_number = '';
        if (!empty($payload['po_number'])) {
            $po_number = (string) $payload['po_number'];
        } else {
            $meta_po_number = (string) get_post_meta($po_id, '_po_number', true);
            if ($meta_po_number !== '') {
                $po_number = $meta_po_number;
            } else {
                $po_number = (string) $po_id;
            }
        }

        // --- 1) Persist incremental receipts into traxs_receipts ---
        foreach ($lines as $ln) {
            $line_id = sanitize_text_field($ln['po_line_id'] ?? '');

            // Support both old ('add_qty') and new ('actual_qty') wire formats.
            $raw_add = $ln['add_qty'] ?? $ln['actual_qty'] ?? 0;
            $add     = max(0, (int) $raw_add);

            if (!$line_id || $add <= 0) {
                continue;
            }

            $wpdb->insert($wpdb->prefix . 'traxs_receipts', [
                'po_number'    => $po_number,            // IMPORTANT: use real PO number, not post ID
                'po_line_id'   => $line_id,
                'wc_order_id'  => 0,                     // optional per-row; we flip per order_ids below
                'sku'          => '',
                'ordered_qty'  => 0,                     // not required for incremental entries
                'received_qty' => $add,
                'status'       => 'ok',
                'user_id'      => $user_id,
                'created_at'   => current_time('mysql'),
            ]);
        }

        // --- 2) Recompute PO lines from Eject (single source of truth) ---
        $all_lines = Eject_Bridge::get_po_lines($po_id);
        if (!is_array($all_lines)) {
            $all_lines = [];
        }

        // PO-level flags
        $any_short = false;   // at least one line received < ordered → partial PO
        $any_lines = false;   // sanity check: PO has lines at all

        // Per-order flags: [ order_id => ['any_short'=>bool, 'any_received'=>bool] ]
        $order_flags = [];
        $orders_flat = [];    // for the old "flip all to processing" behavior

        foreach ($all_lines as $ln) {
            $any_lines = true;

            $ordered  = isset($ln['ordered_qty'])  ? (int)$ln['ordered_qty']  : 0;
            $received = isset($ln['received_qty']) ? (int)$ln['received_qty'] : 0;

            $line_short    = ($received < $ordered);   // your scenario 3 trigger
            $line_received = ($received > 0);         // anything actually received

            if ($line_short) {
                $any_short = true;
            }

            $order_ids = isset($ln['order_ids']) && is_array($ln['order_ids'])
                ? $ln['order_ids']
                : [];

            foreach ($order_ids as $oid) {
                $oid = (int)$oid;
                if ($oid <= 0) continue;

                if (!isset($order_flags[$oid])) {
                    $order_flags[$oid] = [
                        'any_short'   => false,
                        'any_received'=> false,
                    ];
                }

                if ($line_short) {
                    $order_flags[$oid]['any_short'] = true;
                }
                if ($line_received) {
                    $order_flags[$oid]['any_received'] = true;
                }

                $orders_flat[$oid] = true;
            }
        }

        // No lines? Nothing more to do.
        if (!$any_lines) {
            return [
                'ok'             => true,
                'po_id'          => $po_id,
                'fully_received' => false,
                'orders'         => [],
                'note'           => 'PO has no lines after receive; no status changes applied.',
            ];
        }

        // --- 3) PO-level decision (your scenarios 1/2 vs 3) ---
        $po_fully_received = !$any_short;

        if ($po_fully_received && !empty($orders_flat)) {
            if (class_exists(__NAMESPACE__ . '\\WC_Bridge')) {
                foreach (array_keys($orders_flat) as $oid) {
                    WC_Bridge::set_processing((int)$oid);
                }
            }

            // Close the PO so it drops out of "open POs" (Receive Goods list)
            wp_update_post([
                'ID'          => $po_id,
                'post_status' => 'publish',
            ]);
        }

        // --- 4) Per-order work-order readiness flags (for Print Work Orders) ---
        $orders_result = [];

        foreach ($order_flags as $oid => $flags) {
            $oid = (int)$oid;
            if ($oid <= 0) continue;

            if (!$flags['any_received']) {
                $orders_result[$oid] = [
                    'ready'   => false,
                    'missing' => false,
                ];
                continue;
            }

            $is_missing = $flags['any_short'] ? true : false;

            update_post_meta($oid, '_traxs_ready_for_workorder', 'yes');

            if ($is_missing) {
                update_post_meta($oid, '_traxs_workorder_missing_goods', 'yes');
            } else {
                delete_post_meta($oid, '_traxs_workorder_missing_goods');
            }

            $orders_result[$oid] = [
                'ready'   => true,
                'missing' => $is_missing,
            ];
        }

        return [
            'ok'             => true,
            'po_id'          => $po_id,
            'fully_received' => $po_fully_received,
            'orders'         => $orders_result,
        ];
    }
}
