<?php

declare(strict_types=1);

/**
 * 包管理测试：#import 自研 ext（含 C 能力声明、自带包、来源唯一性）。
 *
 * 语义前提：包**只在编译器目录的 `ext/`** 下查找（与 CWD 无关）。
 * 因此夹具包在 `ext/__it/`（编译器 ext 下）临时创建，跑完删除。
 *
 *   1) basic     —— 基本装配：ext 包 + 命名空间调用 + 能力汇总
 *   2) nested    —— 包内 import 另一个包（传递依赖）
 *   3) cycle     —— 环依赖 → 编译错误
 *   4) missing   —— 包不存在 → 编译错误（附搜索路径与可用包）
 *   5) cext      —— 包自带 C 能力（#include/#flag/.c，路径按包根解析）
 *   6) shipped   —— 仓库自带的包（ext/hello、ext/tphp/str、ext/demo/native）可用
 *   7) foreign   —— **项目自带 ext/ 不被识别**（来源唯一：只认编译器 ext）
 *   8) cwd-free  —— 换任意 CWD 运行，包照旧可用
 *
 * 用法：php tests/packages.php
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$extRoot = $root . '/ext';
$fixture = $extRoot . '/__it';        // 夹具包命名空间（临时）
$work = $root . '/build/tests/packages';
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): bool
{
    global $pass, $fail;
    if ($ok) {
        echo "PASS {$name}\n";
        $pass++;
    } else {
        echo "FAIL {$name}\n" . ($detail !== '' ? $detail . "\n" : '');
        $fail++;
    }
    return $ok;
}

/** 递归删除目录（不存在则忽略）。 */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function put(string $dir, string $rel, string $content): void
{
    $path = $dir . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

/**
 * 在 $cwd 下编译并运行 $entry（绝对路径），返回 [输出, 退出码]。
 * CWD 只影响产物落点与 #flag 相对路径，**不影响包查找**（包在编译器 ext）。
 */
function compileFrom(string $cwd, string $entry, string $extra = ''): array
{
    global $root, $php;
    $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . escapeshellarg($php) . ' '
        . escapeshellarg($root . '/main.php') . ' run ' . escapeshellarg($entry)
        . ' ' . $extra . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
}

/** 写入口文件到 build/tests/packages/，返回绝对路径。 */
function entry(string $name, string $src): string
{
    global $work;
    @mkdir($work, 0777, true);
    $path = $work . '/' . $name . '.php';
    file_put_contents($path, $src);
    return str_replace('\\', '/', $path);
}

// 夹具：先清理上次残留，并登记退出时清理
rrmdir($fixture);
register_shutdown_function(static function () use ($fixture): void {
    rrmdir($fixture);
});

// ---------------------------------------------------------------- 夹具包
put($fixture, 'basic/mod.php', "<?php\n\n// @package __it/basic\n// @version 0.1.0\n");
put($fixture, 'basic/src/Util.php', <<<'PHP'
<?php

namespace It\Basic;

final class Util
{
    public static function join(array<string> $xs): string
    {
        return implode("-", $xs);
    }

    public static function sum(array<int> $xs): int
    {
        int $t = 0;
        foreach ($xs as $x) {
            $t = $t + $x;
        }
        return $t;
    }
}
PHP);

put($fixture, 'nest_a/mod.php', "<?php\n\n// @package __it/nest_a\n\n#import __it/nest_b\n");
put($fixture, 'nest_a/src/Top.php', <<<'PHP'
<?php

namespace It\NestA;

final class Top
{
    public static function name(): string
    {
        return "top";
    }
}
PHP);
put($fixture, 'nest_b/mod.php', "<?php\n\n// @package __it/nest_b\n");
put($fixture, 'nest_b/src/Base.php', <<<'PHP'
<?php

namespace It\NestB;

final class Base
{
    public static function tag(): string
    {
        return "base";
    }
}
PHP);

put($fixture, 'cyc_x/mod.php', "<?php\n\n// @package __it/cyc_x\n\n#import __it/cyc_y\n");
put($fixture, 'cyc_y/mod.php', "<?php\n\n// @package __it/cyc_y\n\n#import __it/cyc_x\n");
put($fixture, 'cyc_x/src/X.php', "<?php\n\nnamespace It\\CycX;\n\nfinal class X\n{\n    public static function v(): int\n    {\n        return 1;\n    }\n}\n");

put($fixture, 'cext/mod.php', <<<'PHP'
<?php

// @package __it/cext
// @desc 自带 C 能力（#include / #flag 即能力声明，相对路径按包根解析）

#include "capi.h"
#flag -Iinclude
#flag src/capi.c
PHP);
put($fixture, 'cext/include/capi.h', <<<'C'
#ifndef CAPI_H
#define CAPI_H

#include <stdint.h>

int32_t capi_add(int32_t a, int32_t b);
int32_t capi_mul(int32_t a, int32_t b);

#endif
C);
put($fixture, 'cext/src/capi.c', <<<'C'
#include "capi.h"

int32_t capi_add(int32_t a, int32_t b) { return a + b; }
int32_t capi_mul(int32_t a, int32_t b) { return a * b; }
C);
put($fixture, 'cext/src/Api.php', <<<'PHP'
<?php

namespace It\Cext;

final class Api
{
    public static function add(int $a, int $b): int
    {
        int $r = c->capi_add($a, $b);
        return $r;
    }

    public static function mul(int $a, int $b): int
    {
        int $r = c->capi_mul($a, $b);
        return $r;
    }
}
PHP);

// ---------------------------------------------------------------- 1) basic
$e = entry('basic', <<<'PHP'
<?php

#import __it/basic

use It\Basic\Util;

class Main
{
    public function main(): void
    {
        array<string> $xs = ["a", "b"];
        echo Util::join($xs), "\n";
        echo "sum=", Util::sum([1, 2, 3]), "\n";
    }
}
PHP);
[$out, $code] = compileFrom($root, $e, '-o build/tests/packages/basic.exe');
check('basic：编译并运行成功', $code === 0 && str_contains($out, 'a-b'), $out);
check('basic：跨包调用（sum）', str_contains($out, 'sum=6'), $out);
check('basic：能力汇总打印包来源', str_contains($out, '[TinyPHP 能力汇总]') && str_contains($out, '#import __it/basic'), $out);
check('basic：纯自研包标注无 C 能力', str_contains($out, '无 C 能力'), $out);
check('basic：无泄漏', !str_contains($out, 'leaks=') || str_contains($out, 'leaks=0'), $out);

// ---------------------------------------------------------------- 2) nested
$e = entry('nested', <<<'PHP'
<?php

#import __it/nest_a

use It\NestA\Top;
use It\NestB\Base;

class Main
{
    public function main(): void
    {
        echo Top::name(), "\n";
        echo Base::tag(), "\n";
    }
}
PHP);
[$out, $code] = compileFrom($root, $e, '-o build/tests/packages/nested.exe');
check('nested：传递依赖被自动装配', $code === 0 && str_contains($out, 'top') && str_contains($out, 'base'), $out);

// ---------------------------------------------------------------- 3) cycle
$e = entry('cycle', <<<'PHP'
<?php

#import __it/cyc_x

class Main
{
    public function main(): void
    {
        echo "x", "\n";
    }
}
PHP);
[$out, $code] = compileFrom($root, $e, '-o build/tests/packages/cycle.exe');
check('cycle：环依赖被拒绝', $code !== 0 && str_contains($out, '循环'), $out);

// ---------------------------------------------------------------- 4) missing
$e = entry('missing', <<<'PHP'
<?php

#import nosuch/pkg

class Main
{
    public function main(): void
    {
        echo "x", "\n";
    }
}
PHP);
[$out, $code] = compileFrom($root, $e, '-o build/tests/packages/missing.exe');
check('missing：包不存在时显式报错', $code !== 0 && str_contains($out, '未找到'), $out);
check('missing：错误含搜索路径与可用包', str_contains($out, '已搜索') && str_contains($out, '可用包'), $out);

// ---------------------------------------------------------------- 5) cext
$e = entry('cext', <<<'PHP'
<?php

#import __it/cext

use It\Cext\Api;

class Main
{
    public function main(): void
    {
        echo "add=", Api::add(3, 4), "\n";
        echo "mul=", Api::mul(6, 7), "\n";
    }
}
PHP);
[$out, $code] = compileFrom($root, $e, '-o build/tests/packages/cext.exe');
check('cext：包自带 C 能力可用', $code === 0 && str_contains($out, 'add=7') && str_contains($out, 'mul=42'), $out);
check('cext：能力汇总列出 cflags 与附加 C 源', str_contains($out, 'cflags:') && str_contains($out, '附加C源:'), $out);

// ---------------------------------------------------------------- 6) shipped
$e = entry('shipped', <<<'PHP'
<?php

#import hello
#import tphp/str
#import demo/native

use Hello\Hello;
use Tphp\Str\Str;
use Demo\Native\Api;

class Main
{
    public function main(): void
    {
        echo Hello::greet("world"), "\n";
        echo Str::csv(["x", "y"]), "\n";
        echo "sum=", Str::sum([1, 2, 3]), "\n";
        echo "add=", Api::add(3, 4), "\n";
        echo "mul=", Api::mul(6, 7), "\n";
    }
}
PHP);
[$out, $code] = compileFrom($root, $e, '-o build/tests/packages/shipped.exe');
check('shipped：ext/hello（纯实现）可用', $code === 0 && str_contains($out, 'hello, world!'), $out);
check('shipped：ext/tphp/str（纯实现）可用', str_contains($out, 'x, y') && str_contains($out, 'sum=6'), $out);
check('shipped：ext/demo/native（C 能力）可用', str_contains($out, 'add=7') && str_contains($out, 'mul=42'), $out);
check('shipped：能力汇总列出全部三个包', str_contains($out, 'hello') && str_contains($out, 'tphp/str') && str_contains($out, 'demo/native'), $out);
check('shipped：无泄漏', !str_contains($out, 'leaks=') || str_contains($out, 'leaks=0'), $out);

// ---------------------------------------------------------------- 7) foreign
// 项目自带 ext/ 不被识别：包来源唯一（编译器目录 ext/）
$proj = $root . '/build/tests/packages/foreign';
rrmdir($proj);
put($proj, 'main.php', <<<'PHP'
<?php

#import foreign/pkg

use Foreign\Pkg\P;

class Main
{
    public function main(): void
    {
        echo P::v(), "\n";
    }
}
PHP);
put($proj, 'ext/foreign/pkg/mod.php', "<?php\n\n// @package foreign/pkg\n");
put($proj, 'ext/foreign/pkg/src/P.php', "<?php\n\nnamespace Foreign\\Pkg;\n\nfinal class P\n{\n    public static function v(): int\n    {\n        return 1;\n    }\n}\n");
[$out, $code] = compileFrom($proj, $proj . '/main.php', '-o ' . $proj . '/main.exe');
check('foreign：项目自带 ext/ 不被识别（包来源唯一）', $code !== 0 && str_contains($out, '未找到'), $out);

// ---------------------------------------------------------------- 8) cwd-free
// 换任意 CWD（这里用 build/tests/packages）运行，编译器 ext 的包照旧可用
[$out, $code] = compileFrom($work, $e, '-o ' . $work . '/shipped2.exe');
check('cwd-free：换 CWD 后包仍可用', $code === 0 && str_contains($out, 'hello, world!'), $out);

echo "\n{$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
