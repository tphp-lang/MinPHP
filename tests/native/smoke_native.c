/*
 * #php（libphp 直连）链路冒烟验证：自备最小 ABI 声明，不依赖 php-src 头。
 * 目标：证明在这台机器上"编出 exe → 链 php8embed.lib → 运行期 php8.dll → 调 PHP 原生函数"可行。
 *
 * 手工声明依据（php-src 8.5.7）：
 *   sapi/embed/php_embed.h : int php_embed_init(int argc, char **argv); / void php_embed_shutdown(void);
 *   Zend/zend_execute.h    : ZEND_API zend_result zend_eval_stringl(const char *str, size_t str_len,
 *                                                                    zval *retval_ptr, const char *string_name);
 * 说明：zend_eval_string 是宏，实际符号是 zend_eval_stringl。
 */

#include <stdio.h>
#include <string.h>

extern int php_embed_init(int argc, char **argv);
extern void php_embed_shutdown(void);
extern int zend_eval_stringl(const char *str, size_t str_len, void *retval_ptr, const char *string_name);

int main(int argc, char **argv)
{
    if (php_embed_init(argc, argv) != 0) {
        fprintf(stderr, "smoke: php_embed_init 失败\n");
        return 1;
    }

    /* 调一个 PHP 原生函数：JSON 在 PHP 8 属于核心，无需额外扩展 */
    const char *code = "echo json_encode([1, 2, 3]), \"\\n\"; echo PHP_VERSION, \"\\n\";";
    int rc = zend_eval_stringl(code, strlen(code), NULL, "tphp-smoke");
    if (rc != 0) {
        fprintf(stderr, "smoke: zend_eval_stringl 返回 %d\n", rc);
    }

    php_embed_shutdown();
    return rc == 0 ? 0 : 2;
}
