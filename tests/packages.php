<?php

declare(strict_types=1);

/**
 * 包管理测试：#import 自研 ext（含 C 能力声明）与 #php 的门槛检测。
 *
 * 每个用例在 build/tests/pkg_<name>/ 建一个**独立临时工程**（自带 ext/），
 * 以该目录为 CWD 运行编译器（项目 ext/ 优先于编译器自带 ext/）。
 *
 *   1) basic    —— 基本装配：ext 包 + 命名空间调用 + 能力汇总
 *   2) nested   —— 包内 import 另一个包（传递依赖）
 *   3) cycle    —— 环依赖 → 编译错误
 *   4) missing  —— 包不存在 → 编译错误（附搜索路径与可用包）
 *   5) cext     —— 包自带 C 能力（#include/#flag/.c，路径按包根解析）
 *   6) phpgate  —— #php 门槛：未就绪必须显式报错，不静默通过
 *   7) shipped  —— 仓库自带的示例包（ext/tphp/str、ext/demo/native）可用
 *   8) override —— 项目 ext/ 覆盖编译器自带 ext/（同名包项目优先）
 *
 * 用法：php tests/packages.php
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
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

/** 建临时工程目录（清空重建），返回目录路径。 */
function project(string $name): string
{
    global $root;
    $dir = $root . '/build/tests/pkg_' . $name;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
    }
    @mkdir($dir, 0777, true);
    return $dir;
}

