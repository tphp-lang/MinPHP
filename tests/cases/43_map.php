<?php

// map<K,V>：关联数组（键限 int/string，哈希无序）
// expect:
// 2
// alice=95
// bob=87
// 182
// one/two
// 99
// 2
// b=2
// y
// x=20,x=30,x=10
/* map 为哈希无序，键序不保证——expect 按实际哈希序固化，变更哈希函数时同步更新 */

class Main
{
    public function main(): void
    {
        map<string,int> $score = [];
        $score["alice"] = 95;
        $score["bob"] = 87;
        echo len($score), "\n";
        echo "alice=", $score["alice"], "\n";
        echo "bob=", $score["bob"], "\n";

        // 遍历入口：array_keys + 下标读
        int $total = 0;
        foreach (array_keys($score) as $name) {
            $total = $total + $score[$name];
        }
        echo $total, "\n";

        // int 键
        map<int,string> $names = [];
        $names[1] = "one";
        $names[2] = "two";
        echo $names[1], "/", $names[2], "\n";

        // 覆盖写
        $score["alice"] = 99;
        echo $score["alice"], "\n";

        // => 字面量（推导 map<string,int>）
        map<string,int> $cfg = ["a" => 1, "b" => 2];
        echo len($cfg), "\n";
        echo "b=", $cfg["b"], "\n";

        // int 键字面量
        map<int,string> $n = [1 => "x", 2 => "y"];
        echo $n[2], "\n";

        // 插值 + implode 组合
        map<int,string> $tab = [10 => "x=10", 20 => "x=20", 30 => "x=30"];
        array<string> $lines = [];
        foreach (array_keys($tab) as $k) {
            $lines[] = $tab[$k];
        }
        echo implode(",", $lines), "\n";
    }
}
