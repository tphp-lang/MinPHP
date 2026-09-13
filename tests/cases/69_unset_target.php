<?php

// 整变量（非对象属性）不可 unset
// expect-error: 只支持对象属性

class Main
{
    public function main(): void
    {
        int $v = 1;
        unset($v);
    }
}
