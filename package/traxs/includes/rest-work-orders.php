<?php
/**
 * Traxs REST: Generate PDF for uncompleted (ready-to-print) work orders
 */

add_action('rest_api_init', function() {
    register_rest_route('traxs/v1', '/work-orders/pdf', [
        'methods'  => 'GET',
        'callback' => 'traxs_generate_ready_to_print_work_orders_pdf',
        'permission_callback' => '__return_true',
    ]);
});

function traxs_generate_ready_to_print_work_orders_pdf(WP_REST_Request $req) {
    global $wpdb;

    $receipts_table = $wpdb->prefix . 'traxs_receipts';
    $work_orders_table = $wpdb->prefix . 'traxs_work_orders';

    // ✅ Find all received POs that are NOT yet in work_orders
    $sql = "
        SELECT r.po_number,
               COUNT(*) AS line_count,
               SUM(r.received_qty) AS total_received,
               MAX(r.created_at) AS last_received
        FROM {$receipts_table} r
        LEFT JOIN {$work_orders_table} w
               ON w.wo_number = r.po_number
        WHERE (r.status IN ('ok', 'received') OR r.status IS NULL)
          AND w.wo_number IS NULL
        GROUP BY r.po_number
        ORDER BY last_received DESC
        LIMIT 50
    ";
    $orders = $wpdb->get_results($sql);

    if (empty($orders)) {
        return new WP_REST_Response(['error' => 'No uncompleted work orders found'], 404);
    }

    // ✅ Load bundled FPDF
    $fpdf_path = plugin_dir_path(__FILE__) . '../vendor/fpdf/fpdf.php';
    if (!file_exists($fpdf_path)) {
        return new WP_REST_Response(['error' => 'FPDF library missing'], 500);
    }
    require_once $fpdf_path;

    $pdf = new FPDF();
    $pdf->SetTitle('Traxs Ready-to-Print Work Orders');
    $pdf->SetAutoPageBreak(true, 15);

    foreach ($orders as $order) {
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Work Order: ' . $order->po_number, 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, 'Lines Received: ' . $order->line_count, 0, 1);
        $pdf->Cell(0, 8, 'Total Qty Received: ' . $order->total_received, 0, 1);
        $pdf->Cell(0, 8, 'Last Activity: ' . $order->last_received, 0, 1);
        $pdf->Ln(8);

        $pdf->MultiCell(0, 6, "Status: Ready for print\n\nThis work order has been received and is awaiting production printout.");
    }

    // ✅ Output PDF inline
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Traxs-ReadyToPrint-WorkOrders.pdf"');
    $pdf->Output('I', 'Traxs-ReadyToPrint-WorkOrders.pdf');
    exit;
}
