/*
 * map<K,V>：关联数组（哈希表，链地址法）。
 *
 * 键：int32_t 或 String（k_is_string 区分，Gen 按声明 K 生成适配缓冲）。
 * 值：定长字节槽（v_size 由 Gen 按 V 传入，标量/String 结构/指针）。
 * 引用语义（赋值共享同一 buckets）；Map 本身走引用计数。
 *
 * 第一版边界（doc/type.md）：V 限标量与 string；遍历用 array_keys(m) +
 * 下标读（哈希无序，不保证键序）；缺失键读取 panic（与数组越界一致）。
 */
#ifndef TPHP_MAP_H
#define TPHP_MAP_H

typedef struct TphpMapNode {
    struct TphpMapNode *next;
    union {
        int32_t i;
        String s;
    } k;
    char kv[]; /* 值槽（v_size 字节，8 字节对齐） */
} TphpMapNode;

typedef struct {
    int32_t length;
    int32_t capacity;    /* 桶数（2 的幂） */
    int32_t refcount;
    int32_t k_is_string; /* 1 = string 键 */
    int32_t v_size;      /* 值槽字节尺寸 */
    TphpMapNode **buckets;
} Map;

static void tphp_map_ref(Map *m)
{
    if (m) {
        m->refcount++;
    }
}

static void tphp_map_free(Map *m)
{
    for (int32_t i = 0; i < m->capacity; i++) {
        TphpMapNode *n = m->buckets[i];
        while (n) {
            TphpMapNode *next = n->next;
            free(n);
            n = next;
        }
    }
    free(m->buckets);
    free(m);
}

static void tphp_map_unref(Map *m)
{
    if (m && --m->refcount == 0) {
        tphp_map_free(m);
    }
}

static uint32_t tphp_map_hash_int(int32_t k)
{
    uint32_t x = (uint32_t)k;
    x ^= x >> 16;
    x *= 0x45d9f3bU;
    x ^= x >> 16;
    x *= 0x45d9f3bU;
    x ^= x >> 16;
    return x;
}

static uint32_t tphp_map_hash_str(const char *p, int32_t n)
{
    uint32_t h = 2166136261U; /* FNV-1a */
    for (int32_t i = 0; i < n; i++) {
        h ^= (uint8_t)p[i];
        h *= 16777619U;
    }
    return h;
}

/* 节点的键匹配；ik/sk/sl = 按 k_is_string 解释的待查键。 */
static int32_t tphp_map_key_eq(TphpMapNode *n, int32_t kis, int32_t ik, const char *sk, int32_t sl)
{
    if (kis) {
        return n->k.s.length == sl && memcmp(tphp_str_cref(&n->k.s), sk, (size_t)sl) == 0;
    }
    return n->k.i == ik;
}

/* 查找键所在节点；不存在返回 NULL。ik/sk/sl 同上。 */
static TphpMapNode *tphp_map_find(Map *m, int32_t ik, const char *sk, int32_t sl)
{
    uint32_t h = m->k_is_string ? tphp_map_hash_str(sk, sl) : tphp_map_hash_int(ik);
    TphpMapNode *n = m->buckets[h & (uint32_t)(m->capacity - 1)];
    while (n) {
        if (tphp_map_key_eq(n, m->k_is_string, ik, sk, sl)) {
            return n;
        }
        n = n->next;
    }
    return NULL;
}

/* 值槽指针（不存在则创建节点）；kv 尺寸 m->v_size 由 new 时约定。 */
static char *tphp_map_slot(Map *m, int32_t ik, const char *sk, int32_t sl)
{
    uint32_t h = m->k_is_string ? tphp_map_hash_str(sk, sl) : tphp_map_hash_int(ik);
    uint32_t idx = h & (uint32_t)(m->capacity - 1);
    TphpMapNode *n = m->buckets[idx];
    while (n) {
        if (tphp_map_key_eq(n, m->k_is_string, ik, sk, sl)) {
            return n->kv;
        }
        n = n->next;
    }
    n = (TphpMapNode *)malloc(sizeof(TphpMapNode) + (size_t)m->v_size);
    if (!n) {
        tphp_panic("out of memory");
    }
    if (m->k_is_string) {
        n->k.s = tphp_str_copy(sk, sl);
    } else {
        n->k.i = ik;
    }
    memset(n->kv, 0, (size_t)m->v_size);
    n->next = m->buckets[idx];
    m->buckets[idx] = n;
    m->length++;
    return n->kv;
}

