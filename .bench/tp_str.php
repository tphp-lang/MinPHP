<?php

// B3 字符串拼接 40000 次（O(n²) 拷贝）：测 SSO/bump 池的分配与拷贝
class Main
{
    public function main(): void
    {
        string $s = "";
        int $i = 0;
        while ($i < 40000) {
            $s = $s . "ab";
            $i = $i + 1;
        }
        echo len($s), "\n";
    }
}
