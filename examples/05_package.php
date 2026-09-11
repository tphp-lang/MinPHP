<?php

// 包管理示例：#import 引入示例包（包体在**编译器目录**的 ext/hello/）。
//
// 运行（在编译器目录下执行；#import 从编译器目录的 ext/ 找包）：
//     php main.php run examples/05_package.php
//
// 示例包结构：
//     ext/hello/mod.php        指令式清单（能力声明；本例无 C 能力）
//     ext/hello/src/Hello.php  实现（namespace Hello; class Hello）

#import hello

use Hello\Hello;

class Main
{
    public function main(): void
    {
        echo Hello::greet("world"), "\n";
        echo Hello::twice("hi"), "\n";
        echo "sum=", Hello::sum([1, 2, 3]), "\n";
    }
}