static void tphp_map_grow(Map *m)
{
    int32_t cap = m->capacity * 2;
    TphpMapNode **b = (TphpMapNode **)calloc((size_t)cap, sizeof(TphpMapNode *));
    if (!b) {
        tphp_panic("out of memory");
    }
    for (int32_t i = 0; i < m->capacity; i++) {
        TphpMapNode *n = m->buckets[i];
        while (n) {
            TphpMapNode *next = n->next;
            uint32_t h = m->k_is_string
                ? tphp_map_hash_str(tphp_str_cref(&n->k.s), n->k.s.length)
                : tphp_map_hash_int(n->k.i);
            uint32_t idx = h & (uint32_t)(cap - 1);
            n->next = b[idx];
            b[idx] = n;
            n = next;
        }
    }
    free(m->buckets);
    m->buckets = b;
    m->capacity = cap;
}

static Map *tphp_map_new(int32_t v_size, int32_t k_is_string)
{
    Map *m = (Map *)malloc(sizeof(Map));
    if (!m) {
        tphp_panic("out of memory");
    }
    m->length = 0;
    m->capacity = 8;
    m->refcount = 1;
    m->k_is_string = k_is_string;
    m->v_size = v_size;
    m->buckets = (TphpMapNode **)calloc((size_t)m->capacity, sizeof(TphpMapNode *));
    if (!m->buckets) {
        tphp_panic("out of memory");
    }
    return m;
}

static int32_t tphp_map_len(Map *m)
{
    if (!m) {
        tphp_panic("len() applied to a null map");
    }
    return m->length;
}

/*
 * 读取：键不存在 panic（与数组越界一致，不可捕获）。
 * k 传适配缓冲地址（int32_t 或 String 结构），Gen 按 K 生成；out 至少 v_size 字节。
 */
static void tphp_map_get_raw(Map *m, void *k, int32_t kis, void *out)
{
    if (!m) {
        tphp_panic("map read on a null map");
    }
    int32_t ik = 0;
    const char *sk = NULL;
    int32_t sl = 0;
    if (m->k_is_string) {
        String *s = (String *)k;
        sk = tphp_str_cref(s);
        sl = s->length;
    } else {
        ik = *(int32_t *)k;
    }
    TphpMapNode *n = tphp_map_find(m, ik, sk, sl);
    if (!n) {
        tphp_panic("missing map key");
    }
    memcpy(out, n->kv, (size_t)m->v_size);
}

/* 写入：键不存在则插入（长度超半数扩容）；已存在则覆盖值槽。 */
static void tphp_map_set_raw(Map *m, void *k, int32_t kis, void *v)
{
    if (!m) {
        tphp_panic("map write on a null map");
    }
    int32_t ik = 0;
    const char *sk = NULL;
    int32_t sl = 0;
    if (m->k_is_string) {
        String *s = (String *)k;
        sk = tphp_str_cref(s);
        sl = s->length;
    } else {
        ik = *(int32_t *)k;
    }
    char *slot = tphp_map_slot(m, ik, sk, sl);
    memcpy(slot, v, (size_t)m->v_size);
    if (m->length * 2 > m->capacity) {
        tphp_map_grow(m);
    }
}

/* array_keys：键收集为 array<int> 或 array<string>（遍历入口，哈希无序）。 */
static Array *tphp_map_keys(Map *m)
{
    if (!m) {
        tphp_panic("array_keys() applied to a null map");
    }
    Array *a = m->k_is_string
        ? tphp_arr_new(sizeof(String), m->length, 0)
        : tphp_arr_new(sizeof(int32_t), m->length, 0);
    for (int32_t i = 0; i < m->capacity; i++) {
        for (TphpMapNode *n = m->buckets[i]; n; n = n->next) {
            if (m->k_is_string) {
                a = tphp_arr_push_str(a, n->k.s);
            } else {
                a = tphp_arr_push_int(a, n->k.i);
            }
        }
    }
    return a;
}

#endif /* TPHP_MAP_H */
