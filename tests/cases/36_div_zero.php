<?php

// 整数除零：panic 而非裸崩溃（修复前为 0xC0000094 无输出）
// expect-panic: division by zero

class Main
{
    public function main(): void
    {
        int $a = 10;
        int $b = 0;
        echo $a / $b;
    }
}
