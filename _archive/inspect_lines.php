<?php
$path = __DIR__ . '/includes/bootstrap.php';
$lines = file($path);
for ($i=1;$i<=count($lines);$i++) {
    if ($i>=110 && $i<=140) {
        echo str_pad($i,4,' ',STR_PAD_LEFT) . ': ' . rtrim($lines[$i-1], "\r\n") . "\n";
    }
}
?>