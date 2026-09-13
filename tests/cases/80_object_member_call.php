<?php

// object 无方法布局：未收窄直接调用成员方法 → 编译期报错
// expect-error: object 类型无方法布局

class Dog
{
    public function bark(): string
    {
        return "wang";
    }
}

class Main
{
    public function main(): void
    {
        object $o = new Dog();
        echo $o->bark(), "\n";
    }
}
