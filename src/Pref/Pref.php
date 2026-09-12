<?php

declare(strict_types=1);

namespace Tphp\Pref;

/**
 * 编译选项。
 *
 * 用法（doc/compiler.md）：
 *   php main.php [build|run|shared] [file.php ... | .] [指令]
 *
 * 动作命令为位置参数（非 .php 后缀的 build/run/shared 词）；缺省 = build。
 * `.` 展开为当前目录的全部 *.php 文件（按名称排序）。
 */
final class Pref
{
    /** 动作命令词（位置参数识别；文件必须 .php，天然消歧）。 */
    public const ACTIONS = ['build', 'run', 'shared'];

    /** 支持的目标平台（自带 TCC 交叉编译器覆盖这些组合）。 */
    public const TARGETS = [
        'windows' => ['x86_64', 'i386'],
        'linux' => ['x86_64', 'arm64'],
        'darwin' => ['x86_64', 'arm64'],
    ];

    /**
     * @param list<string> $inputs 输入 .php 文件（`.` 已展开）
     * @param list<string> $cflags 透传给 C 编译器的额外参数
     * @param list<string> $errors 参数用法错误
     */
    private function __construct(
        public readonly string $action,        // build | run | shared
        public readonly array $inputs,
        public readonly ?string $output,
        public readonly bool $emitC,
        public readonly bool $run,             // action === 'run'
        public readonly string $cc,
        public readonly string $os,
        public readonly string $arch,
        public readonly bool $noMain,
        public readonly bool $shared,          // action === 'shared'
        public readonly array $cflags,
        public readonly bool $memStats,
        public readonly bool $help,
        public readonly bool $version,
        public readonly array $errors,
    ) {}

