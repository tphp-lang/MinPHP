<?php
$a = [];
for ($i = 0; $i < 500000; $i++) { $a[] = $i % 1000; }
$sum = 0;
foreach ($a as $v) { $sum += $v; }
for ($j = 499999; $j >= 0; $j--) { $sum += $a[$j]; }
echo $sum, "\n";
