# 包管理与能力来源

TinyPHP 有两种**能力来源**，互不冲突（命名空间隔离）：

| 来源 | 指令 | 内容位置 | 调用方式 |
| --- | --- | --- | --- |
| 自研标准库扩展 | `#import <包名>` | 项目 `ext/<包名>/` → 编译器自带 `ext/<包名>/` | **命名空间**：`namespace Tphp\Json;` → `Json::encode()` |
| PHP 原生（函数/标准库/扩展） | `#php <模块名>` | 项目 `php/`（PHP 发行版，自备） | **全局名**：`json_encode()` |

设计原则：无 `#php` 的程序保持**单 TU、零依赖、小体积**不变；包可携带 C 能力，但必须在清单里**显式声明**，编译时打印能力汇总（供应链透明）。

## 一、包（`ext/`）

```
ext/<包名>/
  mod.php          # 指令式清单：只写 #flag / #include（+ 元信息注释头）
  src/*.php        # 自研实现（命名空间隔离）
  include/*.h      # 可选：随包 C 头
  src/impl.c       # 可选：随包 C 源（由 #flag 声明）
```

- **包名**：字母/数字/下划线，可用 `/` 分层（`tphp/json` → `ext/tphp/json/`）。`#import` **不接受路径形式**（防越界，与 `#include/#flag` 同一安全原则）。
- **搜索顺序**：项目 `ext/` → 编译器自带 `ext/`（项目优先，可覆盖自带包）。
- **装配**：包内**所有 `.php` 递归纳入**编译单元（`mod.php` 无声明，只贡献指令）；包之间可互相 `#import`（传递依赖），按 realpath 去重，**环依赖报错**。
- `tphp .`（递归目录输入）会**跳过 `ext/`**，避免与 `#import` 重复收集。

清单示例（含 C 能力声明）：

```php
<?php

// @package tphp/json
// @version 0.1.0
// @desc    自研 JSON 编解码（零依赖）

#include "json_impl.h"
#flag -Iinclude
#flag src/json_impl.c
```

- `#flag` 一行一个参数；**包内**相对路径按**包根**解析（`-Iinclude` → `ext/tphp/json/include`），项目内其它文件保持既有"相对 CWD"语义。
- 包内 C 能力通过 phpc 直连使用：`int $r = c->json_impl_add(1, 2);`，再用命名空间包装成有类型的自研 API。

## 二、PHP 原生（`#php`）

```php
#php json

class Main { public function main(): void { /* json_encode(...) */ } }
```

- `#php` 开启 PHP 原生能力；原生函数以**全局名**调用，与自研包的命名空间调用不冲突。
- 需要 `php/` 目录（PHP 发行版 + 嵌入用库/头）。**当前状态**：门槛检测已实现（未就绪时显式报错，不静默通过）；**真链接待 M2**（见下）。
- 产物影响：出现 `#php` 时产物不再是零依赖（需随附 `php/`），编译时会提示。

## 三、编译期能力汇总

出现包或 `#php` 时打印（供应链透明）：

```
[TinyPHP 能力汇总]
  #import demo/native        cflags: -Iinclude  附加C源: src/demo_native.c
  #import tphp/str           （纯自研实现，无 C 能力）
  #php json                  链接 libphp（php/），产物将不再是零依赖
```

## 四、错误处理（全部显式报错，附可操作信息）

| 场景 | 行为 |
| --- | --- |
| `#import` 用了路径形式 | `#import 只接受包名（…），不支持路径形式` |
| 包不存在 | `#import X 未找到：已搜索 <项目 ext、编译器 ext>；可用包：…` |
| 环依赖 | `包依赖循环：#import a → b → a` |
| 包内无 .php | `#import X 包内没有 .php 源文件：<目录>` |
| 包内 `#flag` 引用的 C 源缺失 | `#flag 引用的源文件不存在 X（按包根解析为 Y）` |
| `#php` 未就绪 | `#php X 需要 libphp，但 php/ 目录未就绪：缺失 …（未使用 #php 的程序不受影响）` |

