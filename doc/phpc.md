# phpc — TinyPHP ↔ C 互操作规范

TinyPHP 直接调用 C 函数（libc / 系统 API / 第三方库）：声明式、直连直调、
零包装、强类型边界。phpc 是语言唯一的 unsafe 边界，内存规则见
`doc/memory.md` 第三节。

## 一、三种 `#` 指令

`#` 位于**行首**且后跟 `include` / `flag` / `struct` 时是指令；
其余位置的 `#` 都是行注释。

```php
#include <stdio.h>;              // 系统头文件 → 原样输出 #include <stdio.h>
#include "mylib/mylib.h";        // 含 / 视为相对路径头文件
#flag -lsqlite3 -lpthread        // 整行参数追加到 C 编译命令；.c 文件自动加入编译列表
#struct Color {
    c.u8 r;
    c.u8 g;
    c.u8 b;
    c.u8 a;
}
```

- `#include` / `#flag` 为行指令（捕获行内原文）；`#struct` 为块指令（正常解析）
- 指令为文件级、全局（不参与命名空间）
- `#struct` 只登记布局给编译器用，**不生成 C 类型**——本体由 `#include`
  的头文件提供（调用现成 C 库的形态）；字段类型：`c.*` 标量 / 嵌套 cstruct / 指针

**安全校验（对齐 vlang 的 #flag 白名单并更严格）**——源文件（可能来自第三方）不能
借指令触达构建机上的任意文件或注入编译器选项：

- `#include "..."`：必须是不含 `..` 的**项目内相对路径**，扩展 `.h`（`<...>` 系统头放行）
- `#flag` 允许：`-I` `-L`（相对路径）、`-l`（库名）、`-D`/`-U`（宏）、
  `-std=`、`-f` `-m` `-O` `-g` `-W`（编译选项，值字符白名单）、裸 `.c/.h/.o/.a` 文件（相对路径）
- `#flag` 拒绝：反引号与 shell 元字符、`..` 路径、盘符绝对路径、**其余一切编译器标志**
  （`-B`/`-specs`/`-wrapper`/`--include`/`-imacros`/`-Xlinker` 等可让构建机
  执行任意文件的选项被白名单天然挡下）
- 构建者自身的可信参数走 CLI `--cflag <arg>`（不经此校验）
- `#flag` 引用的 `.c` 文件在编译前校验存在性

## 一·五、平台条件编译（#if / #elif / #else / #endif）

按当前编译目标（`-os` / `-arch` / `--cc`）在**解析前**丢弃未命中分支——
不解析、不检查、不生成 C，与 Zig 的 `$_if` 口径一致。解决跨平台头文件与链接库差异：

```php
#if windows
    #include <windows.h>
    #flag -luser32
#elif linux
    #include <unistd.h>
    #flag -lrt
#endif

#if arm64
function platTag(): string { return "aarch64"; }
#else
function platTag(): string { return "x64"; }
#endif
```

- 条件：os 名（`windows` / `linux`）、arch 名（`x86_64` / `i386` / `arm64`）、
  cc 名（`tcc` / `gcc` / `clang`），支持 `!` 取反
- 可包裹指令、声明（函数/类/常量）、函数体内语句；指令允许行首缩进
- 支持嵌套；`#if` 缺 `#endif` 编译报错
- 未命中分支的语法错误不影响编译（整段跳过，不解析）

## 一·七、C 枚举

**引用 C 头文件里的枚举（无需声明）**：枚举成员就是整型常量，`c->成员名` 直连引用
（与 C 宏常量同一机制），由 C 编译器解析。返回 CVAL——落变量须显式 C 类型声明：

```php
#include "raylib.h"

c.i32 $r = c->FLAG_RECT;           // 枚举成员
c.i32 $mix = c->FLAG_RECT | c->FLAG_ROUND;  // 位运算组合（CVAL 混入，结果 CVAL）
echo c->KEY_ENTER == 257, "\n";    // 与字面量比较
switch ($v) { case c->KEY_A: ... } // switch 分发
c->draw_circle($x, $y, $r, c->RED); // 直接作为 c-> 调用实参
```

**TinyPHP 侧自定义常量集（#enum）**：不需要 C 头文件时，用 `#enum` 定义等价的
常量集（成员名 = `枚举名_成员名`，类型 c.i32，生成 `#define`）：

