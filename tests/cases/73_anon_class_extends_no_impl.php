<?php

// expect-error: 匿名类必须 implements 至少一个接口

class Base
{
    public int $b = 1;
}

class Main
{
    public function main(): void
    {
        $a = new class extends Base {
            public int $x = 2;
        };
        echo $a->x, "\n";
    }
}
