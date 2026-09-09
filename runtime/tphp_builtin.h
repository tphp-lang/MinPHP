/*
 * 内置极小函数集的 C 实现：echo / len / dump / 类型转换。
 * echo 与 dump 按静态类型单态化调用（Gen 依 Checker 标注选择）。
 */
#ifndef TPHP_BUILTIN_H
#define TPHP_BUILTIN_H

/* ---------------------------------------------- 递归深度（栈溢出保护） */

#ifndef TPHP_MAX_DEPTH
#define TPHP_MAX_DEPTH 2000 /* Windows 默认 1MB 栈下的保守深度；#flag -DTPHP_MAX_DEPTH=n 可调 */
#endif

static int32_t tphp_depth = 0;

/* 宏而非 static 函数：TCC 不做跨函数内联，函数调用形态会让
 * 调用密集程序（每次调用 2 次 call）付出 ~80% 开销（实测 fib +81%）；
 * 宏展开后仅 ++/cmp/-- 三条指令。 */
#define tphp_depth_enter() \
    do { \
        if (++tphp_depth > TPHP_MAX_DEPTH) { \
            tphp_panic("stack overflow (recursion too deep)"); \
        } \
    } while (0)

#define tphp_depth_leave() \
    do { \
        tphp_depth--; \
    } while (0)

/* ---------------------------------------------------------------- echo */

static void tphp_echo_int(int32_t v) { printf("%d", v); }
static void tphp_echo_float(float v) { printf("%g", (double)v); }
static void tphp_echo_double(double v) { printf("%.14g", v); }
static void tphp_echo_bool(bool v) { fputs(v ? "true" : "false", stdout); }

/* char** argv → array<String>（Main::__construct(int, array<string>) 入参）。 */
static Array *tphp_args_array(int argc, char **argv)
{
    Array *a = tphp_arr_new(sizeof(String), 8, 0);
    for (int i = 0; i < argc; i++) {
        a = tphp_arr_push_str(a, tphp_str_copy(argv[i], (int32_t)strlen(argv[i])));
    }
    return a;
}

static void tphp_echo_str(String s)
{
    if (s.length > 0) {
        fwrite(tphp_str_cref(&s), 1, (size_t)s.length, stdout);
    }
}

/* ---------------------------------------------------------------- implode */

/* implode(sep, parts)：两次遍历（求总长 → 一次分配一次拷贝），O(n)。
 * 字符串累加 `$s = $s . x` 是 O(n²)（每次全量拷贝），集合型拼接用它。 */
static String tphp_str_implode(String sep, Array *a)
{
    if (!a) {
        tphp_panic("implode() applied to a null array");
    }
    int32_t total = 0;
    for (int32_t i = 0; i < a->length; i++) {
        String v = ((String *)a->data)[i];
        total += v.length;
        if (i > 0) {
            total += sep.length;
        }
    }
    String r = tphp_str_alloc(total);
    char *dst = r.is_local ? r.u.local : r.u.data; /* 自有可写缓冲，不经 cref（返回 const） */
    int32_t off = 0;
    for (int32_t i = 0; i < a->length; i++) {
        String v = ((String *)a->data)[i];
        if (i > 0 && sep.length > 0) {
            memcpy(dst + off, tphp_str_cref(&sep), (size_t)sep.length);
            off += sep.length;
        }
        if (v.length > 0) {
            memcpy(dst + off, tphp_str_cref(&v), (size_t)v.length);
            off += v.length;
        }
    }
    return r;
}

/* ---------------------------------------------------------------- dump */

static void tphp_dump_int(int32_t v) { printf("int(%d)\n", v); }
static void tphp_dump_float(float v) { printf("c.f32(%g)\n", (double)v); }
static void tphp_dump_double(double v) { printf("float(%.14g)\n", v); }
static void tphp_dump_bool(bool v) { printf("bool(%s)\n", v ? "true" : "false"); }

static void tphp_dump_str(String s)
{
    printf("string(%d) \"%.*s\"\n", s.length, (int)s.length, tphp_str_cref(&s));
}

/* ---------------------------------------------------------------- 标量 → 字符串 */

static String tphp_str_of_int(int32_t v)
{
    char buf[16];
    int n = snprintf(buf, sizeof(buf), "%d", v);
    return tphp_str_copy(buf, (int32_t)n);
}

static String tphp_str_of_float(float v)
{
    char buf[32];
    int n = snprintf(buf, sizeof(buf), "%g", (double)v);
    return tphp_str_copy(buf, (int32_t)n);
}

static String tphp_str_of_double(double v)
{
    char buf[40];
    int n = snprintf(buf, sizeof(buf), "%.14g", v);
    return tphp_str_copy(buf, (int32_t)n);
}

static String tphp_str_of_bool(bool v)
{
    return v ? tphp_str_lit("true", 4) : tphp_str_lit("false", 5);
}

