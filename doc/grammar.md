# 语法规范

强类型 PHP 子集。整体按 C 的语义模型设计（静态类型、值/指针二分、
int 整除、严格 bool 条件），表面语法保持 PHP 风格（`$` 变量、
`function` / `->` / `foreach ... as`、`.` 拼接）。

## 词法

- `<?php` 开标签任意文件可选；`?>` 表示源码结束（其后内容忽略）
- 注释：`// ...`、`# ...`、`/* ... */`
- 行首 `#[export("C名")]` 注解：整体为一个 token，仅全局函数（见 doc/phpc.md）；
  行首其余 `#` 为 phpc 指令（`#include` / `#flag` / `#struct` / `#if`）或行注释
- 标识符：`[A-Za-z_][A-Za-z0-9_]*`；变量：`$` + 标识符
- 数字：十进制 / `0x` 十六进制 / `0b` 二进制 / `0o` 八进制；浮点含小数与指数部分
- 字符串：单引号（仅 `\\` `\'` 转义）与双引号（`\n \r \t \v \f \0 \\ \$ \"` +
  `$var` / `{$expr}` 插值）
- Heredoc / Nowdoc：`<<<EOT`（内容自下一行起到缩进 `EOT` 行，**支持插值**，等价双引号）；
  `<<<'EOT'`（nowdoc，原文输出，无转义无插值，等价单引号）；结束行的 `;` 归还语句层
- 不支持：`===` `!==` `<=>` `??` `?->` `@` `&$引用` `$$可变变量`

## 文法（EBNF 简写）

