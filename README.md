# TinyPHP

> 强类型 PHP 子集 → 人类可读 C 代码 → 原生可执行文件。

```php
<?php

const int MAX_RETRIES = 3;

interface Greeter
{
    public function greet(): string;
}

class World implements Greeter
{
    public function greet(): string
    {
        return "hello, world!";
    }
}

class Main
{
    public function main(): void
    {
        Greeter $g = new World();
        echo $g->greet(), "\n";
        $n = parse() or { MAX_RETRIES };   // 出错时取默认值
        echo "n=", $n, "\n";
    }
}

function parse(): int
{
    throw "not ready";
}
```

```console
$ php main.php run hello.php
生成 C 源码: build/hello.c
> tcc -o hello.exe -I runtime build/hello.c
编译完成: hello.exe
hello, world!
Uncaught error: not ready
```

## 快速开始

要求：PHP >= 8.4（运行编译器）、项目自带 TCC（作为 C 后端）。
编译指令详见 `doc/compiler.md`。

```bash
php main.php run examples/01_hello.php       # 编译并运行（exe 在当前目录，C 源码在 build/）
php main.php build examples/04_class.php     # 只编译出 04_class.exe（当前目录）
php main.php build examples/02_control.php --emit-c   # 只生成 C 源码（build/ 目录，可直接阅读）
php tests/run.php                            # 跑测试（64 个用例，含多文件/内存/推断/phpc/可见性/闭包/heredoc/枚举/析构/指令安全/平台条件/map/trait/abstract/instanceof/运行时 panic）
php tests/shared.php                         # 库模式测试（shared 命令 + #[export] 符号导出）
php tests/dot.php                            # `.` 指令测试（递归展开 + 排除规则）
php tests/cross.php                          # 交叉编译测试（4 个目标）
```

产物位置：可执行文件/动态库默认输出到当前目录，C 源码默认输出到当前目录的
`build/`；用 `-o` 显式指定输出路径时，C 源码与产物同目录。

## 交叉编译

自带 TCC 是按目标划分的独立二进制，交叉开箱即用（Linux 产物为 musl
静态链接，不依赖目标机 libc，可直接放到目标板运行）：

```bash
php main.php main.php -os linux -arch arm64   # → Linux arm64 静态 ELF
php main.php main.php -os linux -arch x86_64  # → Linux x86_64 静态 ELF
php main.php main.php -os windows -arch i386  # → 32 位 Windows exe
```

库模式（嵌入式固件 / 宿主程序调用）：

```bash
php main.php main.php --no-main              # 不生成 main()，只输出 C 源码
php main.php shared main.php            # 编译为 main.dll（Windows，当前目录）
php main.php shared main.php -os linux -arch arm64   # → main.so
php main.php shared main.php --cc gcc --cflag -mcpu=cortex-m4 # gcc/clang 透传参数
```

库模式下用户函数与 `tphp_new_<Class>` 都是非 static 的 C 符号，
宿主程序可直接声明并调用；`#[export("c_name")]` 注解可为全局函数
指定自定义 C 符号名（仅顶层 function 有效，详见 `doc/phpc.md`）：

```php
#[export("c_add")]
function add(int $a, int $b): int
{
    return $a + $b;
}
```

## 多文件与命名空间

多文件：入口 = 含全局 `class Main` 的文件（最多一个，命令行第一个参数仅用于输出命名），
其余 `.php` 直接列在命令行，所有文件合并编译为同一程序，符号免 import：

```console
$ php main.php main.php lib/geometry.php lib/units.php
```

命名空间（每文件一个 `namespace` 声明，须为文件第一条声明）：

```php
<?php
namespace Geom;

const double PRECISION = 0.001;

interface Shape
{
    public function area(): double;
}

class Rect implements Shape
{
    public function area(): double { /* ... */ }
}

function area(Shape $s): double { return $s->area(); }
```

入口文件用 `use` 导入（支持 `as` 别名、`function`/`const` 前缀与分组语法），
也可直接写全限定名：

```php
<?php

use Geom\{Shape, Rect as Box, function area, const PRECISION};
use function Geom\area as calcArea;

class Main
{
    public function main(): void
    {
        Box $b = new Box(2.0, 3.0);          // use as 别名
        echo area($b), "\n";                  // use function
        Shape $s = new \Geom\Rect(1.0, 1.0); // 全限定名直接写
        echo Geom\PRECISION, "\n";           // 全限定常量
    }
}
```

规则要点：

