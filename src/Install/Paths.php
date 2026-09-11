<?php

declare(strict_types=1);

namespace Tphp\Install;

/**
 * 安装根解析：定位**含 `runtime/`、`tcc/`、`ext/` 的那个目录**。
 *
 * 两种运行形态：
 *  - **源码运行**（`php main.php ...`）：仓库根目录；
 *  - **打包运行**（`micro.sfx` + `tphp.phar` 拼成的 `tphp[.exe]`）：**可执行文件所在目录**。
 *
 * 打包形态下这些资源以**真文件随包分发**，运行期零解压 —— TCC 读不了 `phar://`，
 * 也无法执行 phar 内的二进制，所以不能像旧版那样把 runtime/tcc 塞进 phar 再首跑解压。
 *
 * 探测方式：按候选目录依次检查 `runtime/` 与 `tcc/` 是否**同时存在**（自校验），
 * 命中即用；全部落空时回退到源码根（保证源码模式永远可用）。
 */
final class Paths
{
    private static ?string $root = null;

    public static function root(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }
        foreach (self::candidates() as $dir) {
            if (is_dir($dir . '/runtime') && is_dir($dir . '/tcc')) {
                return self::$root = $dir;
            }
        }
        // 兜底：源码根（`src/Install/` 往上两级）
        return self::$root = self::normalize(dirname(__DIR__, 2));
    }

    /**
     * 候选安装根（按可信度排序）。
     *
     * @return list<string>
     */
    public static function candidates(): array
    {
        $out = [];
        // 1) phar 模式：phar 自身路径的所在目录（micro 单文件时即 exe 路径）
        $running = \Phar::running(false);
        if ($running !== '') {
            $out[] = self::normalize(dirname($running));
        }
        // 2) 当前脚本路径（micro 下指向可执行文件）
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (is_string($script) && $script !== '') {
            $real = realpath($script);
            if ($real !== false) {
                $out[] = self::normalize(dirname($real));
            }
        }
        // 3) argv[0]（`php main.php ...` 时为入口脚本路径）
        $argv0 = $_SERVER['argv'][0] ?? '';
        if (is_string($argv0) && $argv0 !== '') {
            $real = realpath($argv0);
            if ($real !== false) {
                $out[] = self::normalize(dirname($real));
            }
        }
        // 4) 源码根
        $out[] = self::normalize(dirname(__DIR__, 2));

        return array_values(array_unique($out));
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
