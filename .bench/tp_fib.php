<?php

// B1 递归 fib(33)：函数调用 + 算术 + 栈深度（~1140 万次调用）
function fib(int $n): int
{
    if ($n < 2) {
        return $n;
    }
    return fib($n - 1) + fib($n - 2);
}

class Main
{
    public function main(): void
    {
        echo fib(33), "\n";
    }
}