```ebnf
program     = [ "<?php" ], [ nsdecl ] , { directive | useDecl | toplevel } ;
directive   = "#include", ("<", path, ">" | "\"", path, "\"") 
            | "#flag", { arg } 
            | "#struct", IDENT, "{", { type, IDENT, ";" }, "}"
            | "#if", cond, { directive | toplevel }, { "#elif", cond, ... }, ["#else", ...], "#endif" ;
nsdecl      = "namespace", qualifiedName, ";" ;
useDecl     = "use", [ "function" | "const" ], qualifiedName, [ "as", IDENT ], ";"
            | "use", [ "function" | "const" ], qualifiedName, "\\",
              "{", useItem, { ",", useItem }, [ "," ], "}", ";" ;
useItem     = [ "function" | "const" ], qualifiedName, [ "as", IDENT ] ;
toplevel    = funcdecl | classdecl | interdecl | constdecl | traitdecl ;
traitdecl   = "trait", IDENT, "{", { member | usetrait }, "}" ;
usetrait    = "use", qualifiedName, { ",", qualifiedName },
              [ "{", { ( qualifiedName, "::", IDENT, "insteadof", qualifiedName
                       | qualifiedName, "::", IDENT, "as", [vis], [IDENT] ), ";" }, "}" ] ;

constdecl   = "const", [ type ], IDENT, "=", literal, ";" ;
interdecl   = "interface", IDENT, [ "extends", IDENT, { ",", IDENT } ],
              "{", { imethod }, "}" ;
imethod     = [ "public" ], "function", IDENT, "(", [params], ")", [":", type], ";" ;

funcdecl    = "function", ident, "(", [params], ")", [":", type], block ;
classdecl   = [ "final" | "abstract" ], "class", ident, [ "extends", ident ],
              [ "implements", IDENT, { ",", IDENT } ], "{", { member }, "}" ;
member      = {vis|"static"|"abstract"|"final"}, ( method | prop | classconst ) ;
vis         = "public" | "private" | "protected" ;
method      = "function", ident, "(", [params], ")", [":", type], ( block | ";" ) ;  (* abstract → ";" *)
prop        = type, var, ["=", literal], ";" ;
classconst  = "const", type, IDENT, "=", literal, ";" ;   (* 类型必填 *)
params      = param, { ",", param } ;
param       = type, var, ["=", literal] ;

type        = "int" | "float" | "double" | "bool" | "string" | "null"
              ; float = double = 64位（PHP float 语义）；32 位浮点用 c.f32
            | "callable" | "void"
            | "array", "<", type, ">"
            | "map", "<", type, ",", type, ">"   (* K 限 int/string；哈希无序 *)
            | ident           (* 类名 / 接口名 *)
            | ident, ".", ident (* c.* 别名，如 c.i64 *) ;

block       = "{", { stmt }, "}" ;

stmt        = if | while | dowhile | for | foreach | switch
            | "break" ";" | "continue" ";"
            | "return", [expr], ";"
            | "throw", expr, ";"
            | "const", [ type ], IDENT, "=", literal, ";"   (* 函数内常量 *)
            | "echo", expr, { ",", expr }, ";"
            | block | localdecl | expr, ";" ;

if          = "if", "(", expr, ")", block, { "elseif", "(", expr, ")", block }, ["else", block] ;
while       = "while", "(", expr, ")", block ;
dowhile     = "do", block, "while", "(", expr, ")", ";" ;
for         = "for", "(", [forinit], ";", [expr], ";", [expr], ")", block ;
foreach     = "foreach", "(", expr, "as", var, [ "=>", var ], ")", block ;
switch      = "switch", "(", expr, ")", "{", { case }, "}" ;
case        = ( "case", expr | "default" ), ":", { stmt } ;
localdecl   = type, var, ["=", expr], ";" ;   (* 显式声明；PHP 类型可省略（$x = 5; 自动推导））

expr        = assign ;
assign      = pipe, [ assignop, assign ] ;           (* 右结合 *)
pipe        = ternary, { "|>", ternary } ;           (* 左结合；脱糖为调用首参插入 *)
assignop    = "=" | "+=" | "-=" | "*=" | "/=" | "%=" | "**=" | ".="
            | "&=" | "|=" | "^=" | "<<=" | ">>=" ;
ternary     = oror, ["?", ternary, ":", ternary] ;
oror        = andand, { "||", andand } ;
andand      = bitor, { "&&", bitor } ;
bitor       = bitxor, { "|", bitxor } ;
bitxor      = bitand, { "^", bitand } ;
bitand      = equality, { "&", equality } ;
equality    = rel, { ("==" | "!="), rel } ;
rel         = shift, { ("<" | ">" | "<=" | ">="), shift } ;
shift       = add, { ("<<" | ">>"), add } ;
add         = mul, { ("+" | "-" | "."), mul } ;
mul         = inst, { ("*" | "/" | "%"), inst } ;
inst        = unary, { "instanceof", (ident | qualifiedName) } ;  (* 右侧须为类型名 *)
unary       = ("-" | "+" | "!" | "~" | "++" | "--"), unary | power ;
power       = postfix, [ "**", unary ] ;             (* 右结合，高于一元负号 *)
postfix     = primary, { postfixtail } ;
postfixtail = "[", [expr], "]" | "->", ident, ["(", [args], ")"]
            | var, "(", [args], ")"                    (* 闭包调用 $f(...) *)
            | "c", "->", IDENT, ["(", [args], ")"]   (* phpc 直连 *)
            | "::", ( var | IDENT, ["(", [args], ")"] )   (* ::$prop / ::CONST / ::method() *)
            | "or", block                                 (* 任意可失败调用后：函数 / 闭包 /
                                                             方法 / 静态 / 构造器 new C() *)
            | "++" | "--" ;
primary     = literal | var | "this" | IDENT        (* 常量引用 / self:: 前半 *)
            | "[" [args] "]"
            | "new", ident, "(", [args], ")"
            | "(", [casttype], expr, ")"
            | closure                                              (* 闭包字面量 *)
            | "fn", "(", [params], ")", [":", type], "=>", ( expr | block ) ;
closure     = "function", "(", [params], ")", [ "use", "(", capture-list, ")" ],
              [":", type], block ;
capture-list = [ "&" ], var, { ",", [ "&" ], var } ;   (* & = 按引用捕获（堆盒子） *)
casttype    = "int" | "float" | "double" | "bool" | "string" | c*别名 ;
literal     = int | float | string | "true" | "false" | "null" ;
```

