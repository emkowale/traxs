<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Footer {
    protected function renderFooterSection(): void {
        if (!$this->order) {
            return;
        }
        $this->SetY(-$this->footerHeight);
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
}
