/*
 * String：不可变值类型（doc/type.md）。
 *
 * SSO：≤23 字节内联在结构体内，零分配；超长走 bump 字符串池。
 * 字符串内容永不修改，赋值即结构体拷贝（共享池内存是安全的）。
 * 字面量 is_lit 标记指向 .rodata，永不释放。
 * v0.1 池内存随进程回收；v0.1 不做逐串 free（见 README 内存模型一节）。
 *
 * 注：联合使用具名成员 u——匿名联合是 C11 特性，TCC 0.9.x 不支持。
 */
#ifndef TPHP_STRING_H
#define TPHP_STRING_H

#define STR_SSO_MAX 23

typedef struct {
    union {
        char *data;
        char local[STR_SSO_MAX + 1]; /* is_local 时使用 */
    } u;
    int32_t length;
    bool is_local;
    bool is_lit; /* .rodata 字面量，永不 free */
} String;

/* ---------------------------------------------------------------- 致命错误 */

static void tphp_panic(const char *msg)
{
    fprintf(stderr, "TinyPHP runtime error: %s\n", msg);
    exit(1);
}

/* ------------------------------------------------- 字符串池（bump 分配器） */

typedef struct TphpStrChunk {
    struct TphpStrChunk *next;
    size_t used;
    size_t cap;
    char bytes[];
} TphpStrChunk;

static TphpStrChunk *tphp_str_chunk = NULL;

static char *tphp_str_pool_alloc(size_t n)
{
    n = (n + 15u) & ~(size_t)15u;
    if (!tphp_str_chunk || tphp_str_chunk->used + n > tphp_str_chunk->cap) {
        size_t cap = 65536;
        if (cap < n) {
            cap = n;
        }
        TphpStrChunk *c = (TphpStrChunk *)malloc(sizeof(TphpStrChunk) + cap);
        if (!c) {
            tphp_panic("out of memory");
        }
        c->next = tphp_str_chunk;
        c->used = 0;
        c->cap = cap;
        tphp_str_chunk = c;
    }
    char *p = tphp_str_chunk->bytes + tphp_str_chunk->used;
    tphp_str_chunk->used += n;
    return p;
}

/* ---------------------------------------------------------------- 构造 */

static String tphp_str_lit(const char *lit, int32_t length)
{
    String s;
    s.u.data = (char *)lit;
    s.length = length;
    s.is_local = false;
    s.is_lit = true;
    return s;
}

/* 零值字符串（未初始化变量的默认值） */
static String tphp_str_empty(void)
{
    String s;
    memset(&s, 0, sizeof(s));
    s.is_local = true;
    s.is_lit = true;
    return s;
}

/* 分配 length 字节并保证 NUL 结尾 */
static String tphp_str_alloc(int32_t length)
{
    String s;
    if (length <= STR_SSO_MAX) {
        s.length = length;
        s.is_local = true;
        s.is_lit = false;
        s.u.local[length] = '\0';
        return s;
    }
    char *p = tphp_str_pool_alloc((size_t)length + 1u);
    p[length] = '\0';
    s.u.data = p;
    s.length = length;
    s.is_local = false;
    s.is_lit = false;
    return s;
}

static String tphp_str_copy(const char *bytes, int32_t length)
{
    String s = tphp_str_alloc(length);
    char *dst = s.is_local ? s.u.local : s.u.data;
    if (length > 0) {
        memcpy(dst, bytes, (size_t)length);
    }
    return s;
}

/* ---------------------------------------------------------------- 操作 */

/*
 * 取 String 的 C 字符串指针。两条路径的安全边界完全不同：
 *  - tphp_str_cref(&s)：指向调用方对象，调用期间始终有效——任何 String 都可用；
 *  - tphp_str_c(s)（按值）：仅限 .rodata 字面量（is_lit，u.data 指向静态存储，
 *    副本销毁无影响）。SSO 值经按值传参返回 u.local 会指向已销毁的参数副本。
 * 生成代码里 c_str(变量) 走 cref，c_str(字面量) 走按值版。
 */
static const char *tphp_str_cref(const String *s)
{
    return s->is_local ? s->u.local : s->u.data;
}

/* 仅用于 .rodata 字面量（见 tphp_str_cref 注释）。
 * clang 静态分析不知道 is_local 恒为 false 这一调用约定，保守告警，压制。 */
#if defined(__clang__)
#pragma clang diagnostic push
#pragma clang diagnostic ignored "-Wreturn-stack-address"
#endif
static const char *tphp_str_c(const String s)
{
    return s.is_local ? s.u.local : s.u.data;
}
#if defined(__clang__)
#pragma clang diagnostic pop
#endif

static String tphp_str_concat(String a, String b)
{
    String s = tphp_str_alloc(a.length + b.length);
    char *dst = s.is_local ? s.u.local : s.u.data;
    if (a.length > 0) {
        memcpy(dst, tphp_str_cref(&a), (size_t)a.length);
    }
    if (b.length > 0) {
        memcpy(dst + a.length, tphp_str_cref(&b), (size_t)b.length);
    }
    return s;
}

static bool tphp_str_eq(String a, String b)
{
    if (a.length != b.length) {
        return false;
    }
    if (a.length == 0) {
        return true;
    }
    /* 不要写 memcmp(tphp_str_c(a), tphp_str_c(b), ...)：
     * tphp_str_c 返回指向"按值参数副本内联存储"的指针，同一表达式里出现
     * 第二次结构体按值调用时，TCC(Win64) 会复用前一个临时槽，第一个指针悬空。
     * 直接访问参数字段——参数是调用方实参临时，本调用期间稳定。 */
    const char *pa = a.is_local ? a.u.local : a.u.data;
    const char *pb = b.is_local ? b.u.local : b.u.data;
    return memcmp(pa, pb, (size_t)a.length) == 0;
}

static int tphp_str_cmp(String a, String b)
{
    int32_t n = a.length < b.length ? a.length : b.length;
    if (n > 0) {
        /* 同 tphp_str_eq：避免同表达式两次结构体按值调用 */
        const char *pa = a.is_local ? a.u.local : a.u.data;
        const char *pb = b.is_local ? b.u.local : b.u.data;
        int c = memcmp(pa, pb, (size_t)n);
        if (c != 0) {
            return c;
        }
    }
    return a.length < b.length ? -1 : (a.length > b.length ? 1 : 0);
}

/* $s[i]：返回单字符字符串 */
static String tphp_str_char(String s, int32_t i)
{
    if (i < 0 || i >= s.length) {
        tphp_panic("string index out of bounds");
    }
    String r = tphp_str_empty();
    r.u.local[0] = tphp_str_cref(&s)[i];
    r.length = 1;
    return r;
}

#endif /* TPHP_STRING_H */