## 语义要点

### 入口与多文件

`class Main` 的构造器可声明为无参或 `(int $argc, array<string> $argv)`——后者由
`main` 传入命令行参数（旧版 tphp 惯例）：

```php
class Main
{
    public function __construct(int $argc, array<string> $argv)
    {
        // $argv[0] 为程序路径
    }

    public function main(): void { /* ... */ }
}
```

类方法返回类型可写 `self`（= 声明类，链式 API）：`public function add(...): self { return $this; }`。

```php
class Main
{
    public function main(): void { /* ... */ }
}
```

- 入口 = 含全局 `class Main` 的文件（最多一个）；命令行第一个参数仅用于输出命名
- `<?php` 开标签任意文件可选；`?>` 为源码结束符，其后内容忽略
- 多文件：`php main.php main.php lib.php ...`——所有文件合并为单翻译单元，
  函数/类/常量跨文件免 import 直接可见；辅助文件先解析、入口最后解析
- 顶层不允许游离语句，只允许 `namespace`（首条、每文件一个）/ `use`（声明前）/
  `function` / `class` / `interface` / `const` 声明

### 命名空间

- `namespace A\B;` 语句式，须为文件第一条声明，每文件最多一个，支持多层名
- 符号以全限定名注册（`Geom\Rect`）；同命名空间跨文件即同一作用域，重复定义报编译错
- 解析：裸名先查当前命名空间（use 导入表优先），函数/常量再回退全局（PHP 语义），类不回退；
  限定名与全限定名（前导 `\`）可在任何位置直接使用
- `use` 支持 `as` 别名、`function` / `const` 前缀与分组语法（每文件独立）
- 生成 C 符号内联命名空间：`Geom\Rect` → `tphp_class_Geom_Rect`、
  `Geom\PRECISION` → `TPHP_CONST_GEOM_PRECISION`（编译器检测 C 符号冲突）
- `class Main` 必须在全局命名空间

### 常量

- 顶层：`const [TYPE] NAME = 字面量;`——类型注解可选（缺省从字面量推断），
  值仅限标量字面量与一元 `-` / `~`（不支持跨常量引用，与旧版一致）
- 类常量：`[vis] const TYPE NAME = 字面量;`——**类型必填**；
  `ClassName::NAME` / `self::NAME` / `parent::NAME` 访问，可见性编译期检查
- 函数内：`const [TYPE] NAME = 字面量;`——作用域内不可变
- C 生成：`#define TPHP_CONST_<大写>` / `#define TPHP_CONST_<类>_<大写>`（与旧版一致）

### 接口

```php
interface Shape
{
    public function area(): double;
}
class Circle implements Shape { /* 必须实现全部方法，签名精确一致 */ }
```

- 接口只含方法签名（仅 public），可 `extends` 多个父接口
- 类可实现多个接口；实现校验：方法存在且签名精确匹配
- 接口变量是 Go itab 风格胖指针（对象指针 + 方法表）；可为 null
- 类 → 接口赋值/传参/返回自动包装；`array<接口>` 合法
- 接口不可实例化、没有属性；不做运行时类型判断（无 instanceof）

### 管道操作符（|>）

`x |> f(a, b)` 是 `f(x, a, b)` 的语法糖——解析期把左操作数插入右侧调用的
第一个参数，无运行时开销，类型检查、`or` 错误传播、引用计数与直接调用一致。

- 左结合可链式：`x |> f() |> g()` = `g(f(x))`
- 右侧必须是 调用 / 方法调用 / 静态调用 / `c->` 直连调用；
  方法接收者不得含调用（避免管道值被求值两次）
- **占位符 `...`**：`x |> f(a, ...)` 中的 `...` 标记左值的插入位置（任意参数位，
  仅一次）；无占位符时默认插入首参。`...` 仅在管道右侧的调用参数中合法：
  `x |> power(2, ...)` = `power(2, x)`
- 可与 `or` 块组合：`$x |> parse() or { ... }`
- 优先级介于赋值与三元之间：`$y = $x |> f();` 无需括号；
  三元分支内使用管道需加括号

