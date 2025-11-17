<?php
namespace Traxs;

if (!defined('ABSPATH')) exit;
require_once dirname(__DIR__) . '/../vendor/phpqrcode/qrlib.php';

class QR {
	public static function makeTempPngForOrder(int $order_id, string $ecc='M', int $size=4, int $margin=2): string {
		$tmp = sys_get_temp_dir() . "/qr_order_{$order_id}.png";
		$url = "https://thebeartraxs.com/traxs/#/scan?order={$order_id}";
		\QRcode::png($url, $tmp, constant("QR_ECLEVEL_{$ecc}"), $size, $margin);
		return $tmp;
	}
	public static function cleanup(string $tmp): void {
		if (file_exists($tmp)) @unlink($tmp);
	}
}
