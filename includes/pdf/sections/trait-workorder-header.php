<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Header {
    protected function renderHeaderSection(): void {
        if (!$this->order) {
            return;
        }
        $order = $this->order;
        $left = $this->lMargin;
        $top  = $this->tMargin;
        $this->SetFont('Arial', 'B', 14);
        $this->SetXY($left, $top);
        $this->Cell(0, 7, 'WORK ORDER', 0, 1, 'L');

        $logoUrl       = 'https://thebeartraxs.com/wp-content/uploads/2025/05/The-Bear-Traxs-Logo.png';
        $logoSizeMm    = 25.4;
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

        $this->renderQrCode();
        $this->Ln(3);
        $this->Ln(3);
        $this->lastSectionBottom = $this->GetY();
    }
}