### 枚举类（enum）

PHP 8.1 语义的枚举类：case 是**单例对象**（`==` 即恒等），可带方法（`$this` 可用）、
实现接口；backed 枚举（`: int` / `: string`）有只读 `->value`，**无自动赋值**
（所有 case 必须显式赋值且值唯一）；`->name` 全枚举只读可用；
合成静态方法 `cases()` / `from()`（无效值抛错）/ `tryFrom()`（无效值返回 null）。
禁止：`new`、属性声明、`__construct`/`__destruct`。

```php
enum Suit: string implements HasColor
{
    case Hearts = 'H';
    case Spades = 'S';

    public function color(): string
    {
        return $this == Suit::Hearts ? "red" : "black";
    }
}

Suit::Hearts->value;     // 'H'
Suit::from('S');         // case 单例
Suit::tryFrom('X');      // null
Suit::cases();           // array<Suit> 按声明序
```

与 `#enum`（C 枚举常量集，值语义）分工：需要 case 行为/接口/恒等对象时用枚举类，
与 C 头文件枚举交互时用 `c->成员` 或 `#enum`。

### 错误处理（or）

```php
function divide(int $a, int $b): int
{
    if ($b == 0) {
        throw "division by zero";
    }
    return $a / $b;
}
int $v = divide(1, 0) or { -1 };          // 出错取默认值
int $w = divide(1, 0) or { echo err; 0 }; // err = 错误消息（string，只读）
```

- `throw <string 表达式>;` 抛出；错误自动沿调用链上浮（各层立即返回零值），
  直到最近的 `调用 or { 块 }`；全程无 or {} 时顶层打印 `Uncaught error: <消息>`，
  退出码 1
- **可带 `or {}` 的调用形式（全部）**：全局函数 `f()`、变量闭包 `$f()`、
  方法调用 `$obj->m()`、静态调用 `C::m()`、构造器 `new C()`——
  即任何可能触达 `throw` 的调用。例：
  ```php
  int $r = $d->hello() or { -1; };              // 捕获方法内异常
  Demo $e = new Demo(-1) or { new Demo(9); };   // 捕获构造器异常（构造失败时对象未建成）
  int $h = $cb(-1) or { 42; };                  // 捕获闭包内异常
  ```
- **or 管住整条链**：链式调用中任一环失败 → 立即短路进 or 块，
  后续环节**不再求值**（等价于 `try { 整条语句 }`）：
  ```php
  int $v = $a->parse()->value() or { -1; }   // parse() 失败时 value() 不会被调用
  ```
  实现：or 上下文中调用不做错误传播，改为"已有挂起错误则跳过本次调用"，
  表达式以零值收尾，由 or 块统一收口；对应的 null 检查也以 `!tphp_err_has()` 为前提
  （故链中间失败不会因对零值解引用而 panic）
- or 块：值上下文取块内最后一条表达式语句的值；块内可用 `return` / `break` / `continue`；
  块内语句正常带分号
- 无任何签名注解——不追踪可失败性，任何调用都可能带 or {}
- 错误处理无 `!`/`?` 签名标注；数组越界、空指针解引用等仍是致命 panic（不可捕获）

### 运算符语义（与 PHP 的差异）

| 表达式 | 语义 |
| ---- | ---- |
| `7 / 2` | `3`——int 相除按 C 整除（PHP 得 3.5） |
| `int % int` | C 取模（符号随被除数） |
| `2 ** 3 ** 2` | `512`——右结合；`-2 ** 2` 为 `-4`（与 PHP 一致） |
| `"a" . 1` | 标量自动转 string 后拼接 |
| `if ($n)` | 编译错误——条件必须是 bool |
| `$a == $b` | 编译期已知类型，恒等比较（接口比较 .obj 指针；数组不支持 `==`） |
| `$x instanceof C` | 类沿祖先链、接口查 id 集（判定与 PHP 同构）；静态类型可判定时编译期折叠；右侧不支持动态类名 |

### 类

