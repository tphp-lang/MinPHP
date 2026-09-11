#ifndef DEMO_NATIVE_H
#define DEMO_NATIVE_H

#include <stdint.h>

/* 示例包的 C 能力：供 #import demo/native 的 PHP 侧包装调用 */
int32_t demo_native_add(int32_t a, int32_t b);
int32_t demo_native_mul(int32_t a, int32_t b);

#endif /* DEMO_NATIVE_H */
