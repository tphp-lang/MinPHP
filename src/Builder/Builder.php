<?php

declare(strict_types=1);

namespace Tphp\Builder;

use Tphp\Checker\Checker;
use Tphp\Errors\Errors;
use Tphp\Gen\Gen;
use Tphp\Parser\Parser;
use Tphp\Pref\Pref;
use Tphp\Table\Table;

/**
 * 编译流水线：
 *
 *   Pref → Scanner+Parser → Checker（两遍）→ Gen → C 源码 → Cc（TCC/GCC/Clang）
 *
 * 各阶段只通过显式产物衔接：File[] → (Table + 标注后的 AST) → C 字符串。
 */
final class Builder
{
    /** @var list<string> 已展开的包根绝对路径（#flag 相对路径按包根解析） */
    private array $packageDirs = [];

    public function __construct(private readonly Pref $pref) {}

    public function run(): int
    {
        foreach ($this->pref->inputs as $input) {
            if (!is_file($input)) {
                fwrite(STDERR, "TinyPHP: 找不到文件 {$input}\n");
                return 1;
            }
        }

        // 多文件（与旧版机制一致）：入口 = 含全局 class Main 的文件（可只有一个），
        // 第一个参数仅用于输出命名；解析顺序为辅助文件在前、入口在后。
        $entryIndex = null;
        $sources = [];
        foreach ($this->pref->inputs as $idx => $input) {
            $src = file_get_contents($input);
            if ($src === false) {
                fwrite(STDERR, "TinyPHP: 无法读取 {$input}\n");
                return 1;
            }
            if (preg_match('/^\s*class\s+Main\b/m', $src) === 1) {
                if ($entryIndex !== null) {
                    fwrite(STDERR, "TinyPHP: 错误：发现多个包含 class Main 的入口文件（{$this->pref->inputs[$entryIndex]} 与 {$input}）\n");
                    return 1;
                }
                $entryIndex = $idx;
            }
            $sources[$idx] = $src;
        }

        $errors = new Errors();
        $table = new Table();
        $parser = new Parser($errors);

        // 包展开（#import）：把包内源文件并入编译单元（辅助文件，排在入口之前）
        // 包只在**编译器目录**的 ext/ 下查找（与 CWD 无关）
        $resolver = new PackageResolver($errors, dirname(__DIR__, 2) . '/ext');
        $extFiles = $this->expandPackages($resolver, $sources, $this->pref->inputs);
        if ($errors->hasErrors()) {
            return $this->report($errors);
        }

        // 解析顺序：CLI 辅助文件 → 包文件 → 入口（含 class Main）最后
        $ordered = array_keys($sources);
        if ($entryIndex !== null) {
            $ordered = array_diff($ordered, [$entryIndex]);
        }
        $plan = [];
        foreach ($ordered as $idx) {
            $plan[] = [str_replace('\\', '/', $this->pref->inputs[$idx]), $sources[$idx]];
        }
        foreach ($extFiles as $extFile) {
            $plan[] = [$extFile['path'], $extFile['src']];
        }
        if ($entryIndex !== null) {
            $plan[] = [str_replace('\\', '/', $this->pref->inputs[$entryIndex]), $sources[$entryIndex]];
        }

        $files = [];
        foreach ($plan as [$path, $src]) {
            $files[] = $parser->parseFile($path, $src, [
                'os' => $this->pref->os,
                'arch' => $this->pref->arch,
                'cc' => $this->pref->cc,
            ]);
        }
        if ($errors->hasErrors()) {
            return $this->report($errors);
        }

        // 包根目录（#flag 相对路径按包根解析；能力汇总用）
        $this->packageDirs = array_values($resolver->roots());

        $this->reportCapabilities($files, $resolver->roots());

        $entryPath = $entryIndex !== null ? str_replace('\\', '/', $this->pref->inputs[$entryIndex]) : str_replace('\\', '/', $this->pref->inputs[0]);
        (new Checker($table, $errors))->check($files, $entryPath, $this->pref->noMain);
        $this->printWarnings($errors);
        if ($errors->hasErrors()) {
            return $this->report($errors);
        }

        $cSource = (new Gen($table, $errors, $this->pref->noMain, $this->pref->memStats))->generate($files);

        // 输出路径：可执行文件/动态库默认当前目录（根目录）；C 源码默认放 build/ 目录。
        // -o 显式指定输出路径时，C 源码与产物同目录。
        // 命名用入口文件（含全局 class Main 的文件；`.` 展开等场景下与参数顺序无关）。
        $base = pathinfo($this->pref->inputs[$entryIndex ?? 0], PATHINFO_FILENAME);
        if ($this->pref->shared) {
            $exePath = $this->pref->output ?? $base . ($this->pref->os === 'windows' ? '.dll' : '.so');
        } else {
            $exePath = $this->pref->output ?? $base . ($this->pref->os === 'windows' ? '.exe' : '');
        }
        $exeDir = dirname($exePath);
        $exeName = pathinfo($exePath, PATHINFO_FILENAME);
        $cDir = $this->pref->output !== null ? $exeDir : ($exeDir === '.' ? 'build' : $exeDir . '/build');
        $cPath = ($cDir === '.' ? '' : $cDir . '/') . $exeName . '.c';

        $dir = dirname($cPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            fwrite(STDERR, "TinyPHP: 无法创建目录 {$dir}\n");
            return 1;
        }
        file_put_contents($cPath, $cSource);
        echo "生成 C 源码: {$cPath}\n";

        $emitC = $this->pref->emitC;
        if ($this->pref->noMain && !$this->pref->shared && !$emitC) {
            // 库模式没有 main，无法链接可执行文件：退回只输出 C
            echo "提示：--no-main 未生成 main()，仅输出 C 源码（可加 shared 命令生成动态库）\n";
            $emitC = true;
        }
        if ($emitC) {
            return 0;
        }

        $exe = Cc::compile($this->pref, $cPath, $exePath, dirname(__DIR__, 2) . '/runtime', $this->collectCFlags($files), $this->collectFlagSources($files));
        if ($exe === null) {
            return 1;
        }
        echo "编译完成: {$exe}\n";

        if ($this->pref->run) {
            if (!$this->canRunHere()) {
                fwrite(STDERR, "TinyPHP: 无法在本机运行 {$this->pref->os}/{$this->pref->arch} 目标的产物\n");
                return 1;
            }
            return $this->runExe($exe);
        }
        return 0;
    }

