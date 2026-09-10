/*
 * 对象：编译期单态化的 C struct。头三字段为引用计数、vtable、析构指针。
 * 引用计数归零时先调用 dtor 释放堆字段，再释放对象本身。
 */
#ifndef TPHP_OBJECT_H
#define TPHP_OBJECT_H

#define TPHP_OBJECT_HEAD int32_t refcount; void *vt; void (*dtor)(void *);

/* ------------------------------------------------- 内存统计（--mem-stats） */

static int64_t tphp_mem_arr_allocs = 0;
static int64_t tphp_mem_arr_frees = 0;
static int64_t tphp_mem_obj_allocs = 0;
static int64_t tphp_mem_obj_frees = 0;
static int64_t tphp_mem_cmem_allocs = 0;
static int64_t tphp_mem_cmem_frees = 0;
static bool tphp_mem_stats_on = false;

static void tphp_mem_track_array(int delta) { tphp_mem_arr_allocs += delta > 0; tphp_mem_arr_frees += delta < 0; }
static void tphp_mem_track_object(int delta) { tphp_mem_obj_allocs += delta > 0; tphp_mem_obj_frees += delta < 0; }
static void tphp_mem_track_cmem(int delta) { tphp_mem_cmem_allocs += delta > 0; tphp_mem_cmem_frees += delta < 0; }

typedef struct {
    int32_t refcount;
    void *vt;
    void (*dtor)(void *);
} TphpObjHead;

/*
 * vtable 头部：类型元信息（instanceof 判定用）。每个类的 vtable 结构首个字段，
 * 方法指针随其后——所有 vtable 同前缀，父类指针转型后方法偏移依然一致。
 *
 * chain  ：祖先类 id 链（自身 → 父 → 祖父 …，-1 结尾）
 * ifaces ：实现的全部接口 id（含经父类/接口继承的闭包，-1 结尾）
 *
 * 判定与 PHP 同构（zend_operators.c: instanceof_function_slow 的父链遍历与
 * 接口数组线性查），仅指针/整数比较，无哈希与反射。
 */
typedef struct {
    const int32_t *chain;
    const int32_t *ifaces;
} TphpVTHead;

static bool tphp_instanceof_class(void *obj, int32_t class_id)
{
    if (!obj) {
        return false; // null instanceof X = false（PHP 语义）
    }
    const TphpVTHead *h = (const TphpVTHead *)((TphpObjHead *)obj)->vt;
    if (!h || !h->chain) {
        return false;
    }
    for (int32_t i = 0; h->chain[i] >= 0; i++) {
        if (h->chain[i] == class_id) {
            return true;
        }
    }
    return false;
}

static bool tphp_instanceof_iface(void *obj, int32_t iface_id)
{
    if (!obj) {
        return false;
    }
    const TphpVTHead *h = (const TphpVTHead *)((TphpObjHead *)obj)->vt;
    if (!h || !h->ifaces) {
        return false;
    }
    for (int32_t i = 0; h->ifaces[i] >= 0; i++) {
        if (h->ifaces[i] == iface_id) {
            return true;
        }
    }
    return false;
}

static void *tphp_object_alloc(size_t size, void *vt, void (*dtor)(void *))
{
    TphpObjHead *o = (TphpObjHead *)calloc(1, size);
    if (!o) {
        tphp_panic("out of memory");
    }
    o->refcount = 1;
    o->vt = vt;
    o->dtor = dtor;
    tphp_mem_track_object(1);
    return o;
}

/* 枚举 case 等进程期单例：巨量初始引用（借用者无法耗尽）、不进内存统计，
 * 生命周期 = 进程（doc/grammar.md 枚举类一节）。 */
static void *tphp_object_alloc_static(size_t size, void *vt, void (*dtor)(void *))
{
    TphpObjHead *o = (TphpObjHead *)calloc(1, size);
    if (!o) {
        tphp_panic("out of memory");
    }
    o->refcount = 1 << 30;
    o->vt = vt;
    o->dtor = dtor;
    return o;
}

static void tphp_object_ref(void *obj)
{
    if (obj) {
        ((TphpObjHead *)obj)->refcount++;
    }
}

static void tphp_object_unref(void *obj)
{
    if (obj && --((TphpObjHead *)obj)->refcount == 0) {
        void (*dtor)(void *) = ((TphpObjHead *)obj)->dtor;
        if (dtor) {
            dtor(obj); /* 先释放字段，再释放对象本身 */
        }
        free(obj);
        tphp_mem_track_object(-1);
    }
}

/* 经 vtable 分发方法调用：TPHP_VT(obj, tphp_vt_Animal)->speak(obj) */
#define TPHP_VT(obj, vttype) ((const vttype *)(obj)->vt)

/*
 * 接口胖指针（Go itab 风格）：所有接口共用此 struct。
 * obj 为对象指针；itab 指向 (类, 接口) 对应的静态方法表，
 * 调用侧按静态接口类型转型为 tphp_itab_<I>*。
 */
typedef struct {
    void *obj;
    const void *itab;
} TphpIface;

static void tphp_mem_report(void)
{
    if (!tphp_mem_stats_on) {
        return;
    }
    int64_t leaks = (tphp_mem_arr_allocs - tphp_mem_arr_frees)
        + (tphp_mem_obj_allocs - tphp_mem_obj_frees)
        + (tphp_mem_cmem_allocs - tphp_mem_cmem_frees);
    printf("mem: arrays %lld/%lld objects %lld/%lld cmem %lld/%lld leaks=%lld\n",
           (long long)tphp_mem_arr_allocs, (long long)tphp_mem_arr_frees,
           (long long)tphp_mem_obj_allocs, (long long)tphp_mem_obj_frees,
           (long long)tphp_mem_cmem_allocs, (long long)tphp_mem_cmem_frees,
           (long long)leaks);
    fflush(stdout);
}

#endif /* TPHP_OBJECT_H */
