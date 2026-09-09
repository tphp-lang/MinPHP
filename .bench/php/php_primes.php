<?php
function isPrime(int $n): bool {
    if ($n < 2) { return false; }
    $i = 2;
    while ($i * $i <= $n) { if ($n % $i == 0) { return false; } $i++; }
    return true;
}
$count = 0;
for ($n = 0; $n < 1000000; $n++) { if (isPrime($n)) { $count++; } }
echo $count, "\n";
