/*
 * #php 桥接冒烟（带参数调用 + 取值返回）：验证自备 ABI 声明足以完成
 * "C 传参 → 调 PHP 原生函数 → 读回返回值"。
 *
 * 依据（php-src 8.5.7 + php8.dll 导出表核对，2026-09-11 实测）：
 *   - 导出符号：`_call_user_function_impl`（带前导下划线）、`zend_eval_stringl`、
 *     `zend_string_init_interned`；而 `zend_string_init` 是 inline **未导出**
 *     → 运行时不构造 zend_string，改用 eval 桥（参数按 PHP 字面量转义后拼进代码）。
 *   - `zend_eval_stringl(str, len, retval, name)`：retval != NULL 时函数内部会
 *     **自动把代码包成 `return <str>;`** → 只能传"表达式"，自己再写 return 会变成
 *     `return return ...;;` 编译失败（踩过的坑）。
 *   - zval = 16 字节：value(8) + type_info(4) + u2(4)；type 取 type_info 低字节。
 *   - zend_string = gc(8) + h(8) + len(8) + val[] → val 偏移 24。
 *
 * 构建步骤见 tests/native/README.md。
 */

#include <stdio.h>
#include <string.h>
#include <stdint.h>

/* ---------------- 最小 ABI 声明（自备，不依赖 php-src 头） ---------------- */
typedef union {
    long lval;
    double dval;
    void *ptr;
    void *str;
    void *arr;
    void *obj;
    uint64_t num;
} tphp_zend_value;

typedef struct {
    tphp_zend_value value;
    uint32_t type_info;
    uint32_t u2;
} tphp_zval;

typedef struct {
    uint32_t refcount;
    uint32_t gc_type_info;
    uint64_t h;
    size_t len;
    char val[1];
} tphp_zend_string;

#define TPHP_IS_STRING 6

extern int php_embed_init(int argc, char **argv);
extern void php_embed_shutdown(void);
extern int zend_eval_stringl(const char *str, size_t str_len, tphp_zval *retval, const char *string_name);

/* 读值助手（只读，不构造 zend_string） */
static const char *string_of_zval(const tphp_zval *z)
{
    if ((z->type_info & 0xFF) != TPHP_IS_STRING || z->value.str == NULL) {
        return NULL;
    }
    return ((const tphp_zend_string *)z->value.str)->val;
}

/* C 字符串 → PHP 单引号字面量（转义反斜杠、单引号与控制字符） */
static void php_str_literal(const char *s, char *out, size_t cap)
{
    size_t j = 0;
    if (j + 1 < cap) {
        out[j++] = '\'';
    }
    for (size_t i = 0; s[i] != '\0' && j + 5 < cap; i++) {
        unsigned char c = (unsigned char)s[i];
        if (c == '\\' || c == '\'') {
            out[j++] = '\\';
            out[j++] = (char)c;
        } else if (c < 0x20) {
            j += (size_t)snprintf(out + j, cap - j, "\\x%02x", c);
        } else {
            out[j++] = (char)c;
        }
    }
    if (j + 1 < cap) {
        out[j++] = '\'';
    }
    out[j] = '\0';
}

/* 调用 helper：表达式（参数已做字面量转义）交给 eval 桥 */
static int call_expr(const char *expr, tphp_zval *ret)
{
    memset(ret, 0, sizeof(*ret));
    return zend_eval_stringl(expr, strlen(expr), ret, "tphp-native");
}

int main(int argc, char **argv)
{
    if (php_embed_init(argc, argv) != 0) {
        fprintf(stderr, "smoke_call: php_embed_init 失败\n");
        return 1;
    }

    /* 1) 无参调用：表达式直接求值 */
    tphp_zval r1;
    if (call_expr("json_encode([1,2,3])", &r1) == 0) {
        printf("json=%s\n", string_of_zval(&r1) != NULL ? string_of_zval(&r1) : "(非字符串)");
    }

    /* 2) 带字符串参数：转义成 PHP 字面量后拼进代码 */
    char lit[64];
    char expr[128];
    php_str_literal("a'b\\c", lit, sizeof(lit));
    snprintf(expr, sizeof(expr), "md5(%s)", lit);
    tphp_zval r2;
    if (call_expr(expr, &r2) == 0) {
        printf("md5=%s\n", string_of_zval(&r2) != NULL ? string_of_zval(&r2) : "(非字符串)");
    }

    php_embed_shutdown();
    return 0;
}
