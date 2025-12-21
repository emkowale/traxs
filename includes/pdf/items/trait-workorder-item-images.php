<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;

trait WorkOrder_ItemImages {
    protected function renderImagesSection(): void {
        $this->ensureImagesSectionSpace();
        $this->Ln(5);
        $gap = 6;
        $usableWidth = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $boxWidth = ($usableWidth - $gap) / 2;
        $x1 = $this->lMargin;
        $x2 = $x1 + $boxWidth + $gap;
        $y  = $this->GetY() + 3;

        if ($this->mockup_url) {
            $this->Image($this->mockup_url, $x1, $y, $boxWidth);
        }
        if ($this->art_url) {
            $this->Image($this->art_url, $x2, $y, $boxWidth);
        }
    }

    protected function ensureImagesSectionSpace(): void {
        $required = $this->getImagesSectionHeight();
        $limit = $this->GetPageHeight() - $this->footerHeight - $this->margin_bottom;
        if ($this->GetY() + $required > $limit) {
            $this->AddPage();
            $this->ensureLineItemsTableStartPosition();
        }
    }

    protected function getImagesSectionHeight(): float {
        return 70.0;
    }
}
