<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Sections {

	protected function renderHeaderSection(): void {
		$order = $this->order;
		$left = $this->lMargin;
		$top  = $this->tMargin;
		$this->SetFont('Arial', 'B', 14);
		$this->SetXY($left, $top);
		$this->Cell(0, 7, 'WORK ORDER', 0, 1, 'L');

        $logoUrl       = 'https://thebeartraxs.com/wp-content/uploads/2025/05/The-Bear-Traxs-Logo.png';
        $logoSizeMm    = 25.4; // 1 inch × 1 inch
        $logoTopMargin = max($top - 2, 0);
        $logoX         = ($this->GetPageWidth() - $logoSizeMm) / 2;
        $this->Image($logoUrl, $logoX, $logoTopMargin, $logoSizeMm, $logoSizeMm);

		$this->SetFont('Arial', '', 10);
		$this->SetX($left);
		$this->Cell(0, 5, get_bloginfo('name'), 0, 1, 'L');
		$this->Cell(0, 5, 'Work Order #: ' . $order->get_order_number(), 0, 1, 'L');
		$this->Cell(0, 5, 'Order Date: ' . $order->get_date_created()->date_i18n(get_option('date_format')), 0, 1, 'L');

		if (!empty($this->vendor_pos)) {
			$this->Cell(0, 5, 'Vendor POs: ' . implode(', ', $this->vendor_pos), 0, 1, 'L');
		}

		// QR in top-right
		$this->renderQrCode();
		$this->Ln(3);
		//$this->Line($left, $this->GetY(), $this->GetPageWidth() - $this->rMargin, $this->GetY());
		$this->Ln(3);
	}

	protected function renderCustomerSection(): void {
		$order = $this->order;
		$billing = trim($order->get_formatted_billing_full_name());
		$shipping = trim($order->get_formatted_shipping_full_name());
		$phone = $order->get_billing_phone();
		$email = $order->get_billing_email();

		$startY = $this->GetY();
		$boxW = 101;
		$left = $this->lMargin;
		$right = $this->GetPageWidth() - $this->rMargin;

		$this->SetFont('Arial', 'B', 10);
		$this->SetXY($left, $startY);
		$this->Cell($boxW, 6, 'Billing', 1, 2, 'L');
		$this->SetFont('Arial', '', 9);
		$this->MultiCell($boxW, 4, str_replace("<br/>","\n",$order->get_formatted_billing_address()), 1);

		$this->SetFont('Arial', 'B', 10);
		$this->SetXY($left + $boxW + 1, $startY);
		$this->Cell($boxW, 6, 'Shipping', 1, 2, 'L');
		$this->SetFont('Arial', '', 9);
		$this->MultiCell($boxW, 4, str_replace("<br/>","\n",$order->get_formatted_shipping_address()), 1);

		$this->Ln(2);
		$this->SetX($left);
		$this->Cell(0, 5, 'Phone: ' . $phone . '    Email: ' . $email, 0, 1, 'L');
		$this->Line($left, $this->GetY(), $right, $this->GetY());
	}

	protected function renderFooterSection(): void {
		if (!$this->order) return;
		$this->SetY(-15);
		$this->SetFont('Arial', 'I', 8);
		$this->Cell(0, 5, sprintf('Work Order #%s    Page %d of {nb}', $this->order->get_order_number(), $this->PageNo()), 0, 0, 'C');
	}
}
