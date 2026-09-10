<?php

// 抽象类不可实例化
// expect-error: 抽象类 'Shape' 不能实例化

abstract class Shape
{
    abstract public function area(): double;
}

class Main
{
    public function main(): void
    {
        Shape $s = new Shape();
        echo $s->area(), "\n";
    }
}
