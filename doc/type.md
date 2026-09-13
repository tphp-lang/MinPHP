# 类型

## 可自动推导

首次赋值推导并定死类型：`$x = 5;` → int（此后不可变）。下表类型均可由右侧初始值推导。

| tphp 类型 | C 类型 | 说明 |
| ---- | ---- | ---- |
| int | int32_t | 32 位有符号整数 |
| float | double | 64位浮点（52位尾数）IEEE-754-2008 binary64（PHP float 语义；`double` 为别名） |
| string | String | 不可变值类型；SSO ≤23 字节内联，超限走 bump 池 |
| bool | bool | true/false |
| array\<T\> | Array* | 泛型数组（引用类型，见下文） |
| map\<K,V\> | Map* | 关联数组（K 限 int/string、V 限标量与 string；引用类型，见下文） |
| callable | Callable | 闭包 / C 函数指针（双字 {fn, env}；env 为捕获环境，doc/closure.md） |
| void | void | 没有值的数据类型，通常用于函数返回值（仅返回类型，不参与推导） |
| 类类型 | tphp_class_X* | 编译期单态化为 C struct，变量为指针，可为 null |
| 枚举类型 | tphp_class_X* | case 单例集（对象引用语义，见下文枚举一节） |
| 接口类型 | TphpIface | Go itab 风格胖指针（对象指针 + 方法表），可为 null |
| object | TphpObjHead * | 裸对象指针（任意类/接口实例的擦除型）；无成员布局，须先 `instanceof` 收窄回具体类（见下文 object 一节） |
| null | — | 仅可赋给 array / callable / 类类型 / 接口类型 / object（映射 NULL） |

## 不可自动推导

C 侧类型（位宽/ABI 由程序员负责）——首次赋值推断被编译器拒绝，必须显式声明：
`c.i32 $n = 5;`

| tphp 类型 | C 类型 | 说明 |
| ---- | ---- | ---- |
| c.f32 | float | 32位浮点（23位尾数）IEEE-754-2008 binary32 |
| c.i8 | int8_t | 带符号的8位整数 |
| c.u8 | uint8_t | 无符号8位整数 |
| c.i16 | int16_t | 带符号的16位整数 |
| c.u16 | uint16_t | 无符号16位整数 |
| c.i32 | int32_t | 带符号的32位整数 |
| c.u32 | uint32_t | 无符号32位整数 |
| c.i64 | int64_t | 带符号的64位整数 |
| c.u64 | uint64_t | 无符号的64位整数 |
| c.i128 | __int128 | 带符号的128位整数（后端支持度因编译器而异） |
| c.u128 | unsigned __int128 | 无符号的128位整数（同上） |
| c.char | char | 用于与C语言的ABI兼容性 |
| c.short | short | 同上 |
| c.ushort | unsigned short | 同上 |
| c.uint | unsigned int | 同上 |
| c.long | long | 同上 |
| c.ulong | unsigned long | 同上 |
| c.longlong | long long | 同上 |
| c.ulonglong | unsigned long long | 同上 |
| c.longdouble | long double | 同上 |
| c.f16 | _Float16 | 16位浮点（10位尾数）IEEE-754-2008 binary16（后端支持度因编译器而异） |
| c.f80 | long double | 80位浮点（64位尾数）80位扩展精度 |
| c.f128 | _Float128 | 128位浮点（112位尾数）IEEE-754-2008 binary128（同上） |
| c.f64 | double | 64位浮点的 C 侧别名（与 float 同型） |
| c.ptr | void* | 指针类型 |
| cstruct | 见 #struct | phpc 的 C 结构体值类型（doc/phpc.md） |

## object（裸对象指针）

`object` 是任意对象实例的**擦除型**：C 表示复用客户端结构体的公共头，即裸对象指针 `TphpObjHead *`。
它既不携带方法集写头，也不含字段布局。

- **类 → `object`**：零成本指针上转 `(TphpObjHead *)(p)`
- **接口 → `object`**：读接口胖指针 `TphpIface{obj, itab}` 的 `.obj` 字段
- **引用计数**：`object` 参与 RC，可作形参 / 返回 / 字段 / 局部 / `array<T>` / `map<K,V>` / `catch` 的类型
- **禁止直接成员访问**：`$o->m()` / `$o->p` 编译报错，须先用 `instanceof` 收窄回**具体类**
  （见 doc/grammar.md 的流敏感收窄一节）
- **收窄边界**：只能收窄回具体类；`object` → 接口需运行期 itab 查表，**不做**