- `<?php` 开标签任意文件可选；`?>` 表示源码结束（其后内容忽略）
- 同命名空间跨文件即同一作用域，重复定义报编译错
- 命名空间内裸名先解析当前命名空间；函数/常量再回退全局（PHP 语义），类不回退
- `class Main` 必须在全局命名空间
- 生成 C 的符号内联命名空间：`Geom\Rect` → `tphp_class_Geom_Rect`、
  `Geom\PRECISION` → `TPHP_CONST_GEOM_PRECISION`

更多示例见 `examples/`，语法规范见 `doc/grammar.md`，类型系统见 `doc/type.md`。

## 语言概览

强类型 PHP 子集，语义按 C 设计：

- **类型**（`doc/type.md`）：`int`(i32)、`float`(f64，PHP 语义；`double` 为别名)、`bool`、
  不可变 `string`（SSO）、`array<T>`（纯列表）、`map<K,V>`（关联数组，K 限 int/string）、
  `callable`、类类型（指针，可 null）、
  接口类型（Go itab 风格胖指针，可 null）、
  以及完整的 `c.*` 定宽别名（32 位浮点用 `c.f32`；`c.i8` ~ `c.u128`、`c.f16` ~ `c.f128`、`c.ptr`）
- **变量**：PHP 类型自动推导（`$x = 5;` 首次赋值定死）；C 侧类型（c.*/cstruct/指针）必须显式声明；显式 `int $x = 1;` 依旧合法；隐式转换仅数值宽化（float → c.f32 收窄仅浮点字面量豁免，float 变量须显式 `(c.f32)` 强转）
- **常量**：顶层 `const [T] NAME = 字面量;`（类型注解可省）、类常量 `[vis] const T name`
  （`self::` / `ClassName::` 访问，可见性检查）、函数内常量（可遮蔽全局）
- **字符串**：单/双引号、插值、heredoc（`<<<EOT` 支持插值）与 nowdoc（`<<<'EOT'` 原文）
- **运算符**：算术 `+ - * / % **`、拼接 `.`、位运算、逻辑、三元、管道 `|>`（左值默认插入首参，`...` 占位符可标记插入位置）、自增自减、复合赋值；
  `int / int` 按 C 整除；条件要求严格 `bool`
- **控制流**：`if / elseif / else`、`while`、`do-while`、`for`、
  `foreach ($arr as $k => $v)`、`switch`（PHP 语义，不隐式穿透）、`break / continue`
- **函数**：显式参数/返回类型、默认参数；**多文件编译**——入口 = 含 `class Main` 的文件，
  其余 .php 直接列在命令行，符号免 import（`<?php` 开标签可选）
- **闭包**（`doc/closure.md`）：`function (int $v) use ($a, &$b) { }` 按值/按引用捕获、
  `fn (int $v): int => $v + $a` 箭头糖（自动按值捕获）、`$f(...)` 调用；
  引用捕获经堆盒子实现（内外共享存储，逃逸安全）；管道 `8 |> $f()` 直接可用
- **类与接口**：单继承 + `implements` 多接口（itab 胖指针分发）、接口可继承接口、
  字段平铺单态化、vtable 动态分发、`public/private/protected`、静态成员、
  `__construct` / `__destruct`（用户析构先于字段释放）、`parent::`、
  `: self` 链式返回；**枚举类**（backed int/string / 纯枚举，case 单例恒等、
  方法/接口/`cases`/`from`/`tryFrom`）；`Main::__construct(int $argc, array<string> $argv)` 可接收命令行参数
- **继承修饰与类型判断**：`final class`（不可继承）/ `final` 方法（不可重写）、
  `abstract class` + `abstract` 方法（子类必须实现；语义经 php-src 源码核对）；
  `instanceof`（类/子类/接口判定，静态可判定时编译期折叠为常量）
- **trait**：`use A, B;` 编译期展开为使用类的方法/属性（零运行时痕迹）；
  类自身成员优先、双 trait 冲突报编译错、`insteadof` / `as` 解决、支持嵌套 use 与抽象方法
- **错误处理**：`throw "msg";` 抛出；错误自动沿调用链上浮；**任意调用** `or { ... }` 处理
  （全局函数 / 闭包 `$f()` / 方法 `$o->m()` / 静态 `C::m()` / 构造器 `new C()`；
  链式 `$a->b()->c() or {...}` 中任一环失败即短路进块，后续环节不再求值）——
  块内 `err` 为错误消息（string），值上下文取块内最后表达式；块内可用 `return`/`break`/`continue`；
  顶层未捕获打印 `Uncaught error:` 并以退出码 1 结束；无任何签名注解
