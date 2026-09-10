<?php

// or 块内堆临时值的作用域：块内链式接收者的释放必须落在块内（嵌套 or）
// expect:
// nested=99
// recover=5
// local=3

class A
{
    public function boom(): A
    {
        throw "inner boom";
    }

    public function val(): int
    {
        return 5;
    }
}

class B
{
    public function get(): A
    {
        return new A();
    }
}

class Main
{
    public function main(): void
    {
        B $b = new B();

        // 嵌套 or：外层失败 → 内层调用链（也失败）→ 取 99
        int $x = $b->get()->boom()->val() or { $b->get()->boom()->val() or { 99; }; };
        echo "nested=", $x, "\n";

        // 外层失败 → 内层成功
        int $y = $b->get()->boom()->val() or { $b->get()->val() or { -1; }; };
        echo "recover=", $y, "\n";

        // 块内声明堆局部变量（块作用域独立，退出时正确释放）
        int $z = $b->get()->boom()->val() or {
            array<int> $local = [1, 2, 3];
            len($local) + 0;
        };
        echo "local=", $z, "\n";
    }
}
