<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

use WC_Order;

trait WorkOrder_Sections {

        /** @var int|null */
        protected $workorder_page_start = null;

        /** @var string */
        protected $workorder_page_placeholder = '';

        /** @var array<int,int> */
        protected $workorder_page_numbers = [];

        /** @var int|null */
        protected $workorder_pagination_order_id = null;

        /**
         * Ensure pagination bookkeeping is ready for the active order before adding a page.
         */
        protected function ensureWorkOrderPagination(): void {
                if (!$this->order instanceof WC_Order) {
                        if ($this->workorder_page_placeholder) {
                                $this->finalizeWorkOrderPagination();
                        }
                        return;
                }

                $order_id = $this->order->get_id();

                if ($this->workorder_page_placeholder && $this->workorder_pagination_order_id === $order_id) {
                        return;
                }

                if ($this->workorder_page_placeholder && $this->workorder_pagination_order_id !== $order_id) {
                        $this->finalizeWorkOrderPagination();
                }

                $this->workorder_pagination_order_id = $order_id;
                $this->workorder_page_placeholder   = sprintf('__WO_NB_%d__', $order_id);
                $this->workorder_page_start         = null;
                $this->workorder_page_numbers       = [];
        }

        /**
         * Replace the placeholder token for the current order with its total page count.
         */
        protected function finalizeWorkOrderPagination(): void {
                if (empty($this->workorder_page_numbers) || !$this->workorder_page_placeholder) {
                        $this->resetWorkOrderPaginationState();
                        return;
                }

                $total_pages = max(1, count($this->workorder_page_numbers));
                $replacement = (string) $total_pages;

                foreach ($this->workorder_page_numbers as $page_no) {
                        if (isset($this->pages[$page_no])) {
                                $this->pages[$page_no] = str_replace(
                                        $this->workorder_page_placeholder,
                                        $replacement,
                                        $this->pages[$page_no]
                                );
                        }
                }

                $this->resetWorkOrderPaginationState();
        }

        protected function resetWorkOrderPaginationState(): void {
                $this->workorder_page_placeholder   = '';
                $this->workorder_page_start         = null;
                $this->workorder_page_numbers       = [];
                $this->workorder_pagination_order_id = null;
        }

        public function AddPage($orientation = '', $size = '', $rotation = 0) {
                if ($this->order instanceof WC_Order) {
                        $this->ensureWorkOrderPagination();
                } elseif ($this->workorder_page_placeholder) {
                        $this->finalizeWorkOrderPagination();
                }

                parent::AddPage($orientation, $size, $rotation);

                if (!$this->workorder_page_placeholder) return;

                $page_no = $this->PageNo();
                if ($this->workorder_page_start === null) {
                        $this->workorder_page_start = $page_no;
                }

                $this->workorder_page_numbers[] = $page_no;
        }

        public function Output($dest = '', $name = '', $isUTF8 = false) {
                if ($this->workorder_page_placeholder) {
                        $this->finalizeWorkOrderPagination();
                }

                return parent::Output($dest, $name, $isUTF8);
        }

        protected function getCurrentWorkOrderPageNumber(): int {
                if ($this->workorder_page_start === null) {
                        return max(1, $this->PageNo());
                }

                return max(1, $this->PageNo() - $this->workorder_page_start + 1);
        }

        protected function getCurrentWorkOrderTotalToken(): string {
                return $this->workorder_page_placeholder ?: '1';
        }

        protected function renderHeaderSection(): void {
                if (!$this->order instanceof WC_Order) {
                        return;
                }

                $order = $this->order;
                $this->SetFont('Arial', 'B', 14);
                $this->SetXY(10, 10);
                $this->Cell(0, 7, 'WORK ORDER', 0, 1, 'L');

                $logoUrl       = 'https://thebeartraxs.com/wp-content/uploads/2025/05/The-Bear-Traxs-Logo.png';
                $logoSizeMm    = 25.4;
                $logoTopMargin = 8;
                $logoX         = ($this->GetPageWidth() - $logoSizeMm) / 2;
                $this->Image($logoUrl, $logoX, $logoTopMargin, $logoSizeMm, $logoSizeMm);

                $this->SetFont('Arial', '', 10);
                $this->SetX(10);
                $this->Cell(0, 5, get_bloginfo('name'), 0, 1, 'L');
                $this->Cell(0, 5, 'Work Order #: ' . $order->get_order_number(), 0, 1, 'L');
                $this->Cell(0, 5, 'Order Date: ' . $order->get_date_created()->date_i18n(get_option('date_format')), 0, 1, 'L');

                if (!empty($this->vendor_pos)) {
                        $this->Cell(0, 5, 'Vendor POs: ' . implode(', ', $this->vendor_pos), 0, 1, 'L');
                }

                $this->renderQrCode();
                $this->Ln(3);
                $this->Ln(3);
        }

        protected function renderCustomerSection(): void {
                if (!$this->order instanceof WC_Order) {
                        return;
                }

                $order    = $this->order;
                $billing  = str_replace('<br/>', "\n", $order->get_formatted_billing_address());
                $shipping = str_replace('<br/>', "\n", $order->get_formatted_shipping_address());
                $phone    = $order->get_billing_phone();
                $email    = $order->get_billing_email();

                $startY = $this->GetY();
                $boxW   = 95;

                $this->SetFont('Arial', 'B', 10);
                $this->SetXY(10, $startY);
                $this->Cell($boxW, 6, 'Billing', 1, 2, 'L');
                $this->SetFont('Arial', '', 9);
                $this->MultiCell($boxW, 4, $billing, 1);

                $this->SetFont('Arial', 'B', 10);
                $this->SetXY(10 + $boxW + 1, $startY);
                $this->Cell($boxW, 6, 'Shipping', 1, 2, 'L');
                $this->SetFont('Arial', '', 9);
                $this->MultiCell($boxW, 4, $shipping, 1);

                $this->Ln(2);
                $this->SetX(10);
                $this->Cell(0, 5, 'Phone: ' . $phone . '    Email: ' . $email, 0, 1, 'L');
                $this->Line(10, $this->GetY(), 206, $this->GetY());
        }

        protected function renderFooterSection(): void {
                if (!$this->order instanceof WC_Order) {
                        return;
                }

                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $page_number = $this->getCurrentWorkOrderPageNumber();
                $total_token = $this->getCurrentWorkOrderTotalToken();
                $this->Cell(
                        0,
                        5,
                        sprintf('Work Order #%s    Page %d of %s', $this->order->get_order_number(), $page_number, $total_token),
                        0,
                        0,
                        'C'
                );
        }
}
