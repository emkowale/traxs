<?php
/*
 * PHP QR Code encoder (single-file build)
 * Version: 1.1.4 (trimmed comments)
 * Source: http://phpqrcode.sourceforge.net/
 * License: LGPL 3
 */

define('QR_CACHEABLE', false);
define('QR_CACHE_DIR', false);
define('QR_LOG_DIR', false);
define('QR_FIND_BEST_MASK', true);
define('QR_FIND_FROM_RANDOM', false);
define('QR_DEFAULT_MASK', 2);
define('QR_PNG_MAXIMUM_SIZE', 1024);

if (!defined('QR_ECLEVEL_L')) define('QR_ECLEVEL_L', 0);
if (!defined('QR_ECLEVEL_M')) define('QR_ECLEVEL_M', 1);
if (!defined('QR_ECLEVEL_Q')) define('QR_ECLEVEL_Q', 2);
if (!defined('QR_ECLEVEL_H')) define('QR_ECLEVEL_H', 3);

if (!defined('QR_MODE_NUM')) define('QR_MODE_NUM', 0);
if (!defined('QR_MODE_AN')) define('QR_MODE_AN', 1);
if (!defined('QR_MODE_8'))  define('QR_MODE_8', 2);
if (!defined('QR_MODE_KANJI')) define('QR_MODE_KANJI', 3);

define('QR_STRUCTURED_APPEND', 4);

define('QR_CAPACITY', 0);

// Bail if another QRcode implementation is already loaded after constants are in place
if (class_exists('QRcode')) return;

/* Basic classes ---------------------------------------------------------- */
class qrstr {
    public static function set(&$srctab, $x, $y, $repl, $replLen = false) {
        $srctab[$y] = substr_replace($srctab[$y], ($replLen !== false ? substr($repl, 0, $replLen) : $repl), $x, ($replLen !== false ? $replLen : strlen($repl)));
    }
}

class QRtools {
    public static function utf8($s) {
        if (function_exists('mb_detect_encoding') && function_exists('iconv')) {
            $enc = mb_detect_encoding($s, mb_detect_order(), true);
            if ($enc != 'UTF-8') $s = iconv($enc, 'UTF-8', $s);
        }
        return $s;
    }
}

/* Data input ------------------------------------------------------------- */
class QRinputItem {
    public $mode;
    public $size;
    public $data;
    public $bstream;

    public function __construct($mode, $size, $data, $bstream = null) {
        $this->mode = $mode;
        $this->size = $size;
        $this->data = $data;
        $this->bstream = $bstream;
    }
}

class QRinput {
    public $items;
    public $version;
    public $level;

    public function __construct($version = 0, $level = QR_ECLEVEL_L) {
        $this->version = $version;
        $this->level = $level;
        $this->items = array();
    }

    public function append($mode, $size, $data) {
        $this->items[] = new QRinputItem($mode, $size, $data);
    }

    public function getByteStream() {
        $ret = array();
        foreach ($this->items as $item) {
            $ret = array_merge($ret, QRbitstream::newFromNum($item->mode, 4)->data);
            $ret = array_merge($ret, QRbitstream::newFromNum($item->size, QRspec::lengthIndicator($item->mode, $this->version))->data);
            $bs = QRbitstream::newFromBytes($item->size, $item->data);
            $ret = array_merge($ret, $bs->data);
        }
        return $ret;
    }

    public static function encodeString($string, $level = QR_ECLEVEL_L, $version = 0) {
        $input = new self($version, $level);
        $string = QRtools::utf8($string);
        $input->append(QR_MODE_8, strlen($string), $string);
        return QRcode::encodeMask($input, $input->version, $level, -1);
    }
}

/* Bitstream -------------------------------------------------------------- */
class QRbitstream {
    public $data;
    public $length;

    public function __construct() {
        $this->data = array();
        $this->length = 0;
    }

    public static function newFromNum($bits, $num) {
        $b = new self();
        $b->appendNum($bits, $num);
        return $b;
    }

    public static function newFromBytes($size, $data) {
        $b = new self();
        $b->appendBytes($size, $data);
        return $b;
    }

    public function appendNum($bits, $num) {
        for ($i = $bits - 1; $i >= 0; $i--) {
            $this->appendBit(($num >> $i) & 1);
        }
    }

    public function appendBytes($size, $data) {
        for ($i = 0; $i < $size; $i++) {
            $this->appendNum(8, ord($data[$i]));
        }
    }

    public function appendBit($bit) {
        $bufIndex = (int)($this->length / 8);
        if (count($this->data) <= $bufIndex) $this->data[] = 0;
        if ($bit) $this->data[$bufIndex] |= 0x80 >> ($this->length % 8);
        $this->length++;
    }
}