- **内置**：`echo`（语句）、`len()`、`var_dump()`、`implode()`（O(n) 字符串拼接的正解工具）、
  `array_keys()`（map 键收集，遍历入口）、phpc 桥接 `c_str` / `php_str` / `php_str_ref` / `cbuf` / `c_own`
- **导出**：`#[export("c_name")]` 注解为全局函数指定 C 符号名（库模式供宿主程序调用；仅顶层 function 有效）
- **phpc（C 互操作）**：`#include` / `#flag` / `#struct` / `#enum` 指令（路径与选项白名单校验，
  见 `doc/phpc.md`）+ `c->` 直连调用与常量引用；
  `c_fn($closure)` 闭包 → C 回调函数指针（约定 C 回调尾参 `void* userdata`，trampoline 转发）；
  C 内存自动管理（`cbuf`/`c_own` 登记，函数出口自动 free，开发者不写 free）——详见 `doc/phpc.md`
- **入口**：`class Main` + `main(): void`

明确不做：动态类型、引用传参、魔术方法（`__construct`/`__destruct` 除外）、
`eval`、运行时 include、`object` 类型、匿名类 —— 评估结论与理由见 `doc/not-doing.md`。

## 架构

```
src/
  Pref/       CLI 选项（目标平台默认本机探测）
  Errors/     统一诊断收集：file:line:col，多错误累积
  Token/      单一枚举 Kind + 平铺 Token
  Scanner/    字符 → Token
  Ast/        每节点一类一文件（expr/ stmt/ decl/）
  Type/       Type = int 编码 + 内置表 + c.* 别名（单一事实来源）
  Table/      全局符号表：类型/类/接口/常量/函数/作用域
  Parser/     递归下降：主类 + Expr/Stmt/Decl 三个 trait
  Checker/    两遍式：收集符号 → 标注类型（三个 trait）
  Gen/        分节 C 输出：head/consts/typedefs/globals/protos/helpers/funcs/main
  Builder/    流水线串联 + TCC 调用
runtime/      生成 C 的运行时（纯头文件：String SSO / Array / 对象 / 接口 itab / builtin）
```

流水线（各阶段只消费上一阶段的显式产物）：

```
Pref → Scanner+Parser → Checker(两遍) → Gen → .c → TCC/GCC/Clang → 可执行文件
```

关键设计：

- **Checker 标注、Gen 纯消费**——类型只在 Checker 推断一次，节点回填 `type` 字段，
  代码生成器不做任何类型判断（旧版在 CodeGenerator 里维护 30+ 个平行类型数组的教训）
- **类型单一事实来源**——编译期编码、C 类型名、运行时布局全部由 `Table` 一处持有
- **内置函数走符号表**——词法层只认识语言关键字，绝不认识库函数
- **分节生成 + `#line` 回源**——生成的 C 分节清晰、可读，C 报错能映射回 PHP 源码
- **编译器即库**——Scanner/Parser/Checker/Gen 相互独立，可单独驱动（tests/smoke 类工具）

## 生成的 C 长什么样

```c
#line 7 "examples/01_hello.php"
    tphp_echo_str(tphp_str_lit("hello world\n", 12));
#line 9 "examples/01_hello.php"
    int32_t n = ((6) * (7));
    tphp_echo_int(n);
```

类单态化为 struct（继承字段平铺、前缀布局）：

```c
struct tphp_class_Dog {
    TPHP_OBJECT_HEAD
    String name;   /* 继承自 Animal */
};
```

用 `php main.php examples/04_class.php --emit-c` 自行查看完整产物。

## 内存安全

三层模型（详见 `doc/memory.md`）：

- **安全核心**：无手动 free；引用计数自动回收数组/对象/接口（对象析构递归释放堆字段）；
  字符串池 + 值语义；cstruct 值语义；越界/空指针 → panic；变量零初始化
- **自动回收**：编译器按精确类型插桩引用计数，语句尾释放临时、作用域退出释放堆局部、
  break/continue/throw 全覆盖
- **可验证**：`--mem-stats` 退出打印 `mem: arrays A/F objects O/F leaks=N`；
  测试架对所有用例断言 `leaks=0`
- **已知例外**：引用循环不回收（文档化；直接自引用 `$o->f = $o` / `$this->f = $this`
  编译期告警）；phpc 的 C 内存由开发者手动所有

## 测试

```bash
php tests/run.php             # 全部用例
php tests/run.php 05_class    # 按名字过滤
```

用例在 `tests/cases/`，文件头注释声明预期：
`// expect:` 块为预期输出（逐行比对 + 断言 `leaks=0`）、
`// expect-error: <子串>` 为期望编译失败、`// expect-panic: <子串>` 为期望运行时 panic（退出码 1）。
