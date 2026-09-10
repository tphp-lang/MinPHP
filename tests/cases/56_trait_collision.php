<?php

// trait 方法冲突未解决 → 编译错误（PHP: "has not been applied, because of collision"）
// expect-error: trait 方法冲突

trait T1
{
    public function m(): int
    {
        return 1;
    }
}

trait T2
{
    public function m(): int
    {
        return 2;
    }
}

class C
{
    use T1, T2;
}

class Main
{
    public function main(): void
    {
        echo "unreachable", "\n";
    }
}
