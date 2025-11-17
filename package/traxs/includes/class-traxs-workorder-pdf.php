<?php
/*
 * File: includes/class-traxs-workorder-pdf.php
 * Description: Main entry for Traxs Work Order PDF generation (modular FPDF + QR)
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-17
 */

namespace Traxs;

if (!defined('ABSPATH')) exit;

// -----------------------------------------------------------------------------
// Dependencies (FPDF + QR + modular sections)
// -----------------------------------------------------------------------------

// ✅ Correct FPDF path (vendor/fpdf/fpdf.php)
if (!class_exists('\\FPDF')) {
	require_once dirname(__DIR__) . '/vendor/fpdf/fpdf.php';
}

// Load internal phpqrcode (vendor or local copy)
require_once __DIR__ . '/../vendor/phpqrcode/qrlib.php';

// Load modularized traits and helpers
require_once __DIR__ . '/pdf/class-traxs-workorder-sections.php';
require_once __DIR__ . '/pdf/class-traxs-workorder-items.php';
require_once __DIR__ . '/pdf/class-traxs-workorder-qr.php';

use \FPDF;
use WC_Order;

/**
 * Core Traxs WorkOrder_PDF class
 * Combines header/footer, line items, images, and QR generation.
 */
class WorkOrder_PDF extends FPDF {

	use WorkOrder_Sections;
	use WorkOrder_Items;

	/** @var WC_Order */
	protected $order;

	/** @var array Vendor PO numbers */
	protected $vendor_pos = [];

	/** @var string URL to mockup image */
	protected $mockup_url = '';

	/** @var string URL to original art image */
	protected $art_url = '';

	// -------------------------------------------------------------------------
	//  Entrypoint: Single Order
	// -------------------------------------------------------------------------
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

	// -------------------------------------------------------------------------
	//  Entrypoint: Multiple Orders
	// -------------------------------------------------------------------------
	public static function output_for_orders(array $order_ids): void {
		$pdf = new self('P', 'mm', 'Letter');
		$pdf->AliasNbPages();
		$pdf->SetAutoPageBreak(true, 15);

		foreach ($order_ids as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order) continue;

			$pdf->order = $order;

			// Vendor PO logic: support array or CSV string meta
			$vendor_raw = get_post_meta($order_id, '_traxs_vendor_pos', true);
			if (is_array($vendor_raw)) {
				$pdf->vendor_pos = array_values(array_filter(array_map('trim', $vendor_raw)));
			} elseif (is_string($vendor_raw) && $vendor_raw !== '') {
				$pdf->vendor_pos = array_values(array_filter(array_map('trim', explode(',', $vendor_raw))));
			} else {
				$pdf->vendor_pos = [];
			}

			$pdf->mockup_url = (string) get_post_meta($order_id, '_traxs_mockup_url', true);
			$pdf->art_url    = (string) get_post_meta($order_id, '_traxs_art_front_url', true);

			$pdf->AddPage();
			$pdf->renderHeaderSection();
			$pdf->renderCustomerSection();
			$pdf->Ln(5);
			$pdf->renderLineItemsTable();
			$pdf->Ln(5);
			$pdf->renderImagesSection();
		}

		if ($pdf->PageNo() === 0) {
			wp_die('No work orders to print.');
		}

		$filename = 'work-orders-' . date_i18n('Ymd-His') . '.pdf';
		$pdf->Output('I', $filename);
		exit;
	}

	// -------------------------------------------------------------------------
	//  Header & Footer Hooks
	// -------------------------------------------------------------------------
	public function Header() {
		// Empty — header drawn manually in renderHeaderSection()
	}

	public function Footer() {
		if (!$this->order) return;
		$this->SetY(-15);
		$this->SetFont('Arial', 'I', 8);
		$this->Cell(
			0,
			5,
			sprintf('Work Order #%s    Page %d of {nb}', $this->order->get_order_number(), $this->PageNo()),
			0,
			0,
			'C'
		);
	}

	// -------------------------------------------------------------------------
	//  QR Code Renderer (upper right corner)
	// -------------------------------------------------------------------------
	protected function renderQrCode(): void {
		if (!$this->order) return;
		$order_id = $this->order->get_id();

		try {
			$tmp = QR::makeTempPngForOrder($order_id, 'M', 4, 2);
			$size = 25;
			$pageRight = 216 - 10;
			$x = $pageRight - $size;
			$y = 10;
			$this->Image($tmp, $x, $y, $size, $size);
			QR::cleanup($tmp);
		}
		catch (\Throwable $e) {
			// Silent fail, keep PDF valid
		}
	}
}