```php
#enum Color {
    RED = 1,
    GREEN,      // = 2（缺省 = 前值 + 1，C 语义）
    BLUE = 4,
}
```

- `#enum` 显式赋值必须为整数字面量；重复成员名 / 与其他常量冲突报编译错
- 两侧互通：C 枚举成员与 `#enum` 常量可混入同一表达式（均为 c.i32/CVAL）

## 一·六、库模式导出（`#[export]` 注解）

`shared` 命令 / `--no-main` 库模式下，全局函数与 `tphp_new_<Class>` 以非 static 的
C 符号导出，宿主程序可直接声明并调用。`#[export("c_name")]` 注解为全局函数
指定自定义 C 符号名（仅顶层 function 有效，标注类成员/接口成员报编译错）：

```php
#[export("c_add")]
function add(int $a, int $b): int
{
    return $a + $b;
}
```

规则：

- 注解必须独占一行、紧跟全局 `function` 声明；名称必须是合法 C 标识符（非 C 关键字）
- 注解只改 C 符号名，TinyPHP 内部调用点自动同步使用新符号
- 符号冲突（多个函数导出同名、或与默认 `tphp_<name>` 撞名）报编译错
- exe 模式同样生效（仅重命名，不影响行为）

动态库导出的平台差异（自带 TCC）：

- windows（.dll）：PE 默认不导出普通符号，编译器自动追加 `-Wl,--export-all-symbols`
- linux（.so）：自带 musl 交叉 tcc 的 `-shared` 产物不含动态符号表，
  需要可按符号加载的 .so 时，请在 Linux 宿主上用 `--cc gcc`

## 二、`c->` 直连（C 函数调用 / C 常量引用，无需声明）

```php
c->printf(c_str("phpc: %d\n"), 42);   // 名字原样直连 C 符号
c.i32 $n = c->SEEK_SET;               // C 常量/宏：原样输出 C 表达式
c.ptr $p = c->NULL;                   // NULL 也走 c->
if (c->is_ready()) { ... }            // 条件上下文允许 CVAL（非零即真）
```

- **参数不做隐式转换**：数值（int/float/double/bool/c.\*）本就是 C 类型直传；
  cstruct 按值直传；`string` 必须经 `c_str()` 显式转换
- **返回值 = CVAL**（信任程序员）：可赋给 `c.*` 标量 / cstruct / 指针 /
  `int` / `float` / `double` / `bool`；可参与数值运算与比较（生成 C 原样）；
  不可赋给 `string`（用 `php_str`）/ `array` / 类 / 接口
- C 调用不经过错误通道（or/throw 不涉及）；panic 语义不变

## 三、string ↔ char* 桥接与 C 内存所有权

| 内置函数 | 签名 | 语义 |
| ---- | ---- | ---- |
| `c_str($s)` | `(string): c.char*` | 借用（零拷贝，池内存与进程同寿，只读） |
| `php_str($p)` | `(char*): string` | **深拷贝**（安全默认，NULL → 空串） |
| `php_str_ref($p)` | `(char*): string` | 零拷贝借用（仅生命周期确定的静态数据） |
| `cbuf($n)` | `(int): CVAL` | 分配 n 字节并登记所有权，函数出口自动 free |
| `c_own($p)` | `(指针): CVAL` | 接管 C 分配的内存，函数出口自动 free |
| `c_fn($cb)` | `(callable): CVAL` | 闭包 → C 回调函数指针（见下节） |

**C 回调桥（c_fn）**：把 TinyPHP 闭包变成可传给 C 的函数指针。约定：C 回调签名为
`RET cb(PARAMS..., void* userdata)`——最后一个参数是 userdata 指针（业界惯例，
raylib/SDL/sqlite3 等库均如此）。编译器为每个 `c_fn` 调用点生成一个 trampoline
（转发到闭包 thunk，捕获数据存于进程期静态槽），userdata 形参保留但被忽略：

```php
$cb = fn (int $v): int => $v + $bias;
c.ptr $f = c_fn($cb);
int $r = c->cfn_apply(5, $f, null); // 尾参 ud 传任意值均可（被忽略）
```

