<?php

// @package demo/native
// @version 0.1.0
// @desc    示例包：自带 C 能力（清单显式声明，见 doc/package.md）
//
// 清单里的 #include / #flag 即"C 能力声明"：编译时会出现在能力汇总里。
// 相对路径按**包根**解析（-Iinclude → ext/demo/native/include）。

#include "demo_native.h"
#flag -Iinclude
#flag src/demo_native.c