    /**
     * 展开全部 `#import`（含传递依赖），返回包内待编译文件。
     *
     * @param array<int, string> $sources CLI 输入源文本（idx => src）
     * @param list<string> $inputs CLI 输入路径
     * @return list<array{path: string, src: string}>
     */
    private function expandPackages(PackageResolver $resolver, array $sources, array $inputs): array
    {
        $out = [];
        $queue = [];
        foreach ($sources as $idx => $src) {
            foreach ($resolver->scanImportsIn($src) as [$name, $line]) {
                $queue[] = [$name, str_replace('\\', '/', $inputs[$idx]), $line];
            }
        }
        while ($queue !== []) {
            [$name, $origin, $line] = array_shift($queue);
            if ($resolver->isExpanded($name)) {
                continue;
            }
            foreach ($resolver->expand($name, $origin, $line) as $file) {
                $out[] = $file;
                // 传递依赖兜底（expand 内部已递归；此处防止轻量扫描遗漏）
                foreach ($resolver->scanImportsIn($file['src']) as [$child, $childLine]) {
                    if (!$resolver->isExpanded($child)) {
                        $queue[] = [$child, $file['path'], $childLine];
                    }
                }
            }
        }
        return $out;
    }

    /** 收集全部文件的 #flag 参数。 @return list<string> */
    private function collectCFlags(array $files): array
    {
        $flags = [];
        foreach ($files as $file) {
            $base = $this->packageBaseOf($file->path);
            foreach ($file->cflags as $flag) {
                $flags[] = $base === null ? $flag : $this->rebaseFlag($flag, $base);
            }
        }
        return $flags;
    }

    /**
     * 收集 #flag 中引用的 .c 源文件（加入编译列表）。
     * 包内文件的相对路径**相对包根**解析；项目内其它文件保持既有 CWD 语义。
     *
     * @return list<string>
     */
    private function collectFlagSources(array $files): array
    {
        $sources = [];
        foreach ($files as $file) {
            $base = $this->packageBaseOf($file->path);
            foreach ($file->cflags as $flag) {
                foreach (preg_split('/\s+/', trim($flag)) ?: [] as $token) {
                    if (!str_ends_with($token, '.c')) {
                        continue;
                    }
                    $path = $base === null ? $token : $base . '/' . ltrim(str_replace('\\', '/', $token), '/');
                    if (!is_file($path)) {
                        fwrite(STDERR, "TinyPHP: {$file->path}: #flag 引用的源文件不存在 {$token}"
                            . ($base !== null ? "（按包根解析为 {$path}）" : '') . "\n");
                        exit(1);
                    }
                    $sources[] = $path;
                }
            }
        }
        return $sources;
    }

