# 评估后明确不做

留下结论与理由，避免将来重复论证。新增条目须写清"为什么不做"与"什么条件下可重启评估"。

## object 类型

**不做。**

- AOT 下静态类型不含方法布局 → `object` 变量**不能调方法**（方法槽位编译期不可知）；
  PHP 的 object 语义（任意对象，可动态分发）无法对齐
- `object → 接口` 需**运行期按接口 id 查找 itab**（itab 是 (类,接口) 对的静态表）——
  引入运行期类型表，违背零运行时设计
- `接口 → object` 单向可行（拆 `.obj` 字段），但只出不进无实用价值
- "不透明句柄"场景已由 phpc 的 `c.ptr` 覆盖（且能传回 C 侧使用，更实用）

## object 专表"纯匿名类"

**不做。**

- 死句柄：装进 `object` 后不能调方法；纯匿名类又没有可写的类型名可收窄
  （收窄需 `instanceof 类型名`，纯匿名类恰好没有 implements 任何接口）
- 只解决"语法可写"，不解决"可用" —— 净收益为零

## 匿名类

**不做（可替代）。**

- 唯一有价值的形态（`extends` / `implements`，也是 PHP 主流用法）可由**就近的普通类**
  零成本替代：TinyPHP 多文件合并单 TU，类就写在同一文件里
- 纯匿名类仅局部推断可用，跨边界（参数/返回/字段）**无类型可写**
- 不解决任何"没有它做不到"的问题；省一个命名的收益撑不起语法链路成本
  （Parser 语法 / 内部类命名 / 推断路径 / 错误消息 / 文档）
- **重启条件**：若测试框架 / mock 需求明确，可评估"强制父类型"方案
  （要求匿名类必须 `extends` 或 `implements`，使类型总是可写且可用）

## 流敏感类型收窄

**待评估（非阻塞）。**

- 价值：对现有 `instanceof` 是直接增强 —— `if ($x instanceof Dog) { $x->fetch(); }`
  是常见模式（现状报错"类 Animal 没有方法 fetch()"）
- 现状说明：`16_narrow` 是数值收窄（float → c.f32），**不是类型收窄**
- 工作量：中等（Checker 分支作用域 + 分支出口类型合并）
- 注：它也是将来若要引入 `object` 类型的唯一前置条件

## 直连 libphp（embed）复用 PHP 原生能力 —— 已放弃（2026-09-11）

**结论**：不做。语言的能力来源目前只有 `#import` 自研标准库扩展一种。

**当时的设想**：用 `#php <模块名>` 开启 PHP 原生函数/标准库/扩展，实现方式是链接项目
`php/` 目录下的 libphp（PHP 发行版）。

**实测过的可行性结论**（方案本身可行，仍被放弃；记录备查，若将来重启可省一轮调研）：

| 项 | 实测结果 |
| --- | --- |
| 引擎导出 | `php8.dll` 导出 `zend_eval_stringl`、`zend_string_init_interned`、`_call_user_function_impl`（带前导下划线）、`php_module_startup` / `php_request_startup` |
| 嵌入库 | `php_embed_init` / `php_embed_shutdown` 在 `php8embed.lib`（`php8.dll` 不导出 `php_embed_*`） |
| 头文件 | **不需要 php-src 头**：自备最小 ABI 声明即可（`zval` 16 字节、`zend_string` 的 `val` 偏移 24） |
| 编译器 | **不需要 MSVC**：MinGW clang 可直链（需从 `php8.dll` 导出表生成 MinGW 导入库 + 5 个 MSVC defaultlib 空桩） |
| 桥接 | 可行：参数按 PHP 字面量转义拼表达式，交 `zend_eval_stringl`（注意其 `retval != NULL` 时会自动包 `return …;`），返回值按 zval 只读解析 |

**放弃的代价/理由（待补充）**：产物不再零依赖（需随附 `php8.dll` 与一堆运行期 DLL）、
引入与 PHP 引擎的 ABI 绑定（版本耦合）、以及一次调用一次 eval 的开销；
与"零运行时 / 单文件小产物 / 强类型 AOT"的既定取向冲突。若将来重启，建议从
`tests/native/` 时期的冒烟结论（见上表）与 `doc/package.md` 的签名表设计出发。
