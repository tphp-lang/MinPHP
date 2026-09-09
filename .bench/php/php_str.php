<?php
$s = "";
for ($i = 0; $i < 40000; $i++) { $s = $s . "ab"; }
echo strlen($s), "\n";
