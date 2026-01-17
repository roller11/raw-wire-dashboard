<?php
if (!defined('ABSPATH')) define('ABSPATH','/var/www/html/');
require_once ABSPATH . 'wp-load.php';

$time = get_option('rawwire_last_batch_time');
$ids = get_option('rawwire_last_batch_ids');

echo "rawwire_last_batch_time: "; var_export($time); echo "\n";
echo "rawwire_last_batch_ids: "; var_export($ids); echo "\n";

return 0;