- 单继承 `extends` + `implements` 多接口；字段平铺单态化为 C struct
  （继承字段前缀布局，指针可安全上溯）
- 方法经 vtable 分发；无子类的类直接直调
- 允许方法重写（签名一致）；**不允许属性遮蔽**
- 支持 `public / private / protected`（编译期检查）、静态属性与方法、
  `self::` / `parent::`、`__construct`
- `final class` 不可继承；`final` 方法不可重写（父链检查）
- `abstract class` 不可实例化；`abstract` 方法以 `;` 结束（无函数体），
  非抽象子类必须实现全部抽象方法（沿父链判定"最近声明"）；
  抽象类可含具体方法，抽象方法不得为 private；抽象构造器 / 析构器合法
  （但不可显式 `parent::` 调用——编译期拦截，PHP 为运行时错误）
- `final const`（PHP 8.1+）/ `final` 属性（PHP 8.4+）语法可用：本语言常量
  不可被子类重定义、属性禁止遮蔽，语义天然满足（PHP 侧分别对应
  `zend_inheritance.c` 的 override final constant / property 检查）；
  `private final const` 按 PHP 报编译错（对其它类不可见）
- `final private` 方法警告冗余（对齐 `zend_compile.c:8259`），构造函数豁免
- **与 PHP 的时机差异**（源码+实测确认）：
  - 抽象类实例化（PHP `zend_API.c:1831`）与调用抽象方法（PHP `zend_object_handlers.c:1934`）
    在 PHP 是**运行时 Error（`try/catch` 可捕获）**，本语言在**编译期**拒绝——AOT 下更早发现
  - 其余检查（继承 final 类/重写 final 方法、抽象方法 private/有体、含抽象方法未标
    abstract、非抽象子类未实现抽象方法）PHP 均为编译期错误（`E_COMPILE_ERROR`），
    本语言一致
- `instanceof`：类/子类/接口判定（元信息挂在 vtable 头部：祖先 id 链 + 接口 id 集）；
  静态类型足以判定时编译期折叠为常量；左侧须为对象类型；右侧不支持动态类名
- 不支持：匿名类 / 魔术方法（`__construct` / `__destruct` 除外）

### trait

PHP 语义同构的**编译期展开**（对齐 `zend_inheritance.c` 的 `zend_traits_copy_functions`）：
trait 的方法/属性/常量在使用类处**单态化复制**（C 符号属使用类），**零运行时痕迹**。

- `use A, B;`（可多个）与嵌套 `use`（trait 用 trait）
- **类自身成员优先**于 trait 成员（PHP：`members from the current class override trait methods`）
- 两个 trait 的同名非抽象方法冲突 → 编译错误；用 `A::m insteadof B;` 选择实现、
  `B::m as bm;` 取别名（或 `as private` 改可见性）解决；抽象方法不冲突
- trait 可含抽象方法（使用类必须实现，规则同抽象类）；`self::` 指使用类
- trait 不可实例化、不可继承、不可用于 `instanceof`（PHP 实测恒 false，本语言编译期报错）
- 循环 use 静默截断（防挂死；PHP 报错）

### 数组

- `array<T>` 纯列表：0 基、连续、仅 int 下标；`$a[] = $v` 追加
- 越界访问运行时报错中止
- 引用语义（赋值共享底层数组）
- 字面量 `[1, 2, 3]`；元素类型自动统合，或借用目标声明类型（`array<Animal> $zoo = [new Cat("k")]`）
- 无键值语义（关联数组见下节 map）；不支持解构、spread、键混用

### map（关联数组）

- `map<K,V>`：K 限 int/string，V 限标量与 string（哈希表，链地址法）
- 字面量：`["a" => 1, "b" => 2]`（全键形式，键类型统合；K/V 亦可借目标 map 类型）
- 下标读写 `$m["k"]`；写即插入、重复写覆盖；**读取缺失键 panic**（不可捕获）
- `len($m)` / `array_keys($m)`（键收集为 array\<K\>，遍历入口：哈希无序，不保证键序）
- 引用语义（赋值共享）；map 不作类字段/数组元素/嵌套（Checker 明确拒绝）

