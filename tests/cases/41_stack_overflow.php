<?php

// 无限递归：深度计数器 panic 而非裸崩溃（修复前为 0xC00000FD）
// expect-panic: stack overflow

function boom(int $n): int
{
    return boom($n + 1);
}

class Main
{
    public function main(): void
    {
        echo boom(1);
    }
}
