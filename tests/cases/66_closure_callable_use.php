<?php
// use 捕获的 callable 与闭包自身 callable 形参均可在闭包体内调用
//（签名随捕获/调用点回填跨闭包边界传递）
// expect:
// 15
// 105
// 109
// 15
// 8
// 57
class Main
{
    public function main(): void
    {
        // 1. 捕获 callable 形参（签名经调用点特化）
        echo Main::apply(fn (int $x): int => $x * 3, 5), "\n";

        // 2. 捕获 callable 局部变量（签名来自赋值）
        callable $cb = fn (int $x): int => $x + 100;
        $inner = function (int $x) use ($cb): int {
            return $cb($x);
        };
        echo $inner(5), "\n";

        // 3. 多个捕获 callable 共存
        callable $g = fn (int $x): int => $x - 1;
        $wrap = function (int $x) use ($g, $cb): int {
            return $g($x) + $cb(0);
        };
        echo $wrap(10), "\n";

        // 4. 闭包自身 callable 形参在体内调用（签名经调用点回填）
        echo Main::applyParam(fn (int $x): int => $x * 3, 5), "\n";

        // 5. 闭包变量赋值传递后调用（FnSymbol 随赋值流动）
        $a = function (int $x, callable $f): int {
            return $f($x) * 2;
        };
        $b = $a;
        echo $b(5, fn (int $v): int => $v - 1), "\n";

        // 6. 先声明后赋闭包（重赋值路径）
        callable $c;
        $c = function (int $x, callable $f): int {
            return $f($x) + 7;
        };
        echo $c(5, fn (int $v): int => $v * 10), "\n";
    }

    public static function apply(callable $cb, int $v): int
    {
        $inner = function (int $x) use ($cb): int {
            return $cb($x);
        };
        return $inner($v);
    }

    public static function applyParam(callable $cb, int $v): int
    {
        $inner = function (int $x, callable $fn): int {
            return $fn($x);
        };
        return $inner($v, $cb);
    }
}
