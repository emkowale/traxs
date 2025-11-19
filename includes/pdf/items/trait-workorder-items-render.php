<?php
// includes/pdf/items/trait-workorder-items-render.php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_Items_Render {
    protected function renderLineItemsTableHeader(array $w): void {
        $this->SetX(10);
        $this->Cell($w['production'], 7, 'Production', 1, 0, 'C');
        $this->Cell($w['vendor'], 7, 'Vendor Code', 1, 0, 'C');
        $this->Cell($w['color'], 7, 'Color', 1, 0, 'C');
        $this->Cell($w['desc'], 7, 'Description', 1, 0, 'C');
        foreach ($this->getSizeColumns() as $s) {
            $this->Cell($w[$s], 7, $s, 1, 0, 'C');
        }
        $this->Cell($w['items'], 7, 'Items', 1, 0, 'C');
        $this->Ln();
    }

    protected function renderLineItemRow(array $w, array $group, float $lineHeight): void {
        $rowHeight = $this->calculateRowHeight($group['description'], $w['desc'], $lineHeight);
        $startX    = 10;
        $y         = $this->GetY();

        $this->SetXY($startX, $y);
        $this->Cell($w['production'], $rowHeight, $group['production'], 1);
        $this->Cell($w['vendor'], $rowHeight, $group['vendor_code'], 1);
        $this->Cell($w['color'], $rowHeight, $group['color'], 1);

        $descX = $this->GetX();
        $this->SetXY($descX, $y);
        $this->MultiCell($w['desc'], $lineHeight, $group['description'], 1);
        $afterDescX = $descX + $w['desc'];
        $this->SetXY($afterDescX, $y);

        foreach ($this->getSizeColumns() as $size) {
            $value = (int) ($group['sizes'][$size] ?? 0);
            $this->Cell($w[$size], $rowHeight, $value > 0 ? (string) $value : '', 1, 0, 'C');
        }

        $itemsText = $group['items_total'] > 0 ? (string) $group['items_total'] : '';
        $this->Cell($w['items'], $rowHeight, $itemsText, 1, 0, 'C');

        $this->SetY($y + $rowHeight);
    }

    protected function ensureLineItemSpace(float $requiredHeight, array $w): void {
        $limit = 260;
        if ($this->GetY() + $requiredHeight <= $limit) return;

        $this->AddPage();
        $this->renderHeaderSection();
        $this->SetFont('Arial', 'B', 9);
        $this->renderLineItemsTableHeader($w);
        $this->SetFont('Arial', '', 9);
    }

    protected function renderImagesSection(): void {
        $w  = 63.5;
        $x1 = 10;
        $x2 = $x1 + $w + 5;
        $y  = $this->GetY() + 3;
        if ($this->mockup_url) $this->Image($this->mockup_url, $x1, $y, $w);
        if ($this->art_url)    $this->Image($this->art_url, $x2, $y, $w);
    }
}
