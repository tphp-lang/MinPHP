<?php

// 值类型（int）字段不可 unset
// expect-error: 无法 unset

class A
{
    public int $n;
}

class Main
{
    public function main(): void
    {
        A $a = new A();
        $a->n = 1;
        unset($a->n);
    }
}
