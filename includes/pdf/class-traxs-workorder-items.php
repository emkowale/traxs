<?php
namespace Traxs;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/items/trait-line-items-table.php';
require_once __DIR__ . '/items/trait-line-items-groups.php';
require_once __DIR__ . '/items/trait-line-items-assets.php';
require_once __DIR__ . '/items/trait-line-items-util.php';
require_once __DIR__ . '/items/trait-line-items-meta.php';

trait WorkOrder_Items {
    use WorkOrder_Items_Table;
    use WorkOrder_Items_Groups;
    use WorkOrder_Items_Assets;
    use WorkOrder_Items_Util;
    use WorkOrder_Items_Meta;
}
