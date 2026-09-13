<?php

// 收窄分支局部：if 分支内的收窄退出即失效，分支外访问 object 成员再次报错
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
        if ($o instanceof Dog) {
            echo $o->bark(), "\n";   // 分支内：已收窄，合法
        }
        echo $o->bark(), "\n";       // 分支外：收窄已失效 → 报错
    }
}
