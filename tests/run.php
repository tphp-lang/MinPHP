<?php

declare(strict_types=1);

/**
 * TinyPHP 测试架。
 *
 * 单文件用例：tests/cases/<name>.php，头部 `// expect:` 块即预期输出；
 * `// expect-error: <子串>` 表示期望编译失败且错误信息含该子串；
 * `// expect-panic: <子串>` 表示期望运行时 panic（退出码 1，stderr 含该子串，
 *   不做 mem-stats 断言——panic 直接 exit(1) 不打印统计）。
 * 多文件用例：tests/multi/<name>/ 目录（main.php 为入口，其余 .php 为辅助文件），
 * 预期块写在 main.php 头部。
 *
 * 用法：php tests/run.php [用例名过滤]
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$casesDir = $root . '/tests/cases';
$multiDir = $root . '/tests/multi';
$filter = $argv[1] ?? null;

/** @var array<string, array{expected: string, inputs: list<string>}> 名字 → 用例 */
$cases = [];

foreach (glob($casesDir . '/*.php') ?: [] as $file) {
    $name = basename($file, '.php');
    if ($filter !== null && !str_contains($name, $filter)) {
        continue;
    }
    $cases[$name] = ['expected' => extractExpected($file), 'inputs' => [$file], 'expectError' => extractExpectError($file), 'expectPanic' => extractExpectPanic($file)];
}

foreach (glob($multiDir . '/*/main.php') ?: [] as $main) {
    $name = basename(dirname($main));
    if ($filter !== null && !str_contains($name, $filter)) {
        continue;
    }
    $inputs = [$main];
    foreach (glob(dirname($main) . '/*.php') ?: [] as $extra) {
        if (realpath($extra) !== realpath($main)) {
            $inputs[] = $extra;
        }
    }
    $cases[$name] = ['expected' => extractExpected($main), 'inputs' => $inputs, 'expectError' => extractExpectError($main), 'expectPanic' => extractExpectPanic($main)];
}

/** 提取文件头 `// expect-panic: <子串>`（期望运行时 panic：退出码 1）。 */
function extractExpectPanic(string $file): ?string
{
    foreach (file($file) ?: [] as $line) {
        $trim = trim($line);
        if (str_starts_with($trim, '// expect-panic:')) {
            $msg = trim(substr($trim, strlen('// expect-panic:')));
            return $msg === '' ? null : $msg;
        }
        if ($trim === '// expect:') {
            break;
        }
    }
    return null;
}

/** 提取文件头 `// expect:` 块。 */
function extractExpected(string $file): string
{
    $expected = [];
    $inBlock = false;
    foreach (file($file) ?: [] as $line) {
        $trim = trim($line);
        if ($trim === '// expect:') {
            $inBlock = true;
            continue;
        }
        if ($inBlock) {
            if (str_starts_with($trim, '// ')) {
                $expected[] = substr($trim, 3);
                continue;
            }
            break; // expect 块结束
        }
    }
    return implode("\n", $expected) . "\n";
}

/** 提取文件头 `// expect-error: <子串>`（期望编译失败）。 */
function extractExpectError(string $file): ?string
{
    foreach (file($file) ?: [] as $line) {
        $trim = trim($line);
        if (str_starts_with($trim, '// expect-error:')) {
            $msg = trim(substr($trim, strlen('// expect-error:')));
            return $msg === '' ? null : $msg;
        }
        if ($trim === '// expect:') {
            break; // 正常运行用例
        }
    }
    return null;
}

if ($cases === []) {
    fwrite(STDERR, "没有找到测试用例\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$builtDir = $root . '/build/tests';
if (!is_dir($builtDir)) {
    mkdir($builtDir, 0777, true);
}

foreach ($cases as $name => $case) {
    $exe = $builtDir . '/' . $name . '.exe';
    @unlink($exe);

    // 编译（直接调用 CLI，测试真实路径）
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/main.php');
    foreach ($case['inputs'] as $input) {
        $cmd .= ' ' . escapeshellarg($input);
    }
    $cmd .= ' --mem-stats -o ' . escapeshellarg($exe) . ' 2>&1';
    $out = shell_exec($cmd);

    if ($case['expectError'] !== null) {
        if (is_file($exe)) {
            echo "FAIL {$name}（期望编译失败，但产物已生成）\n{$out}\n";
            $fail++;
        } elseif ($out !== null && str_contains($out, $case['expectError'])) {
            echo "PASS {$name}\n";
            $pass++;
        } else {
            echo "FAIL {$name}（错误信息未包含 \"{$case['expectError']}\"）\n{$out}\n";
            $fail++;
        }
        continue;
    }

    if (!is_file($exe)) {
        echo "FAIL {$name}（编译失败）\n{$out}\n";
        $fail++;
        continue;
    }

    // 运行时 panic 用例：退出码必须为 1，输出含指定子串（panic 直接 exit，无 mem 统计）
    if ($case['expectPanic'] !== null) {
        $po = [];
        $code = 0;
        exec(escapeshellarg($exe) . ' 2>&1', $po, $code);
        $text = implode("\n", $po);
        if ($code === 1 && str_contains($text, $case['expectPanic'])) {
            echo "PASS {$name}\n";
            $pass++;
        } else {
            echo "FAIL {$name}（期望退出码 1 且输出含 \"{$case['expectPanic']}\"，实际退出码 {$code}）\n{$text}\n";
            $fail++;
        }
        continue;
    }

    $actual = shell_exec(escapeshellarg($exe) . ' 2>&1') ?? '';
    if (!preg_match('/leaks=0$/m', $actual)) {
        echo "FAIL {$name}（内存泄漏：" . (preg_match('/leaks=\d+/m', $actual, $m) ? $m[0] : '无统计') . "）\n";
        $fail++;
        continue;
    }
    // 过滤掉 mem 统计行后再比对程序输出
    $actual = implode("\n", array_filter(
        explode("\n", $actual),
        static fn ($l) => !str_starts_with(ltrim($l), 'mem: '),
    ));
    $normalize = static function (string $s): string {
        $lines = explode("\n", str_replace("\r\n", "\n", $s));
        return implode("\n", array_map(static fn ($l) => rtrim($l), $lines));
    };
    if ($normalize($actual) === $normalize($case['expected'])) {
        echo "PASS {$name}\n";
        $pass++;
    } else {
        echo "FAIL {$name}\n--- 期望 ---\n{$case['expected']}--- 实际 ---\n{$actual}\n";
        $fail++;
    }
}

echo "\n{$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
