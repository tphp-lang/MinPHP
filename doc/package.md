# 包管理（`#import` 自研标准库扩展）

TinyPHP 用 `#import <包名>` 引入**自研标准库扩展**（包），实现放在 `ext/<包名>/`，调用走命名空间隔离。

| 项 | 约定 |
| --- | --- |
| 指令 | `#import <包名>`（包名可分层，如 `tphp/json`） |
| 内容位置 | 项目 `ext/<包名>/` → 编译器自带 `ext/<包名>/`（项目优先，可覆盖自带包） |
| 调用方式 | **命名空间**：`namespace Tphp\Json;` → `Json::encode()` |
| 清单 | `ext/<包名>/mod.php`（指令式：`#flag` / `#include` + 元信息注释头） |
| 能力声明 | 包可携带 C 源/`#flag`/`#include`，**必须写在清单里**，编译时打印能力汇总 |

设计原则：包机制不引入运行时开销——包内 `.php` 与项目源码一样并入**单 TU**；无 C 能力的包
完全不改变产物形态（仍是零依赖小 exe）。

> 曾评估"直连 libphp（embed）以复用 PHP 原生函数/标准库/扩展"，**已放弃**（结论与技术记录见
> `doc/not-doing.md`）。因此语言目前**只**有 `#import` 这一种能力来源。

## 一、包布局

```
ext/<包名>/
  mod.php          # 指令式清单：只写 #flag / #include（+ // @package 等元信息注释）
  src/*.php        # 自研实现（命名空间隔离）
  include/*.h      # 可选：随包 C 头
  src/impl.c       # 可选：随包 C 源（由 #flag 声明）
```

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

## 二、编译期能力汇总

出现包时打印（供应链透明）：

```
[TinyPHP 能力汇总]
  #import demo/native        cflags: -Iinclude  附加C源: src/demo_native.c
  #import tphp/str           （纯自研实现，无 C 能力）
```

## 三、错误处理（全部显式报错，附可操作信息）

| 场景 | 行为 |
| --- | --- |
| `#import` 用了路径形式 | `#import 只接受包名（…），不支持路径形式` |
| 包不存在 | `#import X 未找到：已搜索 <项目 ext、编译器 ext>；可用包：…` |
| 环依赖 | `包依赖循环：#import a → b → a` |
| 包内无 .php | `#import X 包内没有 .php 源文件：<目录>` |
| 包内 `#flag` 引用的 C 源缺失 | `#flag 引用的源文件不存在 X（按包根解析为 Y）` |

## 四、测试

```bash
php tests/packages.php    # 包管理：装配 / 传递依赖 / 环依赖 / 缺失包 / C 能力 / 自带包 / 覆盖顺序
```

覆盖 7 个场景（16 项断言）：基本装配 / 传递依赖 / 环依赖 / 缺失包 / 包自带 C 能力 /
**仓库自带示例包**（`ext/tphp/str`、`ext/demo/native`）/ **项目 ext 覆盖编译器 ext**。
用例在 `tests/packages.php` 内以**独立临时工程**（自带 `ext/`）构建，CWD 为临时工程目录；
指令层与包解析的报错用例同时进了主套件（`tests/cases/62_import_path_form`、`64_import_missing`）。

## 五、自带示例包

| 包 | 内容 |
| --- | --- |
| `ext/tphp/str` | 纯自研实现示例（`Tphp\Str\Str` / `Counter`），无 C 能力 |
| `ext/demo/native` | 带 C 能力示例：`mod.php` 声明 `#include`/`#flag`，`src/Api.php` 用 `c->` 包装 C 函数 |
