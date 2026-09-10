<?php

// trait 冲突解决：insteadof 选实现 + as 别名
// expect:
// A
// B
// onlyA

trait A
{
    public function m(): string
    {
        return "A";
    }

    public function only(): string
    {
        return "onlyA";
    }
}

trait B
{
    public function m(): string
    {
        return "B";
    }
}

class C
{
    use A, B {
        A::m insteadof B;
        B::m as bm;
    }
}

class Main
{
    public function main(): void
    {
        C $c = new C();
        echo $c->m(), "\n";
        echo $c->bm(), "\n";
        echo $c->only(), "\n";
    }
}