    public static function parse(array $argv): self
    {
        $inputs = [];
        $output = null;
        $emitC = false;
        $action = '';
        $runFlag = false;
        $sharedFlag = false;
        $memStats = false;
        $cc = 'tcc';
        $noMain = false;
        $cflags = [];
        $help = false;
        $version = false;
        $errors = [];
        $explicitOs = null;
        $explicitArch = null;

        $args = array_slice($argv, 1);
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '-h' || $arg === '--help') {
                $help = true;
            } elseif ($arg === '-v' || $arg === '--version') {
                $version = true;
            } elseif ($arg === '--emit-c') {
                $emitC = true;
            } elseif ($arg === '--mem-stats') {
                $memStats = true;
            } elseif ($arg === '--no-main') {
                $noMain = true;
            } elseif ($arg === 'build' || $arg === 'run' || $arg === 'shared') {
                // 动作命令（位置参数；文件必须是 *.php，非 *.php 的动作词无歧义）
                if ($action === '') {
                    $action = $arg;
                } else {
                    $errors[] = "动作命令重复：{$action} 与 {$arg}";
                }
            } elseif ($arg === '-o') {
                if ($i + 1 >= $count) {
                    $errors[] = '-o 需要一个参数：-o <输出路径>';
                    break;
                }
                $output = $args[++$i];
            } elseif ($arg === '--cc') {
                if ($i + 1 >= $count) {
                    $errors[] = '--cc 需要一个参数：--cc <name>';
                    break;
                }
                $cc = $args[++$i];
            } elseif ($arg === '--cflag') {
                if ($i + 1 >= $count) {
                    $errors[] = '--cflag 需要一个参数：--cflag <arg>';
                    break;
                }
                $cflags[] = $args[++$i];
            } elseif ($arg === '-os' || $arg === '-arch') {
                if ($i + 1 >= $count) {
                    $errors[] = "{$arg} 需要一个参数";
                    break;
                }
                if ($arg === '-os') {
                    $explicitOs = $args[++$i];
                } else {
                    $explicitArch = $args[++$i];
                }
            } elseif (str_starts_with($arg, '-o') && $arg !== '-os' && $arg !== '-arch') {
                $output = substr($arg, 2);
            } elseif (str_starts_with($arg, '-')) {
                $errors[] = "未知选项：{$arg}";
            } elseif ($arg === '.' || $arg === './') {
                // 当前目录**递归**收集全部 *.php（跳过 vendor/build/.git 与隐藏目录），按路径排序
                $files = self::phpFilesRecursive('.');
                if ($files === []) {
                    $errors[] = '当前目录没有 .php 文件';
                }
                foreach ($files as $file) {
                    $inputs[] = $file;
                }
            } elseif (strtolower(substr($arg, -4)) !== '.php') {
                $errors[] = "文件必须是 *.php 文件：{$arg}";
            } else {
                $inputs[] = $arg;
            }
        }

        // 动作归一：位置命令，缺省 build
        if ($action === '') {
            $action = 'build';
        }
        if ($action === 'shared') {
            $sharedFlag = true;
            $noMain = true; // 库模式不需要入口
        }
        $runFlag = $action === 'run';

        if (!$help && !$version && $inputs === []) {
            $errors[] = '缺少输入文件（一个或多个 .php 文件，或 . 表示当前目录）';
        }

        if (!in_array($cc, ['tcc', 'gcc', 'clang'], true)) {
            $errors[] = "不支持的 C 编译器：{$cc}（可选 tcc / gcc / clang）";
        }

        // 目标解析：默认本机系统 + 本机架构；只指定 -os 时架构默认 x86_64
        [$os, $arch] = self::resolveTarget($explicitOs, $explicitArch);

        if (!isset(self::TARGETS[$os])) {
            $errors[] = '不支持的目标系统：' . $os . '（可选 ' . implode(' / ', array_keys(self::TARGETS)) . '）';
        } elseif (!in_array($arch, self::TARGETS[$os], true)) {
            $errors[] = "目标 {$os} 不支持体系结构 {$arch}（可选 " . implode(' / ', self::TARGETS[$os]) . '）';
        }

        return new self($action, $inputs, $output, $emitC, $runFlag, $cc, $os, $arch, $noMain, $sharedFlag, $cflags, $memStats, $help, $version, $errors);
    }

    /**
     * 递归收集目录下全部 *.php（`.` 指令）。
     * 跳过 vendor / build / ext / .git 与任何以 `.` 开头的目录（隐藏），按路径排序保证稳定顺序。
     * （ext/ 是包目录：只由 `#import` 拉取，避免与目录扫描重复收集。）
     *
     * @return list<string>
     */
    private static function phpFilesRecursive(string $dir): array
    {
        $out = [];
        $skipDirs = ['vendor', 'build', 'ext', '.git'];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $f->getPathname());
            $skip = false;
            foreach (explode('/', $path) as $seg) {
                if (in_array($seg, $skipDirs, true) || (str_starts_with($seg, '.') && $seg !== '.')) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $out[] = $path;
            }
        }
        sort($out);
        return $out;
    }

    /** @return array{string, string} */
    private static function resolveTarget(?string $explicitOs, ?string $explicitArch): array
    {
        $hostOs = strtolower(PHP_OS_FAMILY);
        $hostArch = self::normalizeArch(php_uname('m'));

        if ($explicitOs !== null) {
            $os = $explicitOs;
            // 交叉编译默认 x86_64；显式给了 -arch 则用之
            $arch = $explicitArch ?? 'x86_64';
        } else {
            $os = isset(self::TARGETS[$hostOs]) ? $hostOs : 'windows';
            $arch = $explicitArch
                ?? ($hostArch !== '' && in_array($hostArch, self::TARGETS[$os] ?? [], true) ? $hostArch : 'x86_64');
        }
        return [$os, $arch];
    }

    /** 机器名 → 规范架构名。 */
    public static function normalizeArch(string $machine): string
    {
        $m = strtolower($machine);
        return match (true) {
            in_array($m, ['x86_64', 'amd64', 'x64'], true) => 'x86_64',
            in_array($m, ['arm64', 'aarch64'], true) => 'arm64',
            in_array($m, ['i386', 'i486', 'i586', 'i686', 'x86'], true) => 'i386',
            default => '',
        };
    }

    public static function usage(): string
    {
        $logo = <<<TXT

  _______             ____  __  ______ 
 /_  __(_)___  __  __/ __ \/ / / / __ \
  / / / / __ \/ / / / /_/ / /_/ / /_/ /
 / / / / / / / /_/ / ____/ __  / ____/ 
/_/ /_/_/ /_/\__, /_/   /_/ /_/_/      
            /____/          

TXT;
        // logo 用中国红（国旗红 #DE2910）；echoRgb 在非终端/管道输出时自动退化为纯文本
        return echoRgb($logo, 222, 41, 16) . <<<TXT

用法: php main.php [命令] [file.php ... | .] [指令]

命令:
    build         编译为可执行文件（默认）
    run           编译后立即运行生成的可执行文件（仅本机目标）
    shared        编译为动态库（.dll/.so），隐含 --no-main

指令:
    -o <path>     输出路径（默认当前目录 <入口文件名>[.exe]；C 源码默认输出到 build/，-o 时与产物同目录）
    --emit-c      只生成 C 源码，不调用 C 编译器
    --mem-stats   退出时打印内存分配/释放统计（leaks=N）
    --cc <name>   C 编译器：tcc（默认）/ gcc / clang
    -os <name>    目标系统：windows / linux / darwin（macOS 宿主默认 darwin）
    -arch <name>  目标架构：x86_64（默认）/ i386(windows) / arm64(linux)
    --no-main     不生成 main()（库模式，供固件/宿主程序调用）
    --cflag <arg> 透传参数给 C 编译器（可多次使用）

帮助:
    -h, --help    显示帮助
    -v, --version 显示版本

    
TXT;
    }
}
