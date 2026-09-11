<?php

// #import 自研标准库包（happy path）：调用仓库自带包 ext/tphp/str
// expect:
// x, y, z
// sum=6
// 60
// ababab

#import tphp/str

use Tphp\Str\Str;

class Main
{
    public function main(): void
    {
        // 字符串数组拼接（包内 implode）
        echo Str::csv(["x", "y", "z"]), "\n";

        // 字面量数组求和
        echo "sum=", Str::sum([1, 2, 3]), "\n";

        // 变量数组求和（走同一包方法）
        array<int> $xs = [10, 20, 30];
        echo Str::sum($xs), "\n";

        // 重复拼接
        echo Str::repeat("ab", 3), "\n";
    }
}
