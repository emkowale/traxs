<?php
/*
 * File: includes/ajax/traits/trait-eject-ajax-run-lines.php
 * Description: Line operations (remove/exception) and PO delete/revert/reset with cleanup.
 * Plugin: Eject
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */
if (!defined('ABSPATH')) exit;

trait Eject_Ajax_Run_Lines {

    public static function remove_line() {
        Eject_Ajax::guard();
        $po_id = intval($_POST['po_id'] ?? 0);
        $key   = sanitize_text_field($_POST['line_key'] ?? '');
        if (!$po_id || !$key) wp_send_json_error(['message'=>'Missing args'], 400);
        Eject_Data::remove_line($po_id, $key);
        wp_send_json_success(['message'=>"Removed {$key}"]);
    }

    public static function add_exception() {
        Eject_Ajax::guard();
        $po_id = intval($_POST['po_id'] ?? 0);
        if (!$po_id) wp_send_json_error(['message'=>'Missing run'], 400);

        $rec = [
            'item'   => sanitize_text_field($_POST['item'] ?? ''),
            'color'  => sanitize_text_field($_POST['color'] ?? ''),
            'size'   => sanitize_text_field($_POST['size']  ?? ''),
            'reason' => sanitize_text_field($_POST['reason'] ?? 'Unavailable'),
        ];
        Eject_Data::add_exception($po_id, $rec);
        wp_send_json_success(['message'=>'Exception added']);
    }

    /**
     * Delete / Revert PO:
     * - If PO has NO items -> delete.
     * - Else merge items into a single open run for the vendor and revert to draft.
     * Also cleans up order notes/meta added during "Ordered".
     */
    public static function delete_or_revert_po() {
        Eject_Ajax::guard();

        $po_id = intval($_POST['po_id'] ?? 0);
        if (!$po_id) wp_send_json_error(['message'=>'Missing PO'], 400);

        $vendor = (string) get_post_meta($po_id,'_vendor_name',true);
        $po_no  = (string) get_post_meta($po_id,'_po_number',true);
        $items  = json_decode(get_post_meta($po_id,'_items',true) ?: '[]', true);
        $has_items = is_array($items) && !empty($items);

        if (!$has_items) {
            // Clean notes/meta referencing this PO (if any existed)
            self::cleanup_orders_for_items($items, $vendor, $po_no, true);
            delete_post_meta($po_id,'_eject_notes_written');
            wp_delete_post($po_id, true);
            wp_send_json_success(['status'=>'deleted','po_id'=>$po_id]);
        }

        // Ensure one open run for this vendor, then merge this PO's items into it.
        $target = Eject_Data::get_or_create_run($vendor);
        if ($target !== $po_id) {
            self::merge_items_into_run($target, $items);
            // Clean notes/meta referencing this PO on affected orders
            self::cleanup_orders_for_items($items, $vendor, $po_no, false);
            // Remove the PO record after merge
            delete_post_meta($po_id,'_eject_notes_written');
            wp_delete_post($po_id, true);
            $po_id = $target;
        } else {
            // Same record becomes the run again; still cleanup notes/meta
            self::cleanup_orders_for_items($items, $vendor, $po_no, false);
        }

        // Re-flag all order_item_ids as "in run" (stays out of Queue)
        $merged = json_decode(get_post_meta($po_id,'_items',true) ?: '[]', true);
        foreach ((array)$merged as $rec) {
            foreach ((array)($rec['order_item_ids'] ?? []) as $iid) {
                if ($iid) update_metadata('order_item', (int)$iid, 'eject_in_run', 1);
            }
        }

        // Draft title format
        wp_update_post(['ID'=>$po_id,'post_status'=>'draft','post_title'=>$vendor.' (Run)']);
        delete_post_meta($po_id,'_po_number');
        delete_post_meta($po_id,'_po_date');

        wp_send_json_success(['status'=>'reverted','po_id'=>$po_id]);
    }

    /**
     * Requeue PO:
     * - Clears eject flags on items (so they reappear in Queue).
     * - Clears order-level processed flags, removes Eject notes & guard meta.
     * - Deletes the PO record completely.
     */
    public static function requeue_po() {
        Eject_Ajax::guard();

        $po_id = intval($_POST['po_id'] ?? 0);
        if (!$po_id) wp_send_json_error(['message'=>'Missing PO'], 400);

        $vendor = (string) get_post_meta($po_id,'_vendor_name',true);
        $po_no  = (string) get_post_meta($po_id,'_po_number',true);
        $items  = json_decode(get_post_meta($po_id,'_items',true) ?: '[]', true);
        if (!is_array($items)) $items = [];

        // 1) Unflag item-level markers so they return to Queue
        $orders = [];
        $requeued_items = 0;
        foreach ((array)$items as $rec) {
            foreach ((array)($rec['order_item_ids'] ?? []) as $iid) {
                $iid = (int)$iid; if ($iid <= 0) continue;
                delete_metadata('order_item', $iid, 'eject_in_run');
                delete_metadata('order_item', $iid, 'eject_excluded');
                $requeued_items++;
            }
            foreach ((array)($rec['order_ids'] ?? []) as $oid) {
                $oid = (int)$oid; if ($oid > 0) $orders[$oid] = true;
            }
        }

        // 2) Clean orders completely (notes + meta), and ensure _eject_processed is cleared
        foreach (array_keys($orders) as $oid) {
            self::cleanup_single_order($oid, $vendor, $po_no, true);
        }

        // 3) Remove the PO record entirely
        delete_post_meta($po_id,'_eject_notes_written');
        wp_delete_post($po_id, true);

        wp_send_json_success([
            'status' => 'requeued',
            'po_id'  => $po_id,
            'items'  => $requeued_items,
            'orders' => count($orders),
            'vendor' => $vendor ?: '',
        ]);
    }

