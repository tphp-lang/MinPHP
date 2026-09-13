<?php

// object 无属性布局：未收窄直接读取字段 → 编译期报错
// expect-error: object 类型无属性布局

class Dog
{
    public int $n = 1;
}

class Main
{
    public function main(): void
    {
        object $o = new Dog();
        echo $o->n, "\n";
    }
}