    /** 文件所属包根（不在任何包内返回 null）。 */
    private function packageBaseOf(string $filePath): ?string
    {
        $path = str_replace('\\', '/', $filePath);
        foreach ($this->packageDirs as $dir) {
            if (str_starts_with($path, $dir . '/')) {
                return $dir;
            }
        }
        return null;
    }

    /** 把 #flag 行内的相对路径改写成相对包根（-I/-L/裸 .c .h .o .a）。 */
    private function rebaseFlag(string $flag, string $base): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($flag)) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            $out[] = match (true) {
                str_starts_with($tok, '-I') && strlen($tok) > 2 => '-I' . $this->rebasePath(substr($tok, 2), $base),
                str_starts_with($tok, '-L') && strlen($tok) > 2 => '-L' . $this->rebasePath(substr($tok, 2), $base),
                preg_match('/\.(c|h|o|a)$/', $tok) === 1 => $this->rebasePath($tok, $base),
                default => $tok,
            };
        }
        return implode(' ', $out);
    }

    /** 相对路径 → 相对包根（绝对路径原样返回；去引号后按需补回）。 */
    private function rebasePath(string $p, string $base): string
    {
        $quoted = strlen($p) >= 2 && $p[0] === '"' && str_ends_with($p, '"');
        $raw = $quoted ? substr($p, 1, -1) : $p;
        $norm = str_replace('\\', '/', $raw);
        if ($norm !== '' && !str_starts_with($norm, '/') && preg_match('/^[A-Za-z]:/', $norm) !== 1) {
            $norm = $base . '/' . $norm;
        }
        return $quoted ? '"' . $norm . '"' : $norm;
    }

    /**
     * 编译期能力汇总（供应链透明）：列出各包声明的 C 能力。
     *
     * @param array<string, string> $roots 包名 → 包根
     */
    private function reportCapabilities(array $files, array $roots): void
    {
        if ($roots === []) {
            return;
        }
        echo "[TinyPHP 能力汇总]\n";
        foreach ($roots as $name => $dir) {
            $flags = [];
            $srcs = [];
            foreach ($files as $file) {
                if (!str_starts_with(str_replace('\\', '/', $file->path), $dir . '/')) {
                    continue;
                }
                foreach ($file->cflags as $flag) {
                    foreach (preg_split('/\s+/', trim($flag)) ?: [] as $tok) {
                        if ($tok === '') {
                            continue;
                        }
                        if (str_ends_with($tok, '.c')) {
                            $srcs[$tok] = true;
                        } else {
                            $flags[$tok] = true;
                        }
                    }
                }
            }
            $detail = [];
            if ($flags !== []) {
                $detail[] = 'cflags: ' . implode(' ', array_keys($flags));
            }
            if ($srcs !== []) {
                $detail[] = '附加C源: ' . implode(' ', array_keys($srcs));
            }
            printf("  #import %-18s %s\n", $name, $detail === [] ? '（纯自研实现，无 C 能力）' : implode('  ', $detail));
        }
    }

    /** run 命令仅对本机目标可用。 */
    private function canRunHere(): bool
    {
        $hostOs = strtolower(PHP_OS_FAMILY); // windows / linux / darwin
        if ($this->pref->os !== $hostOs) {
            return false;
        }
        return $this->pref->arch === Pref::normalizeArch(php_uname('m'));
    }

    private function report(Errors $errors): int
    {
        fwrite(STDERR, $errors->report());
        fwrite(STDERR, '共 ' . $errors->count() . " 个错误\n");
        return 1;
    }

    /** 警告不阻断编译，统一在检查完成后输出。 */
    private function printWarnings(Errors $errors): void
    {
        $text = $errors->reportWarnings();
        if ($text === '') {
            return;
        }
        fwrite(STDERR, $text);
        fwrite(STDERR, '共 ' . $errors->warningCount() . " 个警告\n");
    }

    private function runExe(string $exe): int
    {
        $code = 0;
        passthru(escapeshellarg($exe), $code);
        return $code;
    }
}
