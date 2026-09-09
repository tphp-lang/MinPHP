<?php

// implode：字符串累加的 O(n) 正解工具（收集 → 一次拼接）
// expect:
// a-b-c
// 5
// x=1,y=2,z=3
// x=10,x=20,x=30

class Main
{
    public function main(): void
    {
        array<string> $parts = [];
        $parts[] = "a";
        $parts[] = "b";
        $parts[] = "c";
        string $s = implode("-", $parts);
        echo $s, "\n";
        echo len($s), "\n";

        // 与数字插值混用：自动转 string
        array<string> $kv = [];
        $kv[] = "x=1";
        $kv[] = "y=2";
        $kv[] = "z=3";
        echo implode(",", $kv), "\n";

        array<int> $nums = [10, 20, 30];
        array<string> $strs = [];
        foreach ($nums as $n) {
            $strs[] = "x=$n";
        }
        echo implode(",", $strs), "\n";
    }
}