- 闭包签名必须与 C 回调去掉尾参后的部分一致（参数类型/返回类型）
- 一个 `c_fn` 调用点对应一个独立 trampoline 与槽；多次调用同一 `c_fn` 共享最新闭包
- **承接闭包的变量不要标注 `c.ptr`**：`c.ptr $f = fn ...` 报
  `初始化类型不匹配：期望 c.ptr，得到 callable`（标注抹掉 c_fn 依赖的签名推导）。
  先用无标注变量承接闭包，再 `c_fn($f)`；`c.ptr` 标在 **c_fn 的返回值**上才合法（如上例）
- **嵌套闭包不可用**：闭包字面量写在另一闭包体内，前端接受但生成的 C 编译失败
  （v0.3 已知缺陷，见 `doc/closure.md` §6.1）；c_fn 闭包写在方法体内一层不受影响
- **c_fn 闭包体内调用 `callable`** ✅：`use` 捕获的（签名随捕获传递）与
  本闭包 `callable` 形参的（签名经调用点回填）均可调用，用例 `66_closure_callable_use`。
  `callable` 变量 / 形参传给 `c_fn` 可行；回调逻辑也可直接写在闭包体内（C 调用 /
  字面量 / `ClassName::method()` / 全局函数——`self::` 在闭包内静默返回 0，勿用，
  见 `doc/closure.md` §6.1 缺陷 ③）

**C 内存自动管理**：开发者不写 `free`。`cbuf` / `c_own` 登记的指针由编译器在
函数所有出口（return / throw / 错误传播 / 隐式结束）自动释放——与 TinyPHP
引用计数的插桩机制同一时机。默认借用规则：

- `c->` 调用的返回值**默认借用**（多数 C 返回是静态/借用数据）——不登记、不释放
- 需要 TinyPHP 接管的，才用一个词 `c_own(...)` 显式标注
- 同一指针不可 `c_own` 两次（会双重释放）；传给 C 长期持有的数据需自行管理

数值转换不需要函数——`(c.i64)$n`、`(int)$v` 等现有强转已覆盖（同一批 C 类型）。

## 三·五、null 类型（= C 的 NULL）

`null` 既是字面量也是类型：`null $f = c->fopen(...)` 声明空指针变量
（C `void*`），可与 `null` 比较、传给任何指针形参、可赋 CVAL。

## 四、cstruct 与指针

```php
#struct Color { c.u8 r; c.u8 g; c.u8 b; c.u8 a; }

Color $red;                        // 零值 {0}，值语义（赋值即结构体拷贝）
$red->r = 255;                     // 字段访问 → C 点访问（无空指针检查）
c->draw_rectangle_rec($rec, $red); // 按值传入 C

c.ptr $raw = cbuf(16);             // 分配 + 登记：函数出口自动 free
Color* $p = (Color*)$raw;          // 强转获得带类型指针
$p->r = 255;                       // 箭头访问 + 前置空指针 panic
```

- cstruct 值语义：与 TinyPHP 数组的引用语义不同（文档 `doc/memory.md`）
- C 内存：`cbuf` / `c_own` 登记，函数出口自动 free——开发者不写 free
- `X*` 指针：`(X*)` 强转来自 `c.ptr` / CVAL / 其他指针
- `c.<tag>*`（裸 C 类型指针，可在属性/参数/返回/局部位置）：引用 C 头文件里的
  struct tag，生成 `struct <tag>*`——如 `c.mg_context*` → `struct mg_context*`
  （配合 `struct mg_context;` 前向声明句柄）；typedef 类型（`FILE*` 等）
  经 `#struct` 注册后用 `<Name>*`，或用 `c.ptr` + 强转
- `array<Color>` 合法：按 `sizeof` 原始字节存储，引用计数不涉及
- echo cstruct/CVAL → 编译错误（输出用 `c->printf`）

## 五、完整示例

```php
<?php

#include <stdio.h>
#include "demo.h"
#flag demo.c

#struct DemoSize {
    c.i32 w;
    c.i32 h;
}

class Main
{
    public function main(): void
    {
        c->printf(c_str("magic=%d\n"), c->DEMO_MAGIC);
        DemoSize $s;
        $s->w = 6;
        $s->h = 7;
        c.i32 $area = c->demo_area($s);
        string $greeting = php_str(c->demo_greet());
        echo $greeting, " area=", $area, "\n";
    }
}
```

测试用例：`tests/cases/12_phpuc.php`（配 `phpc_demo.h/.c` 微型库）。
