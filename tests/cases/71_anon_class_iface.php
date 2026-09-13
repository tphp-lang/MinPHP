<?php

// expect-error: 类型不匹配：期望 Handler，得到 匿名类

interface Handler
{
    public function handle(int $x): int;
}

interface Other
{
    public function other(int $x): int;
}

function demo(Handler $h): int
{
    return $h->handle(1);
}

class Main
{
    public function main(): void
    {
        echo demo(new class implements Other {
            public function other(int $x): int
            {
                return $x;
            }
        }), "\n";
    }
}