`array<object>` 的元素槽为对象指针（8 字节），push / 读 / 写复用对象通路
（`tphp_arr_push_obj` / `tphp_arr_get_obj` / `tphp_arr_set_obj`），数组释放时逐元素 `unref`。

## 字符串

不可变值类型：赋值即结构体拷贝，内容永不修改，因此无需引用计数。
`is_local` 区分 SSO 内联与堆分配；`is_lit` 标记 .rodata 字面量（永不释放）。

```c
typedef struct {
    union {
        char *data;
        char  local[STR_SSO_MAX+1]; // SSO inline buffer (when is_local)
    } u;
    int   length;
    bool  is_local;
    bool  is_lit;                // true for .rodata string literals — never free()
} String;
```

## 数组

纯列表：0 起始连续存储，仅整数下标，越界即运行时报错。
没有 PHP 的 map 语义（无字符串键、无稀疏索引）——关联数组见下文 `map<K,V>` 一节。
所有 `array<T>` 共享同一个泛型结构（voidptr + element_size 方案），
元素访问由编译器按静态元素类型生成带越界检查的辅助函数调用。

```c
typedef struct {
    int32_t length;
    int32_t capacity;
    int32_t refcount;
    int32_t element_size;
    int32_t elem_flags;     // 0=裸值 1=元素是 Array* 2=元素是对象指针（释放嵌套引用用）
    void *data;
} Array;
```

引用语义：赋值共享同一底层数组（指针拷贝）。

## map（关联数组）

`map<K,V>`：键限 `int` / `string`，值限**标量与 `string`**（第一版边界）。
哈希表（链地址法 + FNV-1a / 整数混合哈希），引用语义（赋值共享底层表）。

```c
typedef struct TphpMapNode {
    struct TphpMapNode *next;
    union { int32_t i; String s; } k;
    char kv[];              // 值槽（v_size 字节，Gen 按 V 传入）
} TphpMapNode;

typedef struct {
    int32_t length;
    int32_t capacity;       // 桶数（2 的幂，超半数扩容）
    int32_t refcount;
    int32_t k_is_string;
    int32_t v_size;
    TphpMapNode **buckets;
} Map;
```

操作：下标读写 `$m["k"]`（写即插入、重复写覆盖）、`len($m)`、
`array_keys($m)`（键收集为 `array<K>`，哈希无序不保证键序）、
全键字面量 `["a" => 1]`、空字面量 `[]` 借目标 map 类型。

**读取缺失键 panic**（不可捕获，与数组越界一致）。
边界（Checker 明确拒绝）：map 不作类字段 / 数组元素 / 嵌套 map；堆值 V 待内存模型后续版本。

## 枚举

`enum Name: int|string`（backed）或 `enum Name`（纯）声明 case 单例集——
case 是**唯一的实例**，`==` 即指针恒等；可带方法（`$this` 可用）与接口实现。
完整语义见 doc/grammar.md 枚举类一节。

```c
typedef struct {
    TPHP_OBJECT_HEAD
    String name;         /* case 名（全枚举可读） */
    String value;        /* 仅 backed 枚举；int backing 为 int32_t */
} tphp_class_Suit;
```

- **单例生命周期**：case 对象经 `tphp_object_alloc_static` 分配（巨量初始引用、
  不进内存统计）——进程期常量数据，借用/赋值的引用计数插桩对它无副作用
- **只读成员**：`->name` / `->value`（backed）编译期拦截写入；纯枚举无 `value`
- **合成静态方法**：`cases(): array<Name>`（按声明序）、`from(v)`（无效值抛错）、
  `tryFrom(v)`（无效值返回 null）——仅 backed 枚举有 from/tryFrom
- **无自动赋值**：backed case 必须显式赋值且值唯一（编译期查重）——与 `#enum`
  （C 枚举常量集，缺省 = 前值 + 1）明确分工

## 内存模型（v0.1）

- 字符串堆内存来自 bump 池，随进程回收；
- 数组 / 对象分配后不主动回收（结构体已含 refcount 字段，运行时已提供 ref/unref 基础设施，后续版本接入自动回收）。

## 类型规则

- 变量显式声明类型，编译期定死：`int $x = 1;`
- 隐式转换只允许数值宽化：int → float（i32 → f64）、c.f32 → float；其余一律显式强转 `(t)$v`（唯一例外：浮点字面量可直接赋给 c.f32——编译期取值，不引入计算误差；float 变量/表达式赋给 c.f32 会损失精度，必须显式 `(c.f32)` 强转）
- `int / int` 按 C 语义整除；`**` 右结合且优先级高于一元负号
- 条件（if / while / for / 三元 / 逻辑运算）要求严格的 bool
- `==` 即恒等（类型编译期固定，无需 `===`）
