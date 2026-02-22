<?php
namespace PostalWarmup\Admin;
require_once 'src/Admin/Settings.php';

$s = new Settings();
$defs = (new \ReflectionClass($s))->getProperty('defaults')->getValue($s);

echo "Type of schedule_random_delay_min: " . gettype($defs['schedule_random_delay_min']) . "\n";
echo "Value: " . var_export($defs['schedule_random_delay_min'], true) . "\n";
