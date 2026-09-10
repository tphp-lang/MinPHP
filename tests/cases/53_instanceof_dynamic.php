<?php

// instanceof 右侧不支持动态类名（AOT 无运行期类型表查找）
// expect-error: instanceof 右侧只支持类名或接口名

class Foo
{
}

class Main
{
    public function main(): void
    {
        Foo $f = new Foo();
        string $cls = "Foo";
        echo $f instanceof $cls ? "y" : "n", "\n";
    }
}
