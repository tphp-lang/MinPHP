# 打包与分发（CI）

TinyPHP 编译器的发布形态：**单文件 `tphp[.exe]`**（`micro.sfx` + `tphp.phar` 拼接）+ **随包真文件**的
`runtime/`、`tcc/`、`ext/`。CI 由 `.github/workflows/package.yml` 完成，产物只上传 **Actions Artifacts**
（不发 GitHub Release）。

## 一、发布包布局（解压即用，运行期零解压）

```
tphp-<os>-<arch>/
  tphp[.exe]     编译器本体：micro.sfx + tphp.phar 拼接
  runtime/       C 运行时头文件（TCC -I 需要，必须是真文件）
  tcc/           对应平台的 TCC 包**原样解出**（含交叉编译器，见 TCC.md）
  ext/           自带包（#import 的唯一来源）
  README.md / LICENSE
```

**为什么不做首跑解压**：TCC 读不了 `phar://`，也无法执行 phar 内的二进制。旧版（`C:\project\php\TinyPHP`）
把 `include/`+`tcc/`+`ext/` 塞进 phar，首次运行解压到安装目录 —— 既慢又要写权限。新版直接以真文件
随包分发，运行期**不写安装目录任何文件**。

**验证方式**（CI 冒烟会做）：换到非安装目录运行编译器；对比运行前后包内文件数不变。

## 二、安装根解析（`src/Install/Paths.php`）

编译器按候选顺序探测「同时存在 `runtime/` 与 `tcc/`」的目录（自校验，命中即用）：

1. `Phar::running(false)` 的所在目录 —— phar/micro 打包形态
2. `$_SERVER['SCRIPT_FILENAME']` 的所在目录 —— micro 下指向可执行文件
3. `$_SERVER['argv'][0]` 的所在目录 —— 源码模式 `php main.php ...`
4. 源码根 `dirname(__DIR__, 2)` —— 兜底

`runtime/`、`tcc/`、`ext/` 一律按 `Paths::root()` 解析，**与 CWD 无关**。

## 三、随包 TCC 的 host × target 映射

TCC 的目标平台在编译 tcc 自身时由 `TCC_TARGET_*` 宏决定（`-arch` 是被忽略的选项），交叉编译器是
按目标命名的独立二进制（详见 `TCC.md`）。`Cc::tccBinary()` 的映射：

| host \ target | windows x64 | windows i386 | linux x86_64 | linux arm64 | macOS（本机） |
|---|---|---|---|---|---|
| **windows** | `tcc/tcc.exe` | `tcc/i386-win32-tcc.exe` | `tcc/x86_64-tcc.exe` | `tcc/arm64-tcc.exe` | — |
| **linux** | `tcc/x86_64-win32-tcc` | `tcc/i386-win32-tcc` | `tcc/tcc`（仅同构） | `tcc/tcc`（仅同构） | — |
| **macOS** | `tcc/x86_64-win32-tcc` | `tcc/i386-win32-tcc` | `tcc/x86_64-tcc` | `tcc/arm64-tcc` | `tcc/tcc`（本机 Mach-O） |

找不到随包 TCC 时**显式报错**（不再退回 PATH）—— 发布包必须完整；需要别的编译器用 `--cc`。

## 四、CI（`.github/workflows/package.yml`）

| target | runner | 宿主 PHP（构建） | micro | TCC 包 |
|---|---|---|---|---|
| `win-x86_64` | `windows-latest` | `php-8.5.7-cli-win.zip` | `php-8.5.7-micro-win.zip` | `tcc-win-x86_64.zip` |
| `linux-x86_64` | `ubuntu-latest` | `php-cli-8.4-linux-x86_64-glibc.zip` | `php-micro-8.4-linux-x86_64-glibc.zip` | `tcc-linux-x86_64.zip` |
| `linux-aarch64` | `ubuntu-24.04-arm` | `php-cli-8.4-linux-aarch64-glibc.zip` | `php-micro-8.4-linux-aarch64-glibc.zip` | `tcc-linux-aarch64.zip` |
| `macos-aarch64` | `macos-latest` | `php-cli-8.4-macos-aarch64.zip` | `php-micro-8.4-macos-aarch64.zip` | `tcc-macos-aarch64.zip` |

- 宿主 PHP / micro 来自 `KingBes/TinyPHP` release `PHP`（版本以各平台实际可用的包为准：Windows 8.5.7、其余 8.4）
- TCC 包来自 `tphp-lang/tccbin` **最新 release**（`releases/latest/download/<包名>` 稳定链接，资产名不含版本号）
- 触发：push `main` / PR / 手动；产物名 `tphp-<target>`
- 组装由 `build.php --dist` 完成 —— **CI 与本地是同一条命令**（拼接/拷贝/解 TCC 包全在 PHP 内）
- 冒烟：换 CWD 跑 `hello`（纯编译）与 `#import hello`（走随包 `ext/`）

## 五、本地手动复现（Windows 示例，全程纯 PHP）

```bash
# 1) 下载两个包：micro（内含 micro.sfx）与对应平台的 TCC 包（TCC.md）
# 2) 一条命令组装完整发布目录 —— 拼接单文件 / 拷贝 runtime+ext / 解 TCC 包全由 build.php 完成
php -d phar.readonly=0 build.php \
  --dist build/dist/tphp-win-x86_64 \
  --micro build/micro-win.zip \
  --tcc-zip build/tcc-win.zip
# 3) 运行（换 CWD 亦可）
build/dist/tphp-win-x86_64/tphp.exe run examples/01_hello.php -o /tmp/hello.exe
```

`--micro` 也可以直接给解出的 `micro.sfx` 文件；`--bin` 可显式指定单文件输出路径；
只打 phar 不组装：`php -d phar.readonly=0 build.php -o tphp.phar`。