function put(string $dir, string $rel, string $content): void
{
    $path = $dir . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

/** 在 $dir 内编译（CWD=$dir），返回 [输出, 退出码]。 */
function compileIn(string $dir, string $entry = '.', string $extra = ''): array
{
    global $root, $php;
    $cmd = 'cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg($php) . ' '
        . escapeshellarg($root . '/main.php') . ' run ' . escapeshellarg($entry)
        . ' ' . $extra . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
}

// ---------------------------------------------------------------- 1) basic
$d = project('basic');
put($d, 'main.php', <<<'PHP'
<?php

#import tphp/demo

use Tphp\Demo\Util;

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
put($d, 'ext/tphp/demo/mod.php', "<?php\n\n// @package tphp/demo\n// @version 0.1.0\n");
put($d, 'ext/tphp/demo/src/Util.php', <<<'PHP'
<?php

namespace Tphp\Demo;

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
[$out, $code] = compileIn($d);
check('basic：编译并运行成功', $code === 0 && str_contains($out, 'a-b'), $out);
check('basic：跨包调用（sum）', str_contains($out, 'sum=6'), $out);
check('basic：能力汇总打印包来源', str_contains($out, '[TinyPHP 能力汇总]') && str_contains($out, '#import tphp/demo'), $out);
check('basic：纯自研包标注无 C 能力', str_contains($out, '无 C 能力'), $out);
check('basic：无泄漏', str_contains($out, 'leaks=0') || !str_contains($out, 'leaks='), $out);

// ---------------------------------------------------------------- 2) nested
$d = project('nested');
put($d, 'main.php', <<<'PHP'
<?php

#import demo/a

use Demo\A\Top;
use Demo\B\Base;

class Main
{
    public function main(): void
    {
        echo Top::name(), "\n";
        echo Base::tag(), "\n";
    }
}
PHP);
put($d, 'ext/demo/a/mod.php', "<?php\n\n// @package demo/a\n\n#import demo/b\n");
put($d, 'ext/demo/a/src/Top.php', <<<'PHP'
<?php

namespace Demo\A;

final class Top
{
    public static function name(): string
    {
        return "top";
    }
}
PHP);
put($d, 'ext/demo/b/mod.php', "<?php\n\n// @package demo/b\n");
put($d, 'ext/demo/b/src/Base.php', <<<'PHP'
<?php

namespace Demo\B;

final class Base
{
    public static function tag(): string
    {
        return "base";
    }
}
PHP);
[$out, $code] = compileIn($d);
check('nested：传递依赖被自动装配', $code === 0 && str_contains($out, 'top') && str_contains($out, 'base'), $out);

// ---------------------------------------------------------------- 3) cycle
$d = project('cycle');
put($d, 'main.php', <<<'PHP'
<?php

#import demo/x

class Main
{
    public function main(): void
    {
        echo "x", "\n";
    }
}
PHP);
put($d, 'ext/demo/x/mod.php', "<?php\n\n// @package demo/x\n\n#import demo/y\n");
put($d, 'ext/demo/y/mod.php', "<?php\n\n// @package demo/y\n\n#import demo/x\n");
[$out, $code] = compileIn($d);
check('cycle：环依赖被拒绝', $code !== 0 && str_contains($out, '循环'), $out);

// ---------------------------------------------------------------- 4) missing
$d = project('missing');
put($d, 'main.php', <<<'PHP'
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
[$out, $code] = compileIn($d);
check('missing：包不存在时显式报错', $code !== 0 && str_contains($out, '未找到'), $out);
check('missing：错误含搜索路径与可用包', str_contains($out, '已搜索') && str_contains($out, '可用包'), $out);

// ---------------------------------------------------------------- 5) cext
$d = project('cext');
put($d, 'main.php', <<<'PHP'
<?php

#import demo/capi

use Demo\Capi\Api;

class Main
{
    public function main(): void
    {
        echo "add=", Api::add(3, 4), "\n";
        echo "mul=", Api::mul(6, 7), "\n";
    }
}
PHP);
put($d, 'ext/demo/capi/mod.php', <<<'PHP'
<?php

// @package demo/capi
// @desc 自带 C 能力（#include / #flag 即能力声明，相对路径按包根解析）

#include "capi.h"
#flag -Iinclude
#flag src/capi.c
PHP);
put($d, 'ext/demo/capi/include/capi.h', <<<'C'
#ifndef CAPI_H
#define CAPI_H

#include <stdint.h>

int32_t capi_add(int32_t a, int32_t b);
int32_t capi_mul(int32_t a, int32_t b);

#endif
C);
put($d, 'ext/demo/capi/src/capi.c', <<<'C'
#include "capi.h"

int32_t capi_add(int32_t a, int32_t b) { return a + b; }
int32_t capi_mul(int32_t a, int32_t b) { return a * b; }
C);
put($d, 'ext/demo/capi/src/Api.php', <<<'PHP'
<?php

namespace Demo\Capi;

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
[$out, $code] = compileIn($d);
check('cext：包自带 C 能力可用', $code === 0 && str_contains($out, 'add=7') && str_contains($out, 'mul=42'), $out);
check('cext：能力汇总列出 cflags 与附加 C 源', str_contains($out, 'cflags:') && str_contains($out, '附加C源:'), $out);

// ---------------------------------------------------------------- 6) phpgate
$d = project('phpgate');
put($d, 'main.php', <<<'PHP'
<?php

#php json

class Main
{
    public function main(): void
    {
        echo "x", "\n";
    }
}
PHP);
[$out, $code] = compileIn($d);
// M1 只做门槛：未就绪必须显式报错（不得静默成功）
check(
    'phpgate：#php 未就绪时显式报错（不静默）',
    $code !== 0 && (str_contains($out, 'libphp') || str_contains($out, '未定义')),
    $out,
);

// ---------------------------------------------------------------- 7) shipped
// 仓库**自带**的示例包（ext/tphp/str 纯实现 + ext/demo/native 带 C 能力），在仓库根编译
$entry = $root . '/build/tests/pkg_shipped.php';
@mkdir(dirname($entry), 0777, true);
file_put_contents($entry, <<<'PHP'
<?php

#import tphp/str
#import demo/native

use Tphp\Str\Str;
use Demo\Native\Api;

class Main
{
    public function main(): void
    {
        echo Str::csv(["x", "y"]), "\n";
        echo "sum=", Str::sum([1, 2, 3]), "\n";
        echo "add=", Api::add(3, 4), "\n";
        echo "mul=", Api::mul(6, 7), "\n";
    }
}
PHP);
[$out, $code] = compileIn($root, 'build/tests/pkg_shipped.php');
check('shipped：自带 ext/tphp/str 可用（纯实现）', $code === 0 && str_contains($out, 'x, y') && str_contains($out, 'sum=6'), $out);
check('shipped：自带 ext/demo/native 可用（C 能力）', str_contains($out, 'add=7') && str_contains($out, 'mul=42'), $out);
check('shipped：能力汇总列出两个包', str_contains($out, 'tphp/str') && str_contains($out, 'demo/native'), $out);
check('shipped：无泄漏', !str_contains($out, 'leaks=') || str_contains($out, 'leaks=0'), $out);

// ---------------------------------------------------------------- 8) override
// 项目 ext/ 优先于编译器自带 ext/：同名包 tphp/str 由**项目版**生效
$d = project('override');
put($d, 'main.php', <<<'PHP'
<?php

#import tphp/str

use Tphp\Str\Str;

class Main
{
    public function main(): void
    {
        echo Str::csv(["p"]), "\n";
    }
}
PHP);
put($d, 'ext/tphp/str/mod.php', "<?php\n\n// @package tphp/str（项目版，覆盖编译器自带）\n");
put($d, 'ext/tphp/str/src/Str.php', <<<'PHP'
<?php

namespace Tphp\Str;

final class Str
{
    public static function csv(array<string> $parts): string
    {
        return "project:" . implode(",", $parts);
    }
}
PHP);
[$out, $code] = compileIn($d);
check('override：项目 ext/ 覆盖编译器自带包', $code === 0 && str_contains($out, 'project:p'), $out);

echo "\n{$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