## 五、测试

```bash
php tests/packages.php    # 包管理：装配 / 传递依赖 / 环依赖 / 缺失包 / C 能力 / #php 门槛
```

覆盖 8 个场景（17 项断言）：基本装配 / 传递依赖 / 环依赖 / 缺失包 / 包自带 C 能力 /
`#php` 门槛 / **仓库自带示例包**（`ext/tphp/str`、`ext/demo/native`）/
**项目 ext 覆盖编译器 ext**。用例在 `tests/packages.php` 内以**独立临时工程**（自带 `ext/`）
构建，CWD 为临时工程目录；指令层与包解析的报错用例同时进了主套件
（`tests/cases/62_import_path_form`、`63_php_module_name`、`64_import_missing`）。

## 六、M2（打通 libphp）：可行性已实测确认

**结论：`#php` 可以直连 libphp，链路已在本机跑通。** 实测（2026-09-11）：

| 环节 | 实测结果 |
| --- | --- |
| 引擎导出 | `php8.dll` 导出 `php_module_startup` / `php_request_startup` / `zend_eval_stringl` / `zend_execute_scripts` / `zend_string_init` / `call_user_function_impl` |
| 嵌入库 | `php8embed.lib` 提供 `php_embed_init` / `php_embed_shutdown`（`php8.dll` 本身不导出 `php_embed_*`） |
| 编译 | **MinGW clang 可直接链** `php/php8embed.lib`（导入库带 `/DEFAULTLIB` 指令，需补 `OLDNAMES` 等空桩库，见下） |
| 运行 | `php8.dll` 需在 exe 同目录或 PATH；冒烟程序输出 `[1,2,3]` 与 `8.5.1` ✓ |
| 产物 | 88KB（不含 `php8.dll`，仍非"零依赖"） |

冒烟验证（源码 `tests/native/smoke_native.c`，复现步骤见 `tests/native/README.md`）要点：
- **不需要 php-src 头**：自备最小 ABI 声明即可（`php_embed_init(int, char**)`、`php_embed_shutdown()`、`zend_eval_stringl(const char*, size_t, zval*, const char*)`——注意 `zend_eval_string` 是宏，实际符号带 `l` 后缀）。
- **不需要 MSVC**：`clang`（MinGW）+ 空桩库即可；`php8embed.lib` 的 `/DEFAULTLIB:OLDNAMES` 等需要空 `.a` 桩（`-L<stubs>`）。

**桥接方式已选定（实测）**：**eval 桥** —— 把调用拼成 PHP 表达式交给 `zend_eval_stringl`
（参数按 PHP 字面量转义），返回值按 zval 只读解析（不构造 `zend_string`：`zend_string_init` 未导出，
`zend_string_init_interned` 运行时构造会崩）。**链接**用"从 `php8.dll` 导出表生成的 MinGW 导入库"
+ `php8embed.lib` + MSVC defaultlib 空桩。复现步骤与全部坑点见 `tests/native/README.md`。

M2 待实现（设计要点）：
1. **签名表**：静态类型语言必须在编译期知道签名 → 编译器自带 `native/<mod>.php`（可从 php-src `*.stub.php` 半自动生成），不可映射的签名（`mixed`/联合/可空/`object`）**显式报错**。
2. **桥接**：`runtime/tphp_native.h`（最小 ABI 声明 + 编组 + 引擎生命周期：`php_embed_init` → 程序全程一次 request → `php_embed_shutdown`）；错误映射到 `throw`/`or {}`。
3. **链接**：`Cc` 在出现 `#php` 时注入 `php/php8embed.lib` + 桩库目录，并提示"需随附 `php/`"。
4. **版本/ABI**：绑定 `php8.dll` 的版本（8.5.1 NTS x64）；跨边界不传 `FILE*`、不跨 CRT 释放内存（PHP 分配的内存由 PHP 释放）。
