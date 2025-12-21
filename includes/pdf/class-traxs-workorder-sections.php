<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/sections/trait-workorder-header.php';
require_once __DIR__ . '/sections/trait-workorder-footer.php';

trait WorkOrder_Sections {
    use WorkOrder_Header;
    use WorkOrder_Footer;

    protected function renderCustomerSection(): void {
        if (!$this->order) {
            return;
        }
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
        $this->lastSectionBottom = $this->GetY();
    }
}
