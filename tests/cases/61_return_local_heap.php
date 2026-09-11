<?php

// 返回表达式引用局部堆变量：求值必须发生在 RC 清理之前
// （否则读到已释放内存 → 段错误；修复前 `return implode("", $parts)` 必崩）
// expect:
// ababab
// 3
// x,y
// a-b!

function build(int $n): string
{
    array<string> $parts = [];
    int $i = 0;
    while ($i < $n) {
        $parts[] = "ab";
        $i = $i + 1;
    }
    return implode("", $parts);
}

function countIt(): int
{
    array<int> $xs = [1, 2, 3];
    return len($xs);
}

function joinIt(): string
{
    array<string> $xs = ["x", "y"];
    return implode(",", $xs);
}

function nested(): string
{
    array<string> $parts = ["a", "b"];
    // 返回值本身又是一次使用局部堆变量的调用
    return implode("-", $parts) . "!";
}

class Main
{
    public function main(): void
    {
        echo build(3), "\n";
        echo countIt(), "\n";
        echo joinIt(), "\n";
        echo nested(), "\n";
    }
}
