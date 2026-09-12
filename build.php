<?php

declare(strict_types=1);

/**
 * 构建 TinyPHP 编译器发布产物（纯 PHP，不依赖 unzip/cp 等外部工具）。
 *
 * 两种用法：
 *   1) 只打 phar：
 *        php -d phar.readonly=0 build.php [-o tphp.phar]
 *   2) 打 phar 并组装完整发布目录（推荐，一条命令出包）：
 *        php -d phar.readonly=0 build.php \
 *          --dist build/dist/tphp-win-x86_64 \
 *          --micro build/micro-win.zip \
 *          --tcc-zip build/tcc-win.zip
 *
 * 发布目录内容：tphp[.exe]（micro.sfx + phar 拼接）+ runtime/ + ext/ + tcc/ + README.md + LICENSE。
 * runtime/tcc/ext 以**真文件**随包分发（运行期零解压 —— TCC 读不了 phar://，
 * 也无法执行 phar 内的二进制，见 doc/release.md）。
 */

$base = __DIR__;

/** 显式报错并退出（项目风格：不静默处理）。 */
function fail(string $msg): never
{
    fwrite(STDERR, "TinyPHP: {$msg}\n");
    exit(1);
}

function usage(): void
{
    echo <<<TXT
    用法：php -d phar.readonly=0 build.php [选项]

      -o <path>           phar 输出路径（默认 tphp.phar；给 --dist 且未给 -o 时
                          写到 <dist>/tphp.phar，拼接完成后自动删除）
      --dist <dir>        组装完整发布目录（二进制 + runtime/ + ext/ + tcc/ + README + LICENSE）
      --micro <zip|file>  micro 包（zip 内含 micro.sfx，或已解出的 micro.sfx 文件）；
                          提供后与 phar 拼接为单文件 tphp[.exe]
      --bin <path>        单文件输出路径（默认 <dist>/tphp，Windows host 加 .exe）
      --tcc-zip <zip>     TCC 二进制包（顶层 tcc/ 目录），解压进发布目录

    TXT;
}

// ---- 参数解析 ------------------------------------------------------------
$out = null;     // phar 输出路径
$dist = null;    // 发布目录
$micro = null;   // micro 包（zip 或 micro.sfx 文件）
$bin = null;     // 单文件输出路径
$tccZip = null;  // TCC 包 zip

for ($i = 1; $i < $argc; $i++) {
    $val = $argv[$i + 1] ?? null;
    switch ($argv[$i]) {
        case '-o':
            $val === null && fail('-o 缺少路径');
            $out = $val;
            $i++;
            break;
        case '--dist':
            $val === null && fail('--dist 缺少目录');
            $dist = $val;
            $i++;
            break;
        case '--micro':
            $val === null && fail('--micro 缺少路径（zip 或 micro.sfx 文件）');
            $micro = $val;
            $i++;
            break;
        case '--bin':
            $val === null && fail('--bin 缺少路径');
            $bin = $val;
            $i++;
            break;
        case '--tcc-zip':
            $val === null && fail('--tcc-zip 缺少路径');
            $tccZip = $val;
            $i++;
            break;
        case '-h':
        case '--help':
            usage();
            exit(0);
        default:
            fail("未知参数 \"{$argv[$i]}\"（-h 查看用法）");
    }
}

if ($micro !== null && $bin === null && $dist === null) {
    fail('--micro 需要配合 --dist（或显式 --bin）指定单文件输出路径');
}

// ---- 0) 前置检查 ----------------------------------------------------------
if (ini_get('phar.readonly') === '1') {
    fail('构建 phar 需要 phar.readonly=0：php -d phar.readonly=0 build.php');
}

