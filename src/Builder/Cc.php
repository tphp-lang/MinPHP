<?php

declare(strict_types=1);

namespace Tphp\Builder;

use Tphp\Install\Paths;
use Tphp\Pref\Pref;

/**
 * 调用 C 编译器把生成的 .c 变成产物。
 *
 * 默认用随包 TCC，按 **host × target** 选择二进制（TCC 的目标平台在编译 tcc 自身时
 * 由 TCC_TARGET_* 宏决定，交叉编译器是按目标命名的独立二进制，映射见 TCC.md）：
 *
 *   | host \ target        | windows x64         | windows i386            | linux x86_64     | linux arm64      | macOS（本机）     |
 *   |----------------------|--------------------|-------------------------|------------------|------------------|------------------|
 *   | windows              | tcc/tcc.exe        | tcc/i386-win32-tcc.exe  | tcc/x86_64-tcc.exe | tcc/arm64-tcc.exe | —                |
 *   | linux                | tcc/x86_64-win32-tcc | tcc/i386-win32-tcc    | tcc/tcc（仅同构） | tcc/tcc（仅同构） | —                |
 *   | macOS                | tcc/x86_64-win32-tcc | tcc/i386-win32-tcc    | tcc/x86_64-tcc   | tcc/arm64-tcc    | tcc/tcc（本机 Mach-O） |
 *
 * Windows/linux 交叉产物静态链接（musl / PE），不依赖目标机 libc；
 * 也可以 --cc gcc/clang 并用 --cflag 透传交叉参数。找不到随包 TCC 时**显式报错**
 * （不再静默退回 PATH——发布包必须完整）。
 */
final class Cc
{
    public static function compile(Pref $pref, string $cFile, string $exeFile, string $runtimeDir, array $cflags = [], array $extraSources = []): ?string
    {
        $tcc = null;
        if ($pref->cc === 'tcc') {
            $tcc = self::tccBinary($pref);
            if ($tcc === null) {
                return null;
            }
        }
        // Windows 可执行文件必须带 .exe：原生 tcc 即使 -o 无扩展也会自动产出 .exe，
        // 且 cmd 不会为带引号的路径补扩展名、也不会执行无扩展名文件。这里把输出路径
        // 显式归一化，保证传给 tcc 的 -o 与返回的产物路径同磁盘真实文件一致（--run 才能找到）。
        // 动态库同理补 .dll。非 Windows 目标可执行文件无扩展名，保持原样。
        if ($pref->os === 'windows') {
            $ext = $pref->shared ? '.dll' : '.exe';
            if (!str_ends_with(strtolower($exeFile), $ext)) {
                $exeFile .= $ext;
            }
        }

        $cmd = self::buildCommand($pref, $cFile, $exeFile, $runtimeDir, $cflags, $extraSources, $tcc);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        echo "> {$escaped}\n";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($escaped, $descriptors, $pipes);
        if (!is_resource($proc)) {
            fwrite(STDERR, "TinyPHP: 无法启动 C 编译器\n");
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($stdout !== '' && $stdout !== false) {
            echo $stdout;
        }
        if ($stderr !== '' && $stderr !== false) {
            fwrite(STDERR, $stderr);
        }
        if ($code !== 0) {
            fwrite(STDERR, "TinyPHP: C 编译失败（退出码 {$code}）\n");
            return null;
        }
        return $exeFile;
    }

    /** @return list<string> */
    private static function buildCommand(Pref $pref, string $cFile, string $exeFile, string $runtimeDir, array $cflags = [], array $extraSources = [], ?string $tcc = null): array
    {
        $cmd = [$pref->cc === 'tcc' ? ($tcc ?? 'tcc') : $pref->cc];
        $cmd[] = '-o';
        $cmd[] = $exeFile;
        $cmd[] = '-I';
        $cmd[] = $runtimeDir;
        if ($pref->shared) {
            $cmd[] = '-shared';
            // TCC 的 PE 产物默认不导出普通符号，需显式开启（Linux .so 限制见 doc/phpc.md）
            if ($pref->os === 'windows') {
                $cmd[] = '-Wl,--export-all-symbols';
            }
        }
        // #flag 参数（用户 --cflag 之后追加 .c 附加源文件；
        // .c 项从 flag 中剔除——已提升为 extraSources，避免重复编译）
        foreach (array_merge($cflags, $pref->cflags) as $flag) {
            if (str_ends_with($flag, '.c')) {
                continue;
            }
            $cmd[] = $flag;
        }
        foreach ($extraSources as $src) {
            $cmd[] = $src;
        }
        $cmd[] = $cFile;
        // Linux/macOS 的 libm（pow/sqrt 等数学符号）不在默认链接集，需显式 -lm；
        // 且 -lm 必须排在引用它的目标文件（即 $cFile）之后。Windows 的 msvcrt 已内联这些符号，无需 -lm。
        if ($pref->os !== 'windows') {
            $cmd[] = '-lm';
        }
        return $cmd;
    }

    /** 按 host × target 选择随包 TCC 二进制；不可用返回 null（已打印错误）。 */
    private static function tccBinary(Pref $pref): ?string
    {
        $dir = Paths::root() . '/tcc';
        $hostOs = strtolower(PHP_OS_FAMILY); // windows / linux / darwin
        $hostArch = Pref::normalizeArch(php_uname('m'));
        $suffix = $hostOs === 'windows' ? '.exe' : '';
        $target = $pref->os . '/' . $pref->arch;

        if ($pref->os === 'windows') {
            if ($pref->arch === 'i386') {
                return $dir . '/i386-win32-tcc' . $suffix;
            }
            return $hostOs === 'windows' ? $dir . '/tcc.exe' : $dir . '/x86_64-win32-tcc' . $suffix;
        }

        if ($pref->os === 'linux') {
            if ($hostOs === 'linux') {
                // native TCC 的目标架构在编译期已定，只能产出本机架构
                if ($hostArch !== $pref->arch) {
                    return self::noTcc($target, $hostOs . '/' . ($hostArch === '' ? '?' : $hostArch), 'Linux 主机的 native TCC 只能产出本机架构的 ELF');
                }
                return $dir . '/tcc';
            }
            return $dir . ($pref->arch === 'arm64' ? '/arm64-tcc' . $suffix : '/x86_64-tcc' . $suffix);
        }

        if ($pref->os === 'darwin') {
            // macOS 目标只能在 macOS 宿主上编译：随包原生 tcc 产出本机架构的 Mach-O
            // （交叉编译器按目标命名，但 macOS 没有对应的交叉 tcc）。
            if ($hostOs !== 'darwin') {
                return self::noTcc($target, $hostOs, 'macOS 目标只能在 macOS 宿主上编译（需原生 tcc 产出 Mach-O）');
            }
            return $dir . '/tcc';
        }

        return self::noTcc($target, $hostOs, '不支持的目标系统');
    }

    private static function noTcc(string $target, string $host, string $why): null
    {
        fwrite(STDERR, "TinyPHP: 没有可用于 目标 {$target}（host {$host}）的随包 TCC —— {$why}\n");
        fwrite(STDERR, "TinyPHP: 请确认发布包完整（应含 tcc/ 目录），或用 --cc 指定外部编译器\n");
        return null;
    }
}
