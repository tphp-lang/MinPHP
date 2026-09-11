# #php（libphp 直连）冒烟验证

证明"编 exe → 链 php/php8embed.lib → 运行期 php8.dll → 调 PHP 原生函数"这条链路可行，
不依赖 php-src 头（自备最小 ABI 声明）。

前置：`php/` 放好 PHP 发行版（含 `php8.dll`、`php8embed.lib`）。

```bash
# 1) MSVC 导入库带 /DEFAULTLIB 指令，MinGW 链接器需空桩库
mkdir -p build/stubs
for n in OLDNAMES libcmt libvcruntime libucrt libcpmt; do ar rcs build/stubs/lib$n.a; done

# 2) 编译链接（MinGW clang；gcc 亦可）
clang tests/native/smoke_native.c -o build/smoke_native.exe php/php8embed.lib -Lbuild/stubs

# 3) 运行（php8.dll 需在 exe 同目录或 PATH）
PATH="$PWD/php:$PATH" ./build/smoke_native.exe
# 期望输出：
#   [1,2,3]
#   8.5.1
```
