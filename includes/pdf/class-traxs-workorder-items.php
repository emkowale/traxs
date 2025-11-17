<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items {

	protected function renderLineItemsTable(): void {
		$this->SetFont('Arial', 'B', 9);
		$w = ['production'=>20,'vendor'=>20,'color'=>18,'desc'=>60,'S'=>10,'M'=>10,'L'=>10,'XL'=>10,'2XL'=>10,'3XL'=>10,'4XL'=>10];
		$this->renderLineItemsTableHeader($w);
		$this->SetFont('Arial', '', 9);

		foreach ($this->order->get_items('line_item') as $item) {
			if ($this->GetY() > 240) {
				$this->AddPage();
				$this->renderHeaderSection();
				$this->renderLineItemsTableHeader($w);
				$this->SetFont('Arial', '', 9);
			}

			$production  = (string) $item->get_meta('Production', true);
			$vendor_code = (string) ($item->get_meta('Vendor Code', true) ?: $item->get_meta('vendor_code', true));
			$color       = (string) ($item->get_meta('Color', true) ?: $item->get_meta('pa_color', true));
			$desc        = $item->get_name();
			$sizeRaw     = (string) ($item->get_meta('Size', true) ?: $item->get_meta('pa_size', true));
			$sizeKey     = strtoupper(trim($sizeRaw));
			$qty         = (int) $item->get_quantity();

			$cols = ['S'=>'','M'=>'','L'=>'','XL'=>'','2XL'=>'','3XL'=>'','4XL'=>''];
			if (isset($cols[$sizeKey])) $cols[$sizeKey] = (string)$qty;
			else $desc .= " ({$sizeRaw} x {$qty})";

			$this->SetX(10);
			$this->Cell($w['production'],6,$production,1);
			$this->Cell($w['vendor'],6,$vendor_code,1);
			$this->Cell($w['color'],6,$color,1);
			$this->Cell($w['desc'],6,$desc,1);
			foreach (['S','M','L','XL','2XL','3XL','4XL'] as $s)
				$this->Cell($w[$s],6,$cols[$s],1,0,'C');
			$this->Ln();
		}
	}

	protected function renderLineItemsTableHeader(array $w): void {
		$this->SetX(10);
		$this->Cell($w['production'],7,'Production',1,0,'C');
		$this->Cell($w['vendor'],7,'Vendor',1,0,'C');
		$this->Cell($w['color'],7,'Color',1,0,'C');
		$this->Cell($w['desc'],7,'Description',1,0,'C');
		foreach (['S','M','L','XL','2XL','3XL','4XL'] as $s)
			$this->Cell($w[$s],7,$s,1,0,'C');
		$this->Ln();
	}

	protected function renderImagesSection(): void {
		$w = 63.5; $x1 = 10; $x2 = $x1 + $w + 5; $y = $this->GetY() + 3;
		if ($this->mockup_url) $this->Image($this->mockup_url,$x1,$y,$w);
		if ($this->art_url)    $this->Image($this->art_url,$x2,$y,$w);
	}
}
