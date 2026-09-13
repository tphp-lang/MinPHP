<?php

// expect-error: 类 匿名类 实现接口 Handler 缺少方法 handle()

interface Handler
{
    public function handle(int $x): int;
}

class Main
{
    public function main(): void
    {
        $a = new class implements Handler {
            public int $x = 1;
        };
        echo $a->x, "\n";
    }
}
