<?php

// private 常量不能声明 final（PHP: zend_compile.c 编译错误）
// expect-error: private 常量不能声明 final

class A
{
    private final const int X = 1;
}

class Main
{
    public function main(): void
    {
        echo "unreachable", "\n";
    }
}
