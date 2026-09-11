# 包管理（`#import` 自研标准库扩展）

TinyPHP 用 `#import <包名>` 引入**自研标准库扩展**（包），实现放在 `ext/<包名>/`，调用走命名空间隔离。

| 项 | 约定 |
| --- | --- |
| 指令 | `#import <包名>`（包名可分层，如 `tphp/json`） |
| 内容位置 | **编译器目录的 `ext/<包名>/`** —— 唯一来源，与 CWD 无关（项目自带 `ext/` 不参与搜索） |
| 调用方式 | **命名空间**：`namespace Tphp\Json;` → `Json::encode()` |
| 清单 | `ext/<包名>/mod.php`（指令式：`#flag` / `#include` + 元信息注释头） |
| 能力声明 | 包可携带 C 源/`#flag`/`#include`，**必须写在清单里**，编译时打印能力汇总 |

设计原则：包机制不引入运行时开销——包内 `.php` 与项目源码一样并入**单 TU**；无 C 能力的包
完全不改变产物形态（仍是零依赖小 exe）。

> 语言目前**只**有 `#import` 这一种能力来源（自研标准库扩展）。

## 一、示例与测试（先看这里）

| 位置 | 内容 |
| --- | --- |
| `examples/05_package.php` + `ext/hello/` | **最小可运行示例**：示例包（纯实现、无 C 能力）位于编译器目录的 `ext/`，故从编译器目录直接运行即可：`php main.php run examples/05_package.php` |
| `tests/cases/65_import_basic.php` | 主套件 happy path：`#import tphp/str` 调用仓库自带包 |
| `tests/cases/62_import_path_form.php`、`64_import_missing.php` | 主套件报错用例（路径形式 / 缺失包） |
| `tests/packages.php` | 包机制完整测试：8 场景 18 断言（装配 / 传递依赖 / 环依赖 / 缺失包 / C 能力 / 自带包 / **项目 ext 不被识别** / **换 CWD 仍可用**）。夹具包在编译器目录 `ext/__it/` 临时创建、跑完删除 |
| `ext/tphp/str/`、`ext/demo/native/` | 仓库自带示例包（前者纯实现，后者带 C 能力） |

## 二、包布局

```
ext/<包名>/
  mod.php          # 指令式清单：只写 #flag / #include（+ // @package 等元信息注释）
  src/*.php        # 自研实现（命名空间隔离）
  include/*.h      # 可选：随包 C 头
  src/impl.c       # 可选：随包 C 源（由 #flag 声明）
```

- **位置**：包目录位于**编译器目录的 `ext/`**（随编译器分发）；`#import` 只在彼处查找，
  当前工作目录下的 `ext/` **不参与**搜索。
- **包名**：字母/数字/下划线，可用 `/` 分层（`tphp/json` → `ext/tphp/json/`）。
  `#import` **不接受路径形式**（防越界，与 `#include`/`#flag` 同一安全原则）。
- **装配**：包内**所有 `.php` 递归纳入**编译单元（`mod.php` 无声明，只贡献指令）；
  包之间可互相 `#import`（传递依赖），按 realpath 去重，**环依赖报错**。
- **解析顺序**：CLI 辅助文件 → 包文件 → 入口（含 `class Main`）最后。
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

- `#flag` 一行一个参数；**包内**相对路径按**包根**解析（`-Iinclude` → `ext/tphp/json/include`），
  项目内其它文件保持既有"相对 CWD"语义。
- 包内 C 能力经 phpc 直连使用：`int $r = c->json_impl_add(1, 2);`，
  再用命名空间包装成有类型的自研 API（示例见 `ext/demo/native/`）。

## 三、编译期能力汇总

出现包时打印（供应链透明）：

```
[TinyPHP 能力汇总]
  #import demo/native        cflags: -Iinclude  附加C源: src/demo_native.c
  #import tphp/str           （纯自研实现，无 C 能力）
```

## 四、错误处理（全部显式报错，附可操作信息）

| 场景 | 行为 |
| --- | --- |
| `#import` 用了路径形式 | `#import 只接受包名（…），不支持路径形式` |
| 包不存在 | `#import X 未找到：已搜索 <编译器目录 ext>；可用包：…` |
| 环依赖 | `包依赖循环：#import a → b → a` |
| 包内无 .php | `#import X 包内没有 .php 源文件：<目录>` |
| 包内 `#flag` 引用的 C 源缺失 | `#flag 引用的源文件不存在 X（按包根解析为 Y）` |

## 五、自带示例包

| 包 | 内容 |
| --- | --- |
| `ext/hello` | 最小示例包（`Hello\Hello`：greet/twice/sum），无 C 能力；配 `examples/05_package.php` |
| `ext/tphp/str` | 纯自研实现示例（`Tphp\Str\Str` / `Counter`），无 C 能力 |
| `ext/demo/native` | 带 C 能力示例：`mod.php` 声明 `#include`/`#flag`，`src/Api.php` 用 `c->` 包装 C 函数 |
