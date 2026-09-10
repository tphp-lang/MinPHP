<?php

// final 方法不可被重写
// expect-error: 不能重写 final 方法

class Base
{
    final public function locked(): int
    {
        return 1;
    }
}

class Sub extends Base
{
    public function locked(): int
    {
        return 2;
    }
}

class Main
{
    public function main(): void
    {
        echo (new Sub())->locked(), "\n";
    }
}
