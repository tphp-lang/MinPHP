<?php

// expect:
// inline n=42 h=43
// cap base=10 got=15
// base+iface: base=7 h=107
// demo=42
// dtor1
// ref: b
// nested=82
// arr a
// arr b

interface Handler
{
    public function handle(int $x): int;
}

interface Named
{
    public function name(): string;
}

class Base
{
    public int $b;
    public function __construct(int $b)
    {
        $this->b = $b;
    }
}

function demo(Handler $h): int
{
    return $h->handle(1);
}

class Main
{
    public function main(): void
    {
        // 1) 就地使用：字段读写 + 方法调用
        $h = new class implements Handler {
            public int $n = 0;
            public function handle(int $x): int
            {
                $this->n = $x;
                return $x + 1;
            }
        };
        $r = $h->handle(42);
        echo "inline n=", $h->n, " h=", $r, "\n";

        // 2) 构造参数捕获外层局部变量（替代闭包 use；匿名类无 env）
        $base = 10;
        $cap = new class($base) implements Handler {
            public int $base;
            public function __construct(int $b)
            {
                $this->base = $b;
            }
            public function handle(int $x): int
            {
                return $this->base + $x;
            }
        };
        $base = 999; // 捕获的是构造时的值
        echo "cap base=", $cap->base, " got=", $cap->handle(5), "\n";

        // 3) 继承父类并实现接口（构造参数喂给父类 __construct）
        $bi = new class(7) extends Base implements Handler {
            public function handle(int $x): int
            {
                return $this->b + $x;
            }
        };
        echo "base+iface: base=", $bi->b, " h=", $bi->handle(100), "\n";

        // 4) 跨边界：以 implements 的具名接口作为形参类型
        echo "demo=", demo(new class implements Handler {
            public function handle(int $x): int
            {
                return $x + 41;
            }
        }), "\n";

        // 5) 引用类型：接口变量重新赋值 → 旧对象引用归零触发 __destruct
        Named $named = new class implements Named {
            public function name(): string
            {
                return "a";
            }
            public function __destruct()
            {
                echo "dtor1\n";
            }
        };
        $named = new class implements Named {
            public function name(): string
            {
                return "b";
            }
        };
        echo "ref: ", $named->name(), "\n";

        // 6) 嵌套匿名类（内层亦为就地接口实现）
        $outer = new class implements Handler {
            public function handle(int $x): int
            {
                $inner = new class implements Handler {
                    public function handle(int $y): int
                    {
                        return $y * $y;
                    }
                };
                return $inner->handle($x) + 1;
            }
        };
        echo "nested=", $outer->handle(9), "\n";

        // 7) 多态接口数组：元素为匿名类对象（接口胖指针）
        array<Named> $names = [
            new class implements Named {
                public function name(): string
                {
                    return "a";
                }
            },
            new class implements Named {
                public function name(): string
                {
                    return "b";
                }
            }
        ];
        foreach ($names as $nm) {
            echo "arr ", $nm->name(), "\n";
        }
    }
}
