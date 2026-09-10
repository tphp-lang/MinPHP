<?php

// trait 不可实例化（编译期展开，无运行期类型痕迹）
// expect-error: trait 'Loggable' 不能实例化

trait Loggable
{
    public function log(): string
    {
        return "log";
    }
}

class Main
{
    public function main(): void
    {
        Loggable $l = new Loggable();
        echo $l->log(), "\n";
    }
}
