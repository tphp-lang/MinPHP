# 内置函数

内置函数刻意保持极小：标量输出、长度、类型检视，加上 phpc 的
string ↔ char* 与 C 内存所有权桥接。其余能力一律用用户代码或
phpc 直连 C 符号表达，不做杂七杂八的内置库。

## echo

输出一个或多个标量（不加换行、不返回值）。语句而非函数，无需括号。

| 参数名 | 类型 |
|---- | ---- |
| ...$values | `int`/`double`/`float`/`bool`/`string`（可变个数） |

例子:

```php
echo "n=", 42, "\n";
echo 1.5, " ", true, "\n";
```

## len

`string` 长度 / `array` 元素数 -> `int`

| 参数名 | 类型 |
|---- | ---- |
| $x | `string`/`array<T>` |

例子:

```php
len("hello"); // 5
len([0,1,2]); // 3
```

## implode

拼接 `array<string>` 为单个 string（分隔符插在元素之间）-> `string`。
两次遍历、一次分配，O(n)——**字符串累加 `$s = $s . x` 是 O(n²)（每次全量
拷贝），集合型拼接一律用它**。

| 参数名 | 类型 |
|---- | ---- |
| $sep | `string` |
| $parts | `array<string>` |

例子:

```php
array<string> $parts = [];
$parts[] = "a";
$parts[] = "b";
$parts[] = "c";
implode("-", $parts); // "a-b-c"
```

## array_keys

`map<K,V>` 的键收集为 `array<K>` -> `array<K>`。**map 的遍历入口**：
配合 foreach + 下标读使用（哈希无序，不保证键序）。

| 参数名 | 类型 |
|---- | ---- |
| $m | `map<K,V>` |

例子:

```php
map<string,int> $score = ["a" => 1, "b" => 2];
int $total = 0;
foreach (array_keys($score) as $k) {
    $total = $total + $score[$k];
}
```


## var_dump

检视值的类型与内容（带换行）-> `void`

| 参数名 | 类型 |
|---- | ---- |
| $x | `int`/`double`/`float`/`bool`/`string`/`array<T>`/类/接口 |

输出格式对标量是 `类型(值)`，字符串带长度，数组递归列出元素，
对象/接口只报类型名。

例子:

```php
var_dump(42);          // int(42)
var_dump(1.5);         // double(1.5)
var_dump("hello");     // string(5) "hello"
var_dump([1, 2]);      // array(2) [1, 2]
var_dump([[1], [2]]);  // array(2) [array(1) [1], array(1) [2]]
var_dump(new Cat());   // object(Cat)
```

## c_str

string → char*（借用，零拷贝）-> `c.char*`

| 参数名 | 类型 |
|---- | ---- |
| $s | `string` |

字符串池与进程同寿，返回指针只作只读借用（多数 C 形参语义如此）。

例子:

```php
c->printf(c_str("n=%d\n"), 42);
c->puts(c_str("hi"));
```

## php_str

char* → string（深拷贝，安全默认）-> `string`

| 参数名 | 类型 |
|---- | ---- |
| $p | `c.char*`/`c.ptr`/CVAL（c-> 调用返回的指针） |

把 C 侧字符数组复制进字符串池，C 侧后续 free / 修改互不影响；
NULL 拷贝为空串。

例子:

```php
string $line = php_str(c->fgets(cbuf(128), 128, $fp));
```

## php_str_ref

char* → string（零拷贝借用）-> `string`

| 参数名 | 类型 |
|---- | ---- |
| $p | `c.char*`/`c.ptr`/CVAL |

不复制，直接引用 C 侧内存。仅用于生命周期确定的静态数据
（如 `c->getenv`、字面量）；对短命内存使用会悬垂。

例子:

```php
string $path = php_str_ref(c->getenv(c_str("HOME")));
```

## cbuf

分配 n 字节并登记所有权 -> `CVAL`

| 参数名 | 类型 |
|---- | ---- |
| $n | `int`（字节数） |

分配 + 登记一体：编译器在本函数所有出口自动 free，开发者不写
`free`。分配失败 panic。

例子:

```php
c.ptr $buf = cbuf(64);
c->memset($buf, 0, 64);
```

## c_own

接管 C 分配的内存（登记所有权）-> `CVAL`

| 参数名 | 类型 |
|---- | ---- |
| $p | 指针（`c->` 调用返回值 / `c.ptr`） |

登记后由编译器在函数所有出口自动 free。同一指针不可 `c_own`
两次（双重释放）；C 侧长期持有的指针不要登记。

例子:

```php
c.ptr $p = c_own(c->malloc(32));   // 函数出口自动 free
c.ptr $h = c->fopen(c_str("a.txt"), c_str("r")); // 借用语义，不登记
```

---

参见 `doc/phpc.md`：phpc 完整规范（#include / #flag / #struct、
c-> 直连、cstruct、平台条件编译）。
