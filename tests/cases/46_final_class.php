<?php

// final 类不可被继承
// expect-error: 不能继承 final 类

final class Sealed
{
    public function v(): int
    {
        return 1;
    }
}

class Sub extends Sealed
{
}

class Main
{
    public function main(): void
    {
        echo (new Sealed())->v(), "\n";
    }
}
