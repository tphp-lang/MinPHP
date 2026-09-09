/*
 * TinyPHP 运行时总入口。
 *
 * 生成的 C 代码只 #include "prelude.h"，其余运行时头文件由此按序引入。
 * 全部实现为 static inline / static 函数，零链接依赖（C99 + 语句表达式扩展）。
 */
#ifndef TPHP_PRELUDE_H
#define TPHP_PRELUDE_H

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdbool.h>
#include <stdint.h>
#include <math.h>

#include "tphp_string.h"
#include "tphp_object.h"
#include "tphp_callable.h"
#include "tphp_array.h"
#include "tphp_map.h"
#include "tphp_builtin.h"

#endif /* TPHP_PRELUDE_H */
