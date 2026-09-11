<?php

// #php 只接受模块名（字母/数字/下划线）
// expect-error: #php 只接受模块名

#php 123bad

class Main
{
    public function main(): void
    {
        echo "unreachable", "\n";
    }
}
