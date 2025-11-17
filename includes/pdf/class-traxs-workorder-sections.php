<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Sections {

	protected function renderHeaderSection(): void {
		$order = $this->order;
		$this->SetFont('Arial', 'B', 14);
		$this->SetXY(10, 10);
		$this->Cell(0, 7, 'WORK ORDER', 0, 1, 'L');

		$this->SetFont('Arial', '', 10);
		$this->SetX(10);
		$this->Cell(0, 5, get_bloginfo('name'), 0, 1, 'L');
		$this->Cell(0, 5, 'Work Order #: ' . $order->get_order_number(), 0, 1, 'L');
		$this->Cell(0, 5, 'Order Date: ' . $order->get_date_created()->date_i18n(get_option('date_format')), 0, 1, 'L');

		if (!empty($this->vendor_pos)) {
			$this->Cell(0, 5, 'Vendor POs: ' . implode(', ', $this->vendor_pos), 0, 1, 'L');
		}

		// QR in top-right
		$this->renderQrCode();
		$this->Ln(3);
		$this->Line(10, $this->GetY(), 206, $this->GetY());
		$this->Ln(3);
	}

	protected function renderCustomerSection(): void {
		$order = $this->order;
		$billing = trim($order->get_formatted_billing_full_name());
		$shipping = trim($order->get_formatted_shipping_full_name());
		$phone = $order->get_billing_phone();
		$email = $order->get_billing_email();

		$startY = $this->GetY();
		$boxW = 95;

		$this->SetFont('Arial', 'B', 10);
		$this->SetXY(10, $startY);
		$this->Cell($boxW, 6, 'Billing', 1, 2, 'L');
		$this->SetFont('Arial', '', 9);
		$this->MultiCell($boxW, 4, $billing . "\n" . $order->get_formatted_billing_address(), 1);

		$this->SetFont('Arial', 'B', 10);
		$this->SetXY(10 + $boxW + 1, $startY);
		$this->Cell($boxW, 6, 'Shipping', 1, 2, 'L');
		$this->SetFont('Arial', '', 9);
		$this->MultiCell($boxW, 4, $shipping . "\n" . $order->get_formatted_shipping_address(), 1);

		$this->Ln(2);
		$this->SetX(10);
		$this->Cell(0, 5, 'Phone: ' . $phone . '    Email: ' . $email, 0, 1, 'L');
		$this->Line(10, $this->GetY(), 206, $this->GetY());
	}

	protected function renderFooterSection(): void {
		if (!$this->order) return;
		$this->SetY(-15);
		$this->SetFont('Arial', 'I', 8);
		$this->Cell(0, 5, sprintf('Work Order #%s    Page %d of {nb}', $this->order->get_order_number(), $this->PageNo()), 0, 0, 'C');
	}
}
