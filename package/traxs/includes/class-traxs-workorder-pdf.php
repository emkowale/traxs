<?php
/*
 * File: includes/class-traxs-workorder-pdf.php
 * Description: Generate Work Order PDFs (FPDF) for Traxs.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-12 EDT
 */

namespace Traxs;

if (!defined('ABSPATH')) exit;

// Ensure FPDF is loaded from /vendor/fpdf/
if (!class_exists('\\FPDF')) {
    require_once dirname(__DIR__) . '/vendor/fpdf/fpdf.php';
}


use \FPDF;
use WC_Order;

class WorkOrder_PDF extends FPDF
{
    /** @var WC_Order */
    protected $order;

    /** @var array List of vendor PO strings for this order */
    protected $vendor_pos = [];

    /** @var string URL to mockup image */
    protected $mockup_url = '';

    /** @var string URL to Original Art {Print Location} image */
    protected $art_url = '';

    /** @var int|null */
    protected $workorder_page_start = null;

    /** @var string */
    protected $workorder_page_placeholder = '';

    /** @var int[] */
    protected $workorder_page_numbers = [];

    /**
     * Entry point: generate and stream a work order PDF for a single WooCommerce order.
     *
     * @param int    $order_id
     * @param array  $vendor_pos Array of PO numbers (strings) linked to this order.
     * @param string $mockup_url URL to mockup image.
     * @param string $art_url    URL to Original Art {Print Location} image.
     */
    public static function output_for_order(int $order_id, array $vendor_pos = [], string $mockup_url = '', string $art_url = ''): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wp_die('Invalid order for Work Order PDF.');
        }

        $pdf = new self('P', 'mm', 'Letter'); // 8.5" x 11"
        $pdf->AliasNbPages();
        $pdf->SetAutoPageBreak(true, 15);

        $pdf->order      = $order;
        $pdf->vendor_pos = $vendor_pos;
        $pdf->mockup_url = $mockup_url;
        $pdf->art_url    = $art_url;

        $pdf->beginWorkOrderPagination($order);
        $pdf->AddPage();
        $pdf->renderHeaderSection();
        $pdf->renderCustomerSection();
        $pdf->Ln(5);
        $pdf->renderLineItemsTable();
        $pdf->Ln(5);
        $pdf->renderImagesSection();
        $pdf->finalizeWorkOrderPagination();

        $filename = 'work-order-' . $order->get_order_number() . '.pdf';
        $pdf->Output('I', $filename);
        exit;
    }

    /**
     * Batch mode: output a multi-page PDF containing all given orders.
     * Each order gets its own page(s) with the same layout as output_for_order().
     *
     * @param int[] $order_ids
     */
    public static function output_for_orders(array $order_ids): void
    {
        $pdf = new self('P', 'mm', 'Letter'); // 8.5" x 11"
        $pdf->AliasNbPages();
        $pdf->SetAutoPageBreak(true, 15);

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                continue;
            }

            $pdf->order = $order;

            // Vendor POs – same logic as REST: _traxs_vendor_pos can be array or CSV.
            $vendor_raw = get_post_meta($order_id, '_traxs_vendor_pos', true);
            $vendor_pos = [];

            if (is_array($vendor_raw)) {
                $vendor_pos = array_values(array_filter(array_map('trim', $vendor_raw)));
            } elseif (is_string($vendor_raw) && $vendor_raw !== '') {
                $vendor_pos = array_values(array_filter(array_map('trim', explode(',', $vendor_raw))));
            }

            $pdf->vendor_pos = $vendor_pos;

            // Mockup + art images
            $pdf->mockup_url = (string) get_post_meta($order_id, '_traxs_mockup_url', true);
            $pdf->art_url    = (string) get_post_meta($order_id, '_traxs_art_front_url', true);

            $pdf->beginWorkOrderPagination($order);
            $pdf->AddPage();
            $pdf->renderHeaderSection();
            $pdf->renderCustomerSection();
            $pdf->Ln(5);
            $pdf->renderLineItemsTable();
            $pdf->Ln(5);
            $pdf->renderImagesSection();
            $pdf->finalizeWorkOrderPagination();
        }

        if ($pdf->PageNo() === 0) {
            wp_die('No work orders to print.');
        }

        $filename = 'work-orders-' . date_i18n('Ymd-His') . '.pdf';
        $pdf->Output('I', $filename);
        exit;
    }

    /* ---------- Header / Footer ---------- */

    // Header content is rendered manually by renderHeaderSection().
    public function Header() {}

    public function Footer()
    {
        if (!$this->order) return;

        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(
            0,
            5,
            sprintf(
                'Work Order #%s    Page %d of %s',
                $this->order->get_order_number(),
                $this->getCurrentWorkOrderPageNumber(),
                $this->getCurrentWorkOrderTotalToken()
            ),
            0,
            0,
            'C'
        );
    }

    /* ---------- Sections ---------- */

    protected function renderHeaderSection(): void
    {
        $order = $this->order;
        $this->SetFont('Arial', 'B', 14);

        $this->SetXY(10, 10);
        $this->Cell(0, 7, 'WORK ORDER', 0, 1, 'L');

        $this->SetFont('Arial', '', 10);
        $this->SetX(10);
        $this->Cell(0, 5, get_bloginfo('name'), 0, 1, 'L');

        $this->SetX(10);
        $this->Cell(0, 5, 'Work Order #: ' . $order->get_order_number(), 0, 1, 'L');

        $this->SetX(10);
        $this->Cell(
            0,
            5,
            'Order Date: ' . $order->get_date_created()->date_i18n(get_option('date_format')),
            0,
            1,
            'L'
        );

        // Vendor POs line
        if (!empty($this->vendor_pos)) {
            $this->SetX(10);
            $this->Cell(0, 5, 'Vendor POs: ' . implode(', ', $this->vendor_pos), 0, 1, 'L');
        }

        // QR code in top-right (camera / Traxs app can use this URL)
        $this->renderQrCode();
        $this->Ln(3);

        // Divider
        $this->SetDrawColor(0, 0, 0);
        $this->Line(10, $this->GetY(), 206, $this->GetY());
        $this->Ln(3);
    }

    protected function renderCustomerSection(): void
    {
        $order = $this->order;

        $billing_name   = trim($order->get_formatted_billing_full_name());
        $shipping_name  = trim($order->get_formatted_shipping_full_name());
        $phone          = $order->get_billing_phone();
        $email          = $order->get_billing_email();
        $billing_addr   = $order->get_formatted_billing_address();
        $shipping_addr  = $order->get_formatted_shipping_address();

        // Two boxes side by side: Billing / Shipping
        $startY = $this->GetY();
        $boxW   = 95;

        // Billing
        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(10, $startY);
        $this->Cell($boxW, 6, 'Billing', 1, 2, 'L');
        $this->SetFont('Arial', '', 9);
        $this->MultiCell($boxW, 4, $billing_name . "\n" . $billing_addr, 1);

        // Shipping
        $this->SetFont('Arial', 'B', 10);
        $this->SetXY(10 + $boxW + 1, $startY);
        $this->Cell($boxW, 6, 'Shipping', 1, 2, 'L');
        $this->SetFont('Arial', '', 9);
        $this->MultiCell($boxW, 4, $shipping_name . "\n" . $shipping_addr, 1);

        // Contact line under both
        $this->Ln(2);
        $this->SetX(10);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, 'Phone: ' . $phone . '    Email: ' . $email, 0, 1, 'L');

        // Divider
        $this->Line(10, $this->GetY(), 206, $this->GetY());
    }

    protected function renderLineItemsTable(): void
    {
        $this->SetFont('Arial', 'B', 9);

        // Column widths (total ~188mm inside 10mm margins)
        $w = [
            'production' => 20,
            'vendor'     => 20,
            'color'      => 18,
            'desc'       => 60,
            'S'          => 10,
            'M'          => 10,
            'L'          => 10,
            'XL'         => 10,
            '2XL'        => 10,
            '3XL'        => 10,
            '4XL'        => 10,
        ];

        // Header row
        $this->renderLineItemsTableHeader($w);

        $this->SetFont('Arial', '', 9);
        $items = $this->order->get_items('line_item');

        foreach ($items as $item) {
            // Basic pagination for multi-page orders
            if ($this->GetY() > 240) {
                $this->AddPage();
                $this->renderHeaderSection();
                $this->Ln(3);
                $this->renderLineItemsTableHeader($w);
                $this->SetFont('Arial', '', 9);
            }

            $production  = (string) $item->get_meta('Production', true);
            $vendor_code = (string) ($item->get_meta('Vendor Code', true) ?: $item->get_meta('vendor_code', true));
            $color       = (string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true));
            $desc        = $item->get_name();

            $sizeRaw = (string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true));
            $sizeKey = strtoupper(trim($sizeRaw));
            $qty     = (int) $item->get_quantity();

            $sizeCols = ['S'=>'', 'M'=>'', 'L'=>'', 'XL'=>'', '2XL'=>'', '3XL'=>'', '4XL'=>''];
            if (isset($sizeCols[$sizeKey])) {
                $sizeCols[$sizeKey] = (string) $qty;
            } else {
                // Fallback: dump qty/size detail into description if size column is unknown
                $desc .= ' (' . $sizeRaw . ' x ' . $qty . ')';
            }

            $this->SetX(10);
            $this->Cell($w['production'], 6, $production, 1);
            $this->Cell($w['vendor'],     6, $vendor_code, 1);
            $this->Cell($w['color'],      6, $color, 1);
            $this->Cell($w['desc'],       6, $desc, 1);
            foreach (['S','M','L','XL','2XL','3XL','4XL'] as $size) {
                $this->Cell($w[$size], 6, $sizeCols[$size], 1, 0, 'C');
            }
            $this->Ln();
        }
    }

    /**
     * Helper to draw the table header (used on first page + subsequent pages).
     *
     * @param array $w Column widths.
     */
    protected function renderLineItemsTableHeader(array $w): void
    {
        $this->SetX(10);
        $this->Cell($w['production'], 7, 'Production', 1, 0, 'C');
        $this->Cell($w['vendor'],     7, 'Vendor Code', 1, 0, 'C');
        $this->Cell($w['color'],      7, 'Color', 1, 0, 'C');
        $this->Cell($w['desc'],       7, 'Description', 1, 0, 'C');
        foreach (['S','M','L','XL','2XL','3XL','4XL'] as $size) {
            $this->Cell($w[$size], 7, $size, 1, 0, 'C');
        }
        $this->Ln();
    }

    protected function renderImagesSection(): void
    {
        // Images 2.5" wide ≈ 63.5 mm
        $imgWidth = 63.5;
        $x1 = 10;
        $x2 = $x1 + $imgWidth + 5;
        $y  = $this->GetY() + 3;

        if ($this->mockup_url) {
            $this->Image($this->mockup_url, $x1, $y, $imgWidth);
        }

        if ($this->art_url) {
            $this->Image($this->art_url, $x2, $y, $imgWidth);
        }
    }

    /**
     * Render a scannable QR code in the top-right corner using local phpqrcode.
     * Creates a temp PNG, embeds it, then deletes it immediately.
     */
    protected function renderQrCode(): void
    {
        if (!$this->order) return;

        $order_id = $this->order->get_id();

        try {
            // Generate temp PNG (uploads/traxs_qr_tmp or system temp)
            $tmp = QR::makeTempPngForOrder($order_id, 'M', 4, 2);
        } catch (\Throwable $e) {
            // Silent fail: keep PDF generation robust
            return;
        }

        // Place in top-right, inside 10mm margins
        $size      = 25;        // mm (~1")
        $pageRight = 216 - 10;  // Letter width (~216mm) - right margin 10mm
        $x         = $pageRight - $size;
        $y         = 10;

        // Embed then delete temp file (FPDF has already read it into the PDF)
        $this->Image($tmp, $x, $y, $size, $size);
        QR::cleanup($tmp);
    }

    protected function beginWorkOrderPagination(WC_Order $order): void
    {
        $this->workorder_page_placeholder = sprintf('__WO_NB_%d__', $order->get_id());
        $this->workorder_page_start = null;
        $this->workorder_page_numbers = [];
    }

    protected function finalizeWorkOrderPagination(): void
    {
        if (empty($this->workorder_page_numbers) || !$this->workorder_page_placeholder) {
            $this->workorder_page_placeholder = '';
            $this->workorder_page_start = null;
            $this->workorder_page_numbers = [];
            return;
        }

        $total = max(1, count($this->workorder_page_numbers));
        $replacement = (string) $total;
        foreach ($this->workorder_page_numbers as $page) {
            if (isset($this->pages[$page])) {
                $this->pages[$page] = str_replace(
                    $this->workorder_page_placeholder,
                    $replacement,
                    $this->pages[$page]
                );
            }
        }

        $this->workorder_page_placeholder = '';
        $this->workorder_page_start = null;
        $this->workorder_page_numbers = [];
    }

    public function AddPage($orientation = '', $size = '', $rotation = 0)
    {
        parent::AddPage($orientation, $size, $rotation);
        if (!$this->workorder_page_placeholder) {
            return;
        }

        $page = $this->PageNo();
        if ($this->workorder_page_start === null) {
            $this->workorder_page_start = $page;
        }

        $this->workorder_page_numbers[] = $page;
    }

    protected function getCurrentWorkOrderPageNumber(): int
    {
        if ($this->workorder_page_start === null) {
            return max(1, $this->PageNo());
        }

        return max(1, $this->PageNo() - $this->workorder_page_start + 1);
    }

    protected function getCurrentWorkOrderTotalToken(): string
    {
        return $this->workorder_page_placeholder ?: '1';
    }
}
