<?php

// 包不存在：报错须附搜索路径与可用包列表
// expect-error: #import nosuch/pkg 未找到

#import nosuch/pkg

class Main
{
    public function main(): void
    {
        echo "unreachable", "\n";
    }
}