// ---- 1) 组装目录准备 ------------------------------------------------------
$dist = $dist !== null ? rtrim(str_replace('\\', '/', $dist), '/') : null;
$unlinkPhar = false;
if ($dist !== null) {
    if (!is_dir($dist) && !mkdir($dist, 0777, true)) {
        fail("无法创建发布目录 {$dist}");
    }
    if ($out === null) {
        $out = $dist . '/tphp.phar';
        $unlinkPhar = true; // 中间产物：拼接完成后删除，保持发布目录干净
    }
    if ($bin === null) {
        $bin = $dist . '/tphp' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }
}
$out = $out ?? 'tphp.phar';
$alias = basename($out);

// ---- 2) 打 phar（只含编译器源码） ----------------------------------------
$phar = new Phar($out, 0, $alias);
$phar->startBuffering();

// 入口（自带 PSR-4 兜底加载，phar 内可正常 require src/**）
$phar->addFile($base . '/main.php', 'main.php');

$count = 0;
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $local = 'src/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base . '/src') + 1));
    $phar->addFile($file->getPathname(), $local);
    $count++;
}
echo "[*] 已打包 src/（{$count} 个文件）\n";

foreach (['runtime', 'tcc', 'ext'] as $skip) {
    if (is_dir($base . '/' . $skip)) {
        echo "[*] 跳过 {$skip}/（以真文件随发布包分发，不进 phar）\n";
    }
}

$phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('{$alias}'); require 'phar://{$alias}/main.php'; __HALT_COMPILER();");
$phar->stopBuffering();
printf("[*] %s（%s bytes）\n", $out, number_format((float)filesize($out)));

// ---- 3) 组装发布目录 ------------------------------------------------------
/** 递归拷贝目录（不存在则创建）。 */
function copyDir(string $src, string $dst): void
{
    if (!is_dir($src)) {
        fail("源目录不存在：{$src}");
    }
    if (!is_dir($dst) && !mkdir($dst, 0777, true)) {
        fail("无法创建目录 {$dst}");
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $target = $dst . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        if ($f->isDir()) {
            is_dir($target) || mkdir($target, 0777, true);
        } else {
            copy($f->getPathname(), $target) || fail("拷贝失败：{$f->getPathname()} → {$target}");
        }
    }
}

/**
 * 解压 zip 到目标目录。
 * 优先 ZipArchive；回退 PharData（phar 扩展内置 zip 读支持，不依赖可选 zip 扩展——
 * CI 的精简 PHP 常缺 zip 扩展但有 phar）。两者皆无则明确报错（不静默）。
 */
function zipExtract(string $zip, string $dest): void
{
    if (class_exists('ZipArchive', false)) {
        $z = new ZipArchive();
        $z->open($zip) === true || fail("zip 无法打开（不是有效 zip）：{$zip}");
        $z->extractTo($dest) || fail("zip 解压失败：{$zip}");
        $z->close();
        return;
    }
    if (class_exists('PharData', false)) {
        try {
            (new PharData($zip))->extractTo($dest, null, true);
            return;
        } catch (\Throwable $e) {
            fail("zip 解压失败（PharData）：{$zip}（{$e->getMessage()}）");
        }
    }
    fail("无法解压 {$zip}：当前 PHP 既无 ZipArchive 也无 PharData（请启用 zip 或 phar 扩展）");
}

