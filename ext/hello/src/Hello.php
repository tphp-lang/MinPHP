<?php

namespace Hello;

/**
 * 示例包的实现（`#import hello` 后，调用方 `use Hello\Hello;` 即可使用）。
 *
 * 这里只用语言自身的类型与内置函数，不携带任何 C 能力 ——
 * 因此产物形态与普通程序完全一致（单 TU、零依赖小 exe）。
 */
final class Hello
{
    /** 打招呼：hello, <who>! */
    public static function greet(string $who): string
    {
        return "hello, " . $who . "!";
    }

    /** 把字符串重复两遍并以空格连接 */
    public static function twice(string $s): string
    {
        array<string> $parts = [];
        int $i = 0;
        while ($i < 2) {
            $parts[] = $s;
            $i = $i + 1;
        }
        return implode(" ", $parts);
    }

    /** 求和（演示包内使用数组与循环） */
    public static function sum(array<int> $xs): int
    {
        int $total = 0;
        foreach ($xs as $x) {
            $total = $total + $x;
        }
        return $total;
    }
}
