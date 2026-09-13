<?php

// 硬边界：instanceof 目标为接口时不产生收窄（需运行期 itab 查表）
// expect-error: object 类型无方法布局

interface Tagged
{
    public function tag(): string;
}

class Dog implements Tagged
{
    public function tag(): string
    {
        return "t";
    }
}

class Main
{
    public function main(): void
    {
        object $o = new Dog();
        if ($o instanceof Tagged) {
            echo $o->tag(), "\n";   // 目标为接口：不收窄 → 报错
        }
    }
}
