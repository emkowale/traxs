<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

// Load FPDF (1.86)
if (!class_exists('\\FPDF')) {
	require_once __DIR__ . '/../lib/fpdf186/fpdf.php';
}

// Include QR utility
require_once __DIR__ . '/class-traxs-workorder-qr.php';

// Include helpers
require_once __DIR__ . '/class-traxs-workorder-sections.php';

use \FPDF;
use WC_Order;

class WorkOrder_PDF extends FPDF {

	use WorkOrder_Sections; // modularized sections

	protected $order;
	protected $vendor_pos = [];
	protected $mockup_url = '';
	protected $art_url    = '';

	/** Output one WooCommerce order → single work order PDF */
	public static function output_for_order(int $order_id, array $vendor_pos = [], string $mockup_url = '', string $art_url = ''): void {
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) wp_die('Invalid order for Work Order PDF.');

		$pdf = new self('P', 'mm', 'Letter');
		$pdf->AliasNbPages();
		$pdf->SetAutoPageBreak(true, 15);

		$pdf->order      = $order;
		$pdf->vendor_pos = $vendor_pos;
		$pdf->mockup_url = $mockup_url;
		$pdf->art_url    = $art_url;

		$pdf->AddPage();
		$pdf->renderHeaderSection();
		$pdf->renderCustomerSection();
		$pdf->Ln(5);
		$pdf->renderLineItemsTable();
		$pdf->Ln(5);
		$pdf->renderImagesSection();

		$filename = 'work-order-' . $order->get_order_number() . '.pdf';
		$pdf->Output('I', $filename);
		exit;
	}

	/** Output multiple WooCommerce orders in one PDF */
	public static function output_for_orders(array $order_ids): void {
		$pdf = new self('P', 'mm', 'Letter');
		$pdf->AliasNbPages();
		$pdf->SetAutoPageBreak(true, 15);

		foreach ($order_ids as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order) continue;

			$pdf->order = $order;
			$vendor_raw = get_post_meta($order_id, '_traxs_vendor_pos', true);
			$pdf->vendor_pos = is_array($vendor_raw)
				? array_values(array_filter(array_map('trim', $vendor_raw)))
				: array_values(array_filter(array_map('trim', explode(',', (string)$vendor_raw))));

			$pdf->mockup_url = (string)get_post_meta($order_id, '_traxs_mockup_url', true);
			$pdf->art_url    = (string)get_post_meta($order_id, '_traxs_art_front_url', true);

			$pdf->AddPage();
			$pdf->renderHeaderSection();
			$pdf->renderCustomerSection();
			$pdf->Ln(5);
			$pdf->renderLineItemsTable();
			$pdf->Ln(5);
			$pdf->renderImagesSection();
		}

		if ($pdf->PageNo() === 0) wp_die('No work orders to print.');

		$filename = 'work-orders-' . date_i18n('Ymd-His') . '.pdf';
		$pdf->Output('I', $filename);
		exit;
	}

	// Empty header - handled in renderHeaderSection()
	public function Header() {}
	public function Footer() { $this->renderFooterSection(); }
}
