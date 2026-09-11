<?php

declare(strict_types=1);

namespace Tphp\Builder;

use Tphp\Errors\Errors;
use Tphp\Token\Pos;

/**
 * 包解析：把 `#import <包名>` 展开为待编译的源文件集合。
 *
 * 约定（见 doc/package.md）：
 *   - 包 = **编译器目录的 `ext/<包名>/`**（只此一处，与 CWD 无关）；
 *   - 包名可分层（`tphp/json` → `ext/tphp/json/`）；
 *   - 包内**所有 .php 递归纳入**编译（清单 mod.php 只贡献 #flag/#include 指令）；
 *   - 包之间可互相 `#import`（传递依赖），按 realpath 去重，环依赖报错。
 *
 * 本类只做"文件收集"：这里用轻量行扫描识别 `#import`（避免与 Parser 相互依赖），
 * 真正的语法校验仍由 Parser 完成（收集到的文件随后都会经 Parser 解析）。
 */
final class PackageResolver
{
    /** @var array<string, true> 已展开的包名 */
    private array $expanded = [];

    /** @var array<string, string> 包名 → 包根绝对路径 */
    private array $roots = [];

    /** @var array<string, true> 已收集文件的 realpath（跨包去重） */
    private array $fileSeen = [];

    public function __construct(
        private readonly Errors $errors,
        /** 编译器目录的 ext/（包的唯一来源，与 CWD 无关） */
        private readonly string $extRoot,
    ) {}

    /**
     * 展开一个包（含其传递依赖），返回**新增**的待编译文件。
     *
     * @param list<string> $stack 展开栈（环检测）
     * @return list<array{path: string, src: string}>
     */
    public function expand(string $name, string $originFile, int $originLine = 1, array $stack = []): array
    {
        $pos = new Pos($originFile, $originLine, 1);

        // 名字形式校验（与 Parser::validateImport 同一规则与文案）：
        // 解析器在 Parser 之前运行，若不在这里拦，路径形式会被当成"包不存在"，
        // 既丢失"防越界"的明确语义，错误信息也更差。
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\/[A-Za-z_][A-Za-z0-9_]*)*$/', $name) !== 1) {
            $this->errors->add(
                '#import 只接受包名（字母/数字/下划线，可用 / 分层，如 tphp/json），不支持路径形式；'
                . '得到 "' . $name . '"',
                $pos,
            );
            return [];
        }

        // 环检测必须**先于**"已展开"判定：展开中的包再次出现即真环
        // （先判 expanded 会把环当成"已展开"而静默放过）
        if (in_array($name, $stack, true)) {
            $this->errors->add('包依赖循环：#import ' . implode(' → ', [...$stack, $name]), $pos);
            return [];
        }
        if (isset($this->expanded[$name])) {
            return [];
        }

        $dir = $this->locate($name);
        if ($dir === null) {
            $dirs = implode('、', $this->searchDirs());
            $avail = $this->availablePackages();
            $this->errors->add(
                "#import {$name} 未找到：已搜索 {$dirs}；可用包：" . ($avail === [] ? '（无）' : implode(', ', $avail)),
                $pos,
            );
            return [];
        }

        $this->expanded[$name] = true;
        $this->roots[$name] = $dir;

        $out = [];
        foreach ($this->phpFiles($dir) as $file) {
            if (isset($this->fileSeen[$file])) {
                continue;
            }
            $this->fileSeen[$file] = true;

            $src = (string)file_get_contents($file);
            $out[] = ['path' => $file, 'src' => $src];

            // 传递依赖：包内文件自身的 #import
            foreach ($this->scanImportsIn($src) as [$child, $line]) {
                foreach ($this->expand($child, $file, $line, [...$stack, $name]) as $item) {
                    $out[] = $item;
                }
            }
        }

        if ($out === []) {
            $this->errors->add("#import {$name} 包内没有 .php 源文件：{$dir}", $pos);
        }
        return $out;
    }

    public function isExpanded(string $name): bool
    {
        return isset($this->expanded[$name]);
    }

    /** 包名 → 包根绝对路径（能力汇总用）。 @return array<string, string> */
    public function roots(): array
    {
        return $this->roots;
    }

    /** 搜索目录（诊断信息用）。 @return list<string> */
    public function searchDirs(): array
    {
        return [$this->extRoot];
    }

    /** 搜索目录下可见的包名（错误提示用）。 @return list<string> */
    public function availablePackages(): array
    {
        $names = [];
        foreach ($this->searchDirs() as $dir) {
            if (!is_dir($dir)) {
                continue; // 搜索目录可能不存在（scandir 会抛 Warning）
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                    continue;
                }
                if (is_dir($dir . '/' . $entry)) {
                    $names[$entry] = true;
                    // 一层子包（tphp/json）也提示出来：以 mod.php 清单为准，
                    // 避免把 src/ include/ 之类的实现子目录误列为包
                    foreach (scandir($dir . '/' . $entry) ?: [] as $sub) {
                        if ($sub === '.' || $sub === '..' || str_starts_with($sub, '.')) {
                            continue;
                        }
                        if (is_dir($dir . '/' . $entry . '/' . $sub)
                            && is_file($dir . '/' . $entry . '/' . $sub . '/mod.php')) {
                            $names[$entry . '/' . $sub] = true;
                        }
                    }
                }
            }
        }
        return array_keys($names);
    }

    /** 定位包目录：只在编译器目录的 ext/ 下查找。 */
    private function locate(string $name): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\/[A-Za-z_][A-Za-z0-9_]*)*$/', $name) !== 1) {
            return null; // 非法包名不该到这一步（Parser 已拦）
        }
        foreach ($this->searchDirs() as $root) {
            $candidate = $root . '/' . $name;
            if (is_dir($candidate)) {
                $real = realpath($candidate);
                if ($real !== false) {
                    return str_replace('\\', '/', $real);
                }
            }
        }
        return null;
    }

    /**
     * 包内全部 .php（递归；跳过隐藏目录）。
     *
     * @return list<string> 绝对路径（正斜杠）
     */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !str_starts_with($f->getFilename(), '.'),
            ),
        );
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && strtolower($f->getExtension()) === 'php') {
                $out[] = str_replace('\\', '/', $f->getPathname());
            }
        }
        sort($out);
        return $out;
    }

    /**
     * 轻量扫描 `#import`（行首、仅空白前缀），返回 [包名, 行号]。
     * 完整语法校验由 Parser 负责；Builder 用它收集入口文件的 import。
     *
     * @return list<array{0: string, 1: int}>
     */
    public function scanImportsIn(string $src): array
    {
        $out = [];
        $lines = preg_split('/\r\n|\n|\r/', $src) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^[ \t]*#import[ \t]+(\S+)/', $line, $m) === 1) {
                $out[] = [$m[1], $i + 1];
            }
        }
        return $out;
    }
}
