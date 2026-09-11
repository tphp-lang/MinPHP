<?php

namespace Demo\Native;

/** 把包内 C 能力包装成有类型的自研 API（命名空间隔离，不与 #php 原生函数冲突）。 */
final class Api
{
    public static function add(int $a, int $b): int
    {
        int $r = c->demo_native_add($a, $b);
        return $r;
    }

    public static function mul(int $a, int $b): int
    {
        int $r = c->demo_native_mul($a, $b);
        return $r;
    }
}
