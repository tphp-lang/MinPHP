<?php

// B5 数组读写：push 10 万 + 正向 foreach 求和 + 反向 for 下标读取
class Main
{
    public function main(): void
    {
        array<int> $a = [];
        int $i = 0;
        while ($i < 500000) {
            $a[] = $i % 1000;
            $i = $i + 1;
        }
        int $sum = 0;
        foreach ($a as $v) {
            $sum = $sum + $v;
        }
        int $j = 499999;
        while ($j >= 0) {
            $sum = $sum + $a[$j];
            $j = $j - 1;
        }
        echo $sum, "\n";
    }
}
