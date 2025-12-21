<?php
/*
 * File: includes/ajax/traits/trait-eject-ajax-run-status.php
 * Description: Publish/unpublish (mark ordered / not ordered). No per-line notes.
 * Plugin: Eject
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-05 EDT
 */
if (!defined('ABSPATH')) exit;

trait Eject_Ajax_Run_Status {

    /** Publish the run as a PO and let CPT hook add ONE vendor note per order */
    public static function mark_ordered() {
        Eject_Ajax::guard();

        $po_id  = intval($_POST['po_id'] ?? 0);
        $vendor = sanitize_text_field($_POST['vendor'] ?? '');

        if (!$po_id && $vendor) $po_id = Eject_Data::get_or_create_run($vendor);
        if (!$po_id) wp_send_json_error(['message'=>'Missing run'], 400);

        $vendor = get_post_meta($po_id,'_vendor_name',true) ?: $vendor;
        $po_no  = Eject_Data::next_po_number($vendor);

        update_post_meta($po_id,'_po_number',$po_no);
        update_post_meta($po_id,'_po_date', date_i18n('Y-m-d'));

        // Publish (Eject_CPT::on_transition_status will add ONE note per vendor per order)
        wp_update_post(['ID'=>$po_id,'post_status'=>'publish','post_title'=>$po_no]);

        // Link orders to this PO & mark processed — but DO NOT add notes here.
        $items   = json_decode(get_post_meta($po_id,'_items',true) ?: '[]', true);
        $touched = [];

        foreach ((array)$items as $line) {
            $oids = isset($line['order_ids']) ? (array)$line['order_ids'] : [];
            foreach ($oids as $oid) { if (!in_array($oid, $touched, true)) $touched[] = (int)$oid; }
        }

        foreach ($touched as $oid) {
            $o = wc_get_order($oid); if (!$o) continue;
            $arr = $o->get_meta('_eject_run_numbers'); $arr = $arr ? (array)$arr : [];
            $arr[] = ['vendor'=>$vendor,'po_number'=>$po_no,'eject_run_id'=>$po_id];
            $o->update_meta_data('_eject_run_numbers', $arr);
            $o->update_meta_data('_eject_processed', 1);
            $o->save();
        }

        wp_send_json_success(['message'=>'Marked Ordered','po_number'=>$po_no,'po_id'=>$po_id]);
    }

    /** Unpublish back to draft (Run) */
    public static function mark_not_ordered() {
        Eject_Ajax::guard();
        $po_id = intval($_POST['po_id'] ?? 0);
        if (!$po_id) wp_send_json_error(['message'=>'Missing run'], 400);

        $vendor = get_post_meta($po_id,'_vendor_name',true);
        wp_update_post([
            'ID'          => $po_id,
            'post_status' => 'draft',
            'post_title'  => ($vendor ? $vendor : 'Vendor') . ' (Run)'
        ]);

        wp_send_json_success(['message'=>'Run set to Not Ordered','po_id'=>$po_id]);
    }
}
