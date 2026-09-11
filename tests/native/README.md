# #php（libphp 直连）冒烟验证

证明"编 exe → 链 php/ 里的 libphp → 运行期 php8.dll → 调 PHP 原生函数并取回返回值"可行，
**不依赖 php-src 头**（自备最小 ABI 声明），也**不需要 MSVC**（MinGW clang 即可）。

| 文件 | 验证内容 |
| --- | --- |
| `smoke_native.c` | 引擎初始化 + `zend_eval_stringl` 执行 PHP 代码（`echo json_encode([1,2,3])`） |
| `smoke_call.c` | 传参 + 取回返回值（`json_encode` 表达式；`md5('a\'b\\c')` 字符串参数与转义） |

## 构建（Windows/MinGW clang，PHP 8.5.1 NTS x64）

```bash
LLVM=/c/env/Msys2/clang64/bin

# 1) 从 php8.dll 导出表生成 MinGW 导入库（直接用 MSVC 的 php8embed.lib 会有伪重定位问题）
{ echo "LIBRARY php8.dll"; echo "EXPORTS"; \
  $LLVM/llvm-readobj.exe --coff-exports php/php8.dll | sed -n 's/^  Name: //p' \
  | sed 's/@@[0-9]*$//' | sort -u | sed 's/^/  /'; } > build/php8.def
$LLVM/llvm-dlltool.exe -m i386:x86-64 -d build/php8.def -l build/libphp8.dll.a -D php8.dll

# 2) MSVC 导入库带 /DEFAULTLIB 指令，GNU 链接器需空桩库
mkdir -p build/stubs
for n in OLDNAMES libcmt libvcruntime libucrt libcpmt; do ar rcs build/stubs/lib$n.a; done

# 3) 编译链接
$LLVM/clang.exe tests/native/smoke_call.c -o build/smoke_call.exe \
    build/libphp8.dll.a php/php8embed.lib -Lbuild/stubs

# 4) 运行（php8.dll 需在 exe 同目录或 PATH）
PATH="$PWD/php:$PATH" ./build/smoke_call.exe
# 期望：
#   json=[1,2,3]
#   md5=72860c33766c8eca43f5f1e2871189d5     （与 php -r 'echo md5("a'\''b\\c");' 一致）
```

## 实测结论（M2 设计依据）

1. **引擎导出**：`php8.dll` 导出 `php_module_startup` / `php_request_startup` / `zend_eval_stringl` /
   `zend_execute_scripts` / `zend_string_init_interned` / `_call_user_function_impl`（**带前导下划线**）。
   `php_embed_init` / `php_embed_shutdown` 在 `php8embed.lib`（`php8.dll` 本身不导出）。
2. **不必用 php-src 头**：最小 ABI 声明即可 —— `zval` 16 字节（value 8 + type_info 4 + u2 4，
   type 取 `type_info & 0xFF`）、`zend_string` 的 `val[]` 偏移 **24**。
3. **字符串不要自己构造**：`zend_string_init` 是 inline **未导出**；`zend_string_init_interned`
   在运行时构造会导致崩溃（实测）。改用 **eval 桥**：把调用拼成表达式交给 `zend_eval_stringl`。
4. **`zend_eval_stringl` 的坑**：`retval != NULL` 时函数内部**自动包 `return ... ;`**，
   因此**只能传表达式**（自己写 `return` 会变成 `return return ...;;` 而编译失败）。
5. **链接要点**：`php8embed.lib` 是 MSVC 导入库（带 `/DEFAULTLIB`）→ 直接链会有 32 位伪重定位
   告警并在运行期崩溃；正解是**从 php8.dll 导出表生成 MinGW 导入库**（或加 `--disable-auto-import`
   并自行提供导入库）。
