<?php

// B2 试除法数 1000000 内素数：循环 + 取模 + 比较
function isPrime(int $n): bool
{
    if ($n < 2) {
        return false;
    }
    int $i = 2;
    while ($i * $i <= $n) {
        if ($n % $i == 0) {
            return false;
        }
        $i = $i + 1;
    }
    return true;
}

class Main
{
    public function main(): void
    {
        int $count = 0;
        int $n = 0;
        while ($n < 1000000) {
            if (isPrime($n)) {
                $count = $count + 1;
            }
            $n = $n + 1;
        }
        echo $count, "\n";
    }
}
