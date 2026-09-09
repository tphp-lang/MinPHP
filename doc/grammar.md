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
toplevel    = funcdecl | classdecl | interdecl | constdecl ;

constdecl   = "const", [ type ], IDENT, "=", literal, ";" ;
interdecl   = "interface", IDENT, [ "extends", IDENT, { ",", IDENT } ],
              "{", { imethod }, "}" ;
imethod     = [ "public" ], "function", IDENT, "(", [params], ")", [":", type], ";" ;

funcdecl    = "function", ident, "(", [params], ")", [":", type], block ;
classdecl   = "class", ident, [ "extends", ident ],
              [ "implements", IDENT, { ",", IDENT } ], "{", { member }, "}" ;
member      = [vis], ["static"], ( method | prop | classconst ) ;
vis         = "public" | "private" | "protected" ;
method      = "function", ident, "(", [params], ")", [":", type], block ;
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
mul         = pow, { ("*" | "/" | "%"), pow } ;
unary       = ("-" | "+" | "!" | "~" | "++" | "--"), unary | power ;
power       = postfix, [ "**", unary ] ;             (* 右结合，高于一元负号 *)
postfix     = primary, { postfixtail } ;
postfixtail = "[", [expr], "]" | "->", ident, ["(", [args], ")"]
            | var, "(", [args], ")"                    (* 闭包调用 $f(...) *)
            | "c", "->", IDENT, ["(", [args], ")"]   (* phpc 直连 *)
            | "::", ( var | IDENT, ["(", [args], ")"] )   (* ::$prop / ::CONST / ::method() *)
            | "or", block                                 (* 仅函数调用后 *)
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

### 类

- 单继承 `extends` + `implements` 多接口；字段平铺单态化为 C struct
  （继承字段前缀布局，指针可安全上溯）
- 方法经 vtable 分发；无子类的类直接直调
- 允许方法重写（签名一致）；**不允许属性遮蔽**
- 支持 `public / private / protected`（编译期检查）、静态属性与方法、
  `self::` / `parent::`、`__construct`
- 不支持：trait / abstract / final / enum / 匿名类 /
  魔术方法（`__construct` 除外）

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
