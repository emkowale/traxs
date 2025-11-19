<?php
/**
 * Traxs REST Endpoint: Receive Complete
 * Marks PO as received, removes it from the Receive list,
 * and adds it to the Print Work Orders queue.
 */

add_action('rest_api_init', function() {
    register_rest_route('traxs/v1', '/receive-complete', [
        'methods'             => 'POST',
        'callback'            => 'traxs_receive_complete',
        'permission_callback' => '__return_true', // Replace with real auth if needed
    ]);
});

/**
 * Callback: POST /wp-json/traxs/v1/receive-complete
 */
function traxs_receive_complete(WP_REST_Request $request) {
    global $wpdb;

    $po_id = sanitize_text_field($request->get_param('po_id'));
    if (empty($po_id)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing po_id.'
        ], 400);
    }

    // --- 1️⃣ Verify PO exists ---
    $po = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}traxs_po WHERE po_id = %s", $po_id)
    );

    if (!$po) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'PO not found.'
        ], 404);
    }

    // --- 2️⃣ Mark PO as fully received ---
    $updated = $wpdb->update(
        "{$wpdb->prefix}traxs_po",
        [
            'status'        => 'received',
            'received_date' => current_time('mysql')
        ],
        ['po_id' => $po_id]
    );

    // --- 3️⃣ Remove from Receive Goods list ---
    // Assuming there’s a column or table tracking pending receives
    $wpdb->update(
        "{$wpdb->prefix}traxs_po",
        ['pending_receive' => 0],
        ['po_id' => $po_id]
    );

    // --- 4️⃣ Add to Print Work Orders list ---
    // This assumes you have a table named wp_traxs_print_queue
    $wpdb->insert(
        "{$wpdb->prefix}traxs_print_queue",
        [
            'po_id'     => $po_id,
            'queued_at' => current_time('mysql')
        ]
    );

    // --- 5️⃣ Respond to client ---
    if ($updated !== false) {
        return rest_ensure_response([
            'success' => true,
            'po_id'   => $po_id,
            'message' => 'PO marked as received and moved to print queue.'
        ]);
    }

    return new WP_REST_Response([
        'success' => false,
        'message' => 'Failed to update PO status.'
    ], 500);
}
