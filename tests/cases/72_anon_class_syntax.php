<?php

// expect-error: 匿名类必须 implements 至少一个接口

class Main
{
    public function main(): void
    {
        $a = new class {
            public int $x = 1;
        };
        echo $a->x, "\n";
    }
}
