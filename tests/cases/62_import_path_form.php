<?php

// #import 只接受包名，路径形式（防越界）应报错
// expect-error: #import 只接受包名

#import ../evil

class Main
{
    public function main(): void
    {
        echo "unreachable", "\n";
    }
}
