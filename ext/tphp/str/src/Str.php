<?php

namespace Tphp\Str;

/**
 * 字符串/数组小工具（示例包实现）。
 *
 * 命名空间隔离：与 `#php` 引入的 PHP 原生函数（全局名）不冲突。
 */
final class Str
{
    /** 拼成 "a, b, c" */
    public static function csv(array<string> $parts): string
    {
        return implode(", ", $parts);
    }

    /** 求和（示例：跨包调用数组与循环） */
    public static function sum(array<int> $xs): int
    {
        int $total = 0;
        foreach ($xs as $x) {
            $total = $total + $x;
        }
        return $total;
    }

    /** 重复拼接 n 次（注意：小型示例，用 implode 而非 O(n²) 的 `.=`） */
    public static function repeat(string $s, int $n): string
    {
        array<string> $parts = [];
        int $i = 0;
        while ($i < $n) {
            $parts[] = $s;
            $i = $i + 1;
        }
        return implode("", $parts);
    }
}

/** 断号位保留：包内可含多个类（跨文件/跨包均免 import，靠命名空间区分） */
final class Counter
{
    private int $n = 0;

    public function add(int $k): void
    {
        $this->n = $this->n + $k;
    }

    public function value(): int
    {
        return $this->n;
    }
}
