<?php
class Animal { public function speak(): int { return 1; } }
class Dog extends Animal { public function speak(): int { return 2; } }
class Cat extends Animal { public function speak(): int { return 3; } }
$zoo = [];
for ($i = 0; $i < 12000; $i++) { $zoo[] = $i % 2 == 0 ? new Dog() : new Cat(); }
$sum = 0;
for ($r = 0; $r < 300; $r++) { foreach ($zoo as $a) { $sum += $a->speak(); } }
echo $sum, "\n";