### 内置函数（全部）

| 函数 | 说明 |
| ---- | ---- |
| `len($x)` | string 长度 / array 元素数 → int |
| `var_dump($x)` | 打印类型与值（调试用） |
| `implode($sep, $parts)` | 拼接 array\<string\>（O(n)，字符串累加的正解工具） |
| `array_keys($m)` | map 的键收集为 array\<K\>（遍历入口） |
| `c_str($s)` | string → char*（借用，phpc） |
| `php_str($p)` / `php_str_ref($p)` | char* → string（深拷贝 / 零拷贝借用） |
| `cbuf($n)` | 分配 n 字节 C 缓冲（登记所有权，函数出口自动 free） |
| `c_own($p)` | 接管 C 分配的内存（函数出口自动 free） |

`echo` 是语句关键字（非函数）：`echo $a, $b, "\n";`

### phpc（C 互操作）

- 行首 `#include` / `#flag` / `#struct` / `#if` 为指令；其余 `#` 为行注释
- `#if` / `#elif` / `#else` / `#endif` 平台条件编译：条件为 os / arch / cc 名（可 `!` 取反），
  非命中分支解析前整段丢弃；支持嵌套与函数体内使用
- `c->符号(...)` 直连调用；`c->宏` 常量引用；返回 CVAL（信任程序员，
  可赋 c.*/cstruct/指针/数值/bool，不可赋 string/array/类）
- 参数不做隐式转换：数值直传、cstruct 按值；string 需 `c_str()`；
  `php_str` 深拷贝回 string、`php_str_ref` 零拷贝借用
- C 侧类型（c.*/cstruct/指针/CVAL）禁止推断声明——必须显式
- 详见 `doc/phpc.md`（内存安全边界见 `doc/memory.md`）

### 内存模型

见 `doc/type.md` 内存模型一节：v0.2 字符串走 bump 池、数组/对象
不主动回收；refcount 基础设施已就位，为后续自动回收预留。

## 编译器

```bash
php main.php [build|run|shared] <file.php | .> [more.php ...] [-o out] [--emit-c]
            [--cc tcc|gcc|clang] [-os windows|linux] [-arch x86_64|i386|arm64]
            [--no-main] [--cflag <arg> ...]
php tests/run.php           # 测试架（单文件 + 多文件用例）
php tests/cross.php         # 交叉编译测试（windows x86_64/i386、linux x86_64/arm64）
```

- 命令缺省 = `build`；`run` 编译后立即运行（仅本机目标）；`shared` 编译为动态库（.dll/.so），隐含 `--no-main`
- 目标默认**本机系统 + 本机架构**；只传 `-os` 时架构默认 `x86_64`
- `-os` / `-arch`：目标平台，由自带 TCC 的交叉二进制支持
  （Linux 产物为 musl 静态 ELF，不依赖目标机 libc）
- `--no-main`：不生成 `main()`，供固件工程或宿主程序以库形式集成
- `--cflag <arg>`：向 C 编译器透传参数（可多次），gcc/clang 交叉工具链由此接线

流水线：

```
Pref → Scanner → Parser → Checker（两遍）→ Gen → .c → TCC/GCC/Clang
```

- `Tphp\Token` / `Tphp\Scanner` — 词法
- `Tphp\Parser` — 递归下降（Expr / Stmt / Decl 三个 trait）
- `Tphp\Ast` — 每节点一类一文件；`TypeRef` 语法层类型引用
- `Tphp\Type\Type` + `Tphp\Table\Table` — 类型编码与全局符号表（单一事实来源）
- `Tphp\Checker` — 两遍式：收集符号 → 标注类型（Gen 纯消费，不再推断）
- `Tphp\Gen` — 分节输出（head/consts/typedefs/globals/protos/helpers/funcs/main）+
  `#line` 回源映射
- `Tphp\Builder` — 串联与 TCC 调用；`runtime/*.h` 为生成代码的运行时（纯头文件实现）