static String tphp_str_of_long(long long v)
{
    char buf[24];
    int n = snprintf(buf, sizeof(buf), "%lld", v);
    return tphp_str_copy(buf, (int32_t)n);
}

static String tphp_str_of_ulong(unsigned long long v)
{
    char buf[24];
    int n = snprintf(buf, sizeof(buf), "%llu", v);
    return tphp_str_copy(buf, (int32_t)n);
}

/* ---------------------------------------------------------------- 字符串 → 标量（显式强转） */

static int32_t tphp_str_to_int(String s)
{
    return (int32_t)strtol(tphp_str_cref(&s), NULL, 10);
}

static float tphp_str_to_float(String s)
{
    return strtof(tphp_str_cref(&s), NULL);
}

static double tphp_str_to_double(String s)
{
    return strtod(tphp_str_cref(&s), NULL);
}

/* ---------------------------------------------------------------- 幂 */

static int32_t tphp_int_pow(int32_t base, int32_t exp)
{
    if (exp < 0) {
        return (int32_t)pow((double)base, (double)exp);
    }
    uint32_t b = (uint32_t)base;
    uint32_t r = 1u;
    while (exp > 0) {
        if (exp & 1) {
            r *= b;
        }
        b *= b;
        exp >>= 1;
    }
    return (int32_t)r; /* 溢出按 C 语义回绕 */
}

/* ---------------------------------------------------------------- char* ↔ string 桥（phpc） */

/* char* → string：深拷贝进字符串池（安全默认：C 侧后续 free/修改不影响） */
static String tphp_php_str(const char *p)
{
    if (!p) {
        return tphp_str_empty();
    }
    return tphp_str_copy(p, (int32_t)strlen(p));
}

/* char* → string：零拷贝借用（仅用于生命周期确定的静态数据，如 getenv/字面量） */
static String tphp_php_str_ref(const char *p)
{
    String s;
    if (!p) {
        return tphp_str_empty();
    }
    s.u.data = (char *)p;
    s.length = (int32_t)strlen(p);
    s.is_local = false;
    s.is_lit = true; /* 借用：永不释放 */
    return s;
}

/* ---------------------------------------------------------------- C 内存所有权（phpc） */

/* c-> 返回的指针默认借用（多数 C 返回是静态/借用数据，不可乱 free）。
 * c_own 登记 → 编译器在函数所有出口自动 free_since（开发者不写 free）；
 * cbuf = 分配 + 登记一体。跨出口安全：标记 = 栈深度。 */
static void **tphp_cmem_stack = NULL;
static size_t tphp_cmem_len = 0;
static size_t tphp_cmem_cap = 0;

static size_t tphp_cmem_mark(void)
{
    return tphp_cmem_len;
}

static void tphp_cmem_own(void *p)
{
    if (!p) {
        return;
    }
    if (tphp_cmem_len == tphp_cmem_cap) {
        size_t cap = tphp_cmem_cap ? tphp_cmem_cap * 2 : 16;
        void **s = (void **)realloc(tphp_cmem_stack, cap * sizeof(void *));
        if (!s) {
            tphp_panic("out of memory");
        }
        tphp_cmem_stack = s;
        tphp_cmem_cap = cap;
    }
    tphp_cmem_stack[tphp_cmem_len++] = p;
    tphp_mem_track_cmem(1);
}

static void tphp_cmem_free_since(size_t mark)
{
    while (tphp_cmem_len > mark) {
        void *p = tphp_cmem_stack[--tphp_cmem_len];
        if (p) {
            free(p);
            tphp_mem_track_cmem(-1);
        }
    }
}

/* cbuf(n)：分配 n 字节并登记所有权（函数出口自动释放） */
static void *tphp_cbuf(int64_t n)
{
    void *p = malloc((size_t)(n > 0 ? n : 1));
    if (!p) {
        tphp_panic("out of memory");
    }
    tphp_cmem_own(p);
    return p;
}

/* ---------------------------------------------------------------- 错误通道 */

/* throw 设置；调用点自动传播（生成代码在每个调用后检查）；
 * 最近的 `f() or { ... }` 用 tphp_err_take() 取走并处理。v0.1 单线程。 */
static bool tphp_err_flag = false;
static String tphp_err_msg;

static void tphp_err_set(String msg)
{
    tphp_err_flag = true;
    tphp_err_msg = msg;
}

static bool tphp_err_has(void)
{
    return tphp_err_flag;
}

/* 取走错误（清除标志）。 */
static String tphp_err_take(void)
{
    tphp_err_flag = false;
    return tphp_err_msg;
}

/* 顶层未处理的错误。 */
static void tphp_err_uncaught(String msg)
{
    fflush(stdout); /* 保证 stdout 先于 stderr 落盘，输出顺序确定 */
    fprintf(stderr, "Uncaught error: %.*s\n", (int)msg.length, tphp_str_cref(&msg));
    exit(1);
}

#endif /* TPHP_BUILTIN_H */
