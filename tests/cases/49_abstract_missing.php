<?php

// 非抽象子类必须实现全部抽象方法
// expect-error: 有未实现的抽象方法

abstract class Shape
{
    abstract public function area(): double;
    abstract public function name(): string;
}

class Half extends Shape
{
    public function area(): double
    {
        return 1.0;
    }
}

class Main
{
    public function main(): void
    {
        echo (new Half())->area(), "\n";
    }
}
