<?php

// B6 implode：4 万段收集后一次拼接（对比 tp_str 的 O(n²) 拼接）
class Main
{
    public function main(): void
    {
        array<string> $parts = [];
        int $i = 0;
        while ($i < 40000) {
            $parts[] = "ab";
            $i = $i + 1;
        }
        string $s = implode("", $parts);
        echo len($s), "\n";
    }
}
