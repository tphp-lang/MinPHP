<?php

// or {...} 覆盖全部可失败调用：方法 / 静态 / 构造器 / 闭包（不止全局函数）
// expect:
// 3
// method=-1
// ok=3
// ctor=9
// static=5
// closure=42
// 3
// recovered=3
// chain=7
// calls=1
// chainCaught=-1
// calls=1

class Demo
{
    private int $v = 0;

    public function __construct(int $v)
    {
        if ($v < 0) {
            throw "ctor failed";
        }
        $this->v = $v;
    }

    public function hello(): int
    {
        throw "hello failed";
    }

    public function ok(): int
    {
        return $this->v;
    }

    public static function make(int $v): Demo
    {
        if ($v < 0) {
            throw "static failed";
        }
        return new Demo($v);
    }
}

class Holder
{
    public array<int> $items;

    public function __construct(int $n)
    {
        // 构造中途抛错：半成品对象（已持有堆字段）必须正确释放，不能泄漏
        $this->items = [1, 2];
        if ($n < 0) {
            throw "partial ctor";
        }
        $this->items[] = 3;
    }

    public function count(): int
    {
        return len($this->items);
    }
}

class Chain
{
    public static int $calls = 0;

    public function boom(): Chain
    {
        throw "chain boom";
    }

    public function side(): Chain
    {
        self::$calls = self::$calls + 1;
        return $this;
    }

    public function val(): int
    {
        return 7;
    }
}

class Main
{
    public function main(): void
    {
        Demo $d = new Demo(3);
        echo $d->ok(), "\n";

        // 1) 方法调用 or
        int $r = $d->hello() or { -1; };
        echo "method=", $r, "\n";

        // 2) 正常方法（不进 or 块）
        int $ok = $d->ok() or { -99; };
        echo "ok=", $ok, "\n";

        // 3) 构造器 or（失败时用替代对象）
        Demo $e = new Demo(-1) or { new Demo(9); };
        echo "ctor=", $e->ok(), "\n";

        // 4) 静态调用 or
        Demo $f = Demo::make(-1) or { new Demo(5); };
        echo "static=", $f->ok(), "\n";

        // 5) 闭包调用 or
        $g = function (int $x): int {
            if ($x > 0) {
                return $x;
            }
            throw "closure failed";
        };
        int $h = $g(-1) or { 42; };
        echo "closure=", $h, "\n";

        // 6) 构造中途抛错 + 堆字段（半成品释放）+ or 恢复
        Holder $hp = new Holder(-1) or { new Holder(1); };
        echo $hp->count(), "\n";
        echo "recovered=", $hp->count(), "\n";

        // 7) 链式：or 管整条链（正常路径）
        Chain $ch = new Chain();
        echo "chain=", $ch->side()->val() or { -1; }, "\n";
        echo "calls=", Chain::$calls, "\n";

        // 8) 链式中段失败 → 短路进 or 块，后续 side()/val() 不再求值（calls 不增长）
        echo "chainCaught=", $ch->boom()->side()->val() or { -1; }, "\n";
        echo "calls=", Chain::$calls, "\n";
    }
}