/** 读取 zip 内单个条目的原始内容（按 basename 匹配）。 */
function zipReadEntry(string $zip, string $name): string
{
    if (class_exists('ZipArchive', false)) {
        $z = new ZipArchive();
        $z->open($zip) === true || fail("zip 无法打开（不是有效 zip）：{$zip}");
        $idx = -1;
        for ($i = 0; $i < $z->numFiles; $i++) {
            if (basename($z->getNameIndex($i)) === $name) {
                $idx = $i;
                break;
            }
        }
        if ($idx < 0) {
            $entries = [];
            for ($i = 0; $i < $z->numFiles; $i++) {
                $entries[] = $z->getNameIndex($i);
            }
            $z->close();
            fail("zip 内无 {$name}（实际条目：" . implode(', ', $entries) . '）');
        }
        $data = $z->getFromIndex($idx);
        $z->close();
        $data !== false || fail("读取 {$name} 失败：{$zip}");
        return $data;
    }
    if (class_exists('PharData', false)) {
        try {
            $pd = new PharData($zip);
            if (!isset($pd[$name])) {
                $entries = [];
                foreach (new RecursiveIteratorIterator($pd) as $f) {
                    $entries[] = $f->getPathname();
                }
                fail("zip 内无 {$name}（实际条目：" . implode(', ', $entries) . '）');
            }
            return $pd[$name]->getContent();
        } catch (\Throwable $e) {
            fail("读取 {$name} 失败（PharData）：{$zip}（{$e->getMessage()}）");
        }
    }
    fail("无法读取 {$zip}：当前 PHP 既无 ZipArchive 也无 PharData（请启用 zip 或 phar 扩展）");
}

if ($dist === null) {
    exit(0);
}

// runtime/ + ext/（tcc -I 与 #import 必需的真文件）
copyDir($base . '/runtime', $dist . '/runtime');
if (is_dir($base . '/ext')) {
    copyDir($base . '/ext', $dist . '/ext');
} else {
    echo "[!] 仓库无 ext/ 目录，发布包将不含自带包（#import 将无包可用）\n";
}

// README / LICENSE（发布包必须携带）
foreach (['README.md', 'LICENSE'] as $doc) {
    is_file($base . '/' . $doc)
        ? (copy($base . '/' . $doc, $dist . '/' . $doc) || fail("拷贝失败：{$doc}"))
        : fail("缺少 {$doc}");
}

// TCC 包：解压进发布目录（顶层 tcc/），并校验结构
if ($tccZip !== null) {
    is_file($tccZip) || fail("TCC 包不存在：{$tccZip}");
    zipExtract($tccZip, $dist);
    $native = PHP_OS_FAMILY === 'Windows' ? 'tcc.exe' : 'tcc';
    is_dir($dist . '/tcc') || fail('TCC 包顶层没有 tcc/ 目录（结构见 TCC.md）');
    is_file($dist . '/tcc/' . $native) || fail("TCC 包缺少本机编译器 tcc/{$native}（结构见 TCC.md）");
    // ZipArchive::extractTo 不保留执行权限，unix host 需补
    if (PHP_OS_FAMILY !== 'Windows') {
        foreach (array_merge((array) glob($dist . '/tcc/*'), (array) glob($dist . '/tcc/bin/*')) as $f) {
            if (is_file($f)) {
                chmod($f, 0755);
            }
        }
    }
    echo "[*] 已解出 tcc/（随包 C 后端，含交叉编译器，见 TCC.md）\n";
}

// 单文件编译器：micro.sfx + tphp.phar
if ($micro !== null) {
    if (str_ends_with(strtolower($micro), '.zip')) {
        is_file($micro) || fail("micro 包不存在：{$micro}");
        $sfx = zipReadEntry($micro, 'micro.sfx');
    } else {
        is_file($micro) || fail("micro.sfx 不存在：{$micro}");
        $sfx = file_get_contents($micro);
        $sfx !== false || fail("读取 micro.sfx 失败：{$micro}");
    }
    $pharBytes = file_get_contents($out);
    $pharBytes !== false || fail("读取 phar 失败：{$out}");
    file_put_contents($bin, $sfx . $pharBytes) !== false || fail("写单文件失败：{$bin}");
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($bin, 0755);
    }
    printf("[*] %s（micro.sfx %s + phar %s bytes）\n", $bin, number_format((float) strlen($sfx)), number_format((float) strlen($pharBytes)));
}

// 中间 phar 用完即删（仅 --dist 自动生成的才删）
if ($unlinkPhar && $micro !== null) {
    @unlink($out);
}

echo "[*] 发布目录就绪：{$dist}\n";
