<?php

// 不能调用抽象方法（无实现）
// expect-error: 不能调用抽象方法

abstract class Base
{
    abstract public function m(): int;
}

class Impl extends Base
{
    public function m(): int
    {
        return 1;
    }

    public function bad(): int
    {
        return parent::m();
    }
}

class Main
{
    public function main(): void
    {
        echo (new Impl())->bad(), "\n";
    }
}