    /* ---------- local helpers ---------- */

    /** Merge a set of item rows (array) into the run post's _items JSON safely. */
    private static function merge_items_into_run(int $run_id, array $incoming): void {
        $raw   = get_post_meta($run_id, '_items', true);
        $base  = $raw ? json_decode($raw, true) : [];
        if (!is_array($base)) $base = [];

        foreach ($incoming as $rec) {
            $item  = trim((string)($rec['item']  ?? ''));
            $color = (string)($rec['color'] ?? 'N/A'); if ($color === '') $color = 'N/A';
            $size  = (string)($rec['size']  ?? 'N/A'); if ($size  === '') $size  = 'N/A';
            $qty   = max(0, (int)($rec['qty'] ?? 0));
            if ($item === '' || $qty <= 0) continue;

            $key = strtolower($item . '|' . $color . '|' . $size);
            if (empty($base[$key])) {
                $base[$key] = [
                    'item'           => $item,
                    'color'          => $color,
                    'size'           => $size,
                    'qty'            => 0,
                    'order_item_ids' => [],
                    'order_ids'      => [],
                ];
            }

            $base[$key]['qty'] += $qty;

            foreach (['order_item_ids','order_ids'] as $field) {
                $left  = isset($base[$key][$field]) && is_array($base[$key][$field]) ? $base[$key][$field] : [];
                $right = isset($rec[$field]) && is_array($rec[$field]) ? $rec[$field] : [];
                $base[$key][$field] = array_values(array_unique(array_map('intval', array_merge($left, $right))));
            }
        }

        update_post_meta($run_id, '_items', wp_json_encode($base));
    }

    /** Delete our PO-created notes & guard meta for all orders referenced by items. */
    private static function cleanup_orders_for_items(array $items, string $vendor, string $po_no, bool $full): void {
        $order_ids = [];
        foreach ((array)$items as $rec) {
            foreach ((array)($rec['order_ids'] ?? []) as $oid) {
                $oid = (int)$oid; if ($oid > 0) $order_ids[$oid] = true;
            }
        }
        foreach (array_keys($order_ids) as $oid) {
            self::cleanup_single_order($oid, $vendor, $po_no, $full);
        }
    }

    /**
     * Remove Eject artifacts from a single order:
     * - Delete notes that look like "Eject: ... PO ..." or "{Vendor} PO#: {po_no} created" or start with "PO#:".
     * - Remove guard metas & processed flags & run-number linkage for this PO.
     * - If $full is true, clear all _eject_* and _eject_vendor_* metas.
     */
    private static function cleanup_single_order(int $order_id, string $vendor, string $po_no, bool $full): void {
        $o = wc_get_order($order_id); if (!$o) return;

        // Delete our notes (by pattern)
        $comments = get_comments(['post_id'=>$order_id, 'type'=>'order_note', 'status'=>'approve', 'number'=>200]);
        $vendor_label = $vendor !== '' ? $vendor : 'Vendor';
        foreach ($comments as $c) {
            $text = trim((string)$c->comment_content);
            if ($po_no !== '' && preg_match('/^(?:Eject:\s*)?.*PO#?:\s*' . preg_quote($po_no, '/') . '\b/i', $text)) {
                wp_delete_comment($c->comment_ID, true);
                continue;
            }
            if ($po_no !== '' && stripos($text, $vendor_label.' PO#: '.$po_no.' created') === 0) {
                wp_delete_comment($c->comment_ID, true);
                continue;
            }
        }

        // Remove guard metas
        $metas = $o->get_meta_data();
        foreach ($metas as $md) {
            $k = $md->get_data()['key'] ?? '';
            if ($k === '') continue;
            if ($full && (preg_match('/^_eject_/', $k) || preg_match('/^_eject_vendor_/', $k))) {
                $o->delete_meta_data($k);
            } else {
                if (preg_match('/^_eject_vendor_po_note_/', $k) || preg_match('/^_eject_vendor_note_/', $k)) {
                    $o->delete_meta_data($k);
                }
                if ($k === '_eject_processed') {
                    $o->delete_meta_data($k);
                }
                if ($k === '_eject_run_numbers' && $po_no !== '') {
                    $arr = $o->get_meta('_eject_run_numbers');
                    $arr = is_array($arr) ? array_values(array_filter($arr, function($row) use ($po_no){
                        return !is_array($row) || (($row['po_number'] ?? '') !== $po_no);
                    })) : [];
                    $o->update_meta_data('_eject_run_numbers', $arr);
                }
            }
        }
        $o->save();
    }
}