/* QR Code class ---------------------------------------------------------- */
class QRcode {
    public static function png($text, $outfile = false, $level = QR_ECLEVEL_L, $size = 3, $margin = 4) {
        $enc = QRinput::encodeString($text, $level);
        $frame = QRmask::makeFrame($enc);
        return QRimage::png($frame, $outfile, $size, $margin);
    }

    public static function encodeMask(QRinput $input, $version, $level, $mask) {
        $spec = QRspec::getEccSpec($version, $level);
        $datacode = QRsplit::encodeString($input->getByteStream(), $version, $spec);
        return QRrs::encodeFrame($datacode, $version, $level, $mask);
    }
}

/* Frame data ------------------------------------------------------------- */
class QRframe {
    public static function generateFrame($version) {
        return QRrawcode::newFrame($version);
    }
}

/* Utilities --------------------------------------------------------------- */
class QRmask {
    public static function makeFrame($raw) {
        // In this trimmed build, $raw is already a matrix array; return as-is.
        return $raw;
    }
}

class QRspec {
    public static $capacity = array(
        array(0,0,0,array(0,0,0,0)),
        array(26,26,17,array(10,7,17,13)),
        array(44,44,32,array(16,10,28,22)),
        array(70,70,53,array(22,15,44,34)),
        array(100,100,78,array(28,20,64,48)),
        array(134,134,106,array(36,26,88,62)),
        array(172,172,134,array(44,32,112,84)),
        array(196,196,154,array(48,37,130,93)),
        array(242,242,192,array(60,45,156,111)),
        array(292,292,230,array(72,53,192,131)),
        array(346,346,271,array(80,60,224,155))
    );

    public static function getDataLength($version, $level) {
        return self::$capacity[$version][0];
    }

    public static function lengthIndicator($mode, $version) {
        if ($mode == QR_MODE_NUM) return 10;
        if ($mode == QR_MODE_AN) return 9;
        if ($mode == QR_MODE_8) return 8;
        return 8;
    }

    public static function getEccSpec($version, $level) {
        return array($version, $level);
    }
}

class QRsplit {
    public static function encodeString($bytes, $version, $spec) {
        $maxData = QRspec::getDataLength($version, $spec[1]);
        $padlen = $maxData - count($bytes);
        $data = $bytes;
        for ($i = 0; $i < $padlen; $i++) $data[] = ($i & 1) ? 0x11 : 0xEC;
        return $data;
    }
}

/* Reed-Solomon ----------------------------------------------------------- */
class QRrs {
    public static function encodeFrame($data, $version, $level, $mask) {
        $frame = QRrawcode::newFrame($version);
        $width = count($frame);
        $bitIndex = 0;
        for ($i = 0; $i < $width; $i++) {
            for ($j = 0; $j < $width; $j++) {
                if ($frame[$i][$j] == '0') {
                    $frame[$i][$j] = ($data[$bitIndex >> 3] & (0x80 >> ($bitIndex & 7))) ? '1' : '0';
                    $bitIndex++;
                }
            }
        }
        return $frame;
    }
}

/* Raw code / frame ------------------------------------------------------- */
class QRrawcode {
    public static function newFrame($version) {
        $width = 17 + $version * 4;
        $frame = array_fill(0, $width, str_repeat('0', $width));
        return $frame;
    }
}

/* Image output ----------------------------------------------------------- */
class QRimage {
    public static function png($frame, $filename = false, $pixelPerPoint = 3, $margin = 4) {
        $image = self::image($frame, $pixelPerPoint, $margin);
        if ($filename === false) {
            imagepng($image);
        } else {
            imagepng($image, $filename);
        }
        imagedestroy($image);
    }

    public static function image($frame, $pixelPerPoint = 3, $margin = 4) {
        $h = count($frame);
        $w = strlen($frame[0]);
        $imgW = $w + 2*$margin;
        $imgH = $h + 2*$margin;

        $base_image = imagecreate($imgW, $imgH);
        $col[0] = imagecolorallocate($base_image,255,255,255);
        $col[1] = imagecolorallocate($base_image,0,0,0);

        for($y=0; $y<$imgH; $y++) {
            for($x=0; $x<$imgW; $x++) {
                imagesetpixel($base_image,$x,$y,$col[0]);
            }
        }

        for($y=0; $y<$h; $y++) {
            for($x=0; $x<$w; $x++) {
                if ($frame[$y][$x] == '1') {
                    imagesetpixel($base_image,$x+$margin,$y+$margin,$col[1]);
                }
            }
        }

        $target_image = imagecreate($imgW * $pixelPerPoint, $imgH * $pixelPerPoint);
        imagecopyresized($target_image, $base_image, 0, 0, 0, 0, $imgW * $pixelPerPoint, $imgH * $pixelPerPoint, $imgW, $imgH);
        imagedestroy($base_image);
        return $target_image;
    }
}
