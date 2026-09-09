<?php

declare(strict_types=1);

namespace Tphp\Gen;

use Tphp\Errors\Errors;
use Tphp\Table\ClassSymbol;
use Tphp\Table\FnSymbol;
use Tphp\Table\Table;
use Tphp\Table\TypeKind;
use Tphp\Type\Type;

/**
 * C 代码生成（gen/c 模块）。
 *
 * 只消费 Checker 标注好的 AST（节点上的 type 字段）与共享 Table，
 * 自身不做任何类型推断。输出按节分区（head/typedefs/globals/protos/
 * helpers/funcs/main），最终合并为单个 .c 文件。
 */
final class Gen
{
    use GenDeclTrait;
    use GenStmtTrait;
    use GenExprTrait;
    use GenRcTrait;
    use GenClosureTrait;

    private const SECTION_ORDER = ['head', 'consts', 'typedefs', 'globals', 'protos', 'helpers', 'funcs', 'closures', 'main'];

    /** @var array<string, string> */
    private array $sections = [];

    private string $cur = 'head';
    private int $indent = 0;
    private int $tmpN = 0;
    private int $closureSeq = 0;

    /** 当前正在生成的函数所属文件（#line 指令用） */
    private string $curFile = '';

    /** @var array<string, string> 已生成的 dump 组合函数（签名 → 函数名） */
    private array $dumpFns = [];

    public function __construct(
        private readonly Table $table,
        private readonly Errors $errors,
        private readonly bool $noMain = false,
        private readonly bool $memStats = false,
    ) {
        foreach (self::SECTION_ORDER as $section) {
            $this->sections[$section] = '';
        }
    }

    /** @param list<File> $files */
    public function generate(array $files): string
    {
        $decls = $this->collectDecls($files);

        $this->emitHead();
        $this->emitIncludes($files);
        $this->emitConsts();
        $this->emitTypes($decls);
        $this->emitProtos($decls);
        $this->emitBodies($decls);
        if (!$this->noMain) {
            $this->emitMain();
            if ($this->memStats) {
                // 在 main 体开头插入统计开关（兼容 main(void) 与 main(int, char**) 两种签名）
                $main = $this->sections['main'];
                $pos = strpos($main, "\n{");
                if ($pos !== false) {
                    $inject = "    tphp_mem_stats_on = true;\n    atexit(tphp_mem_report);\n";
                    $this->sections['main'] = substr($main, 0, $pos + 3) . $inject . substr($main, $pos + 3);
                }
            }
        }

        return implode('', array_map(
            fn (string $s) => $this->sections[$s],
            self::SECTION_ORDER,
        ));
    }

    // ------------------------------------------------------------------ 输出

    private function w(string $line): void
    {
        $this->sections[$this->cur] .= str_repeat('    ', max(0, $this->indent)) . $line . "\n";
    }

    private function begin(string $section): void
    {
        $this->cur = $section;
    }

    private function tmp(string $hint = 't'): string
    {
        return '__' . $hint . ($this->tmpN++);
    }

    /** 生成 #line 指令，把 C 报错映射回 PHP 源码。 */
    private function sourceLine(?object $pos): void
    {
        if ($pos === null || $this->curFile === '') {
            return;
        }
        $this->sections[$this->cur] .= '#line ' . $pos->line . ' "' . $this->curFile . "\"\n";
    }

    /** 全局函数的 C 符号名：#[export] 注解优先，否则默认 tphp_<name>。 */
    private function fnName(string $phpName): string
    {
        $fn = $this->table->fns[$phpName] ?? null;
        return ($fn !== null && $fn->exportName !== null) ? $fn->exportName : Names::fn($phpName);
    }

    // ------------------------------------------------------------------ 类型 → C

    public function cType(int $code): string
    {
        if ($code === Type::NONE) {
            return 'void';
        }
        $name = $this->table->cnames[$code] ?? 'void';
        // 引用类型（数组/类/map）在 C 中以指针表示
        $kind = $this->table->kindOf($code);
        if ($kind === TypeKind::ArrayOf || $kind === TypeKind::ClassType || $kind === TypeKind::MapType) {
            return $name . '*';
        }
        return $name;
    }

    /** 数组元素的 C 类型（不带指针）。 */
    public function elemCType(int $elemCode): string
    {
        return $this->cType($elemCode);
    }

    /**
     * 标量类型在 echo / 插值场景下的转换表达式。
     * 调用前保证类型为标量（Checker 已校验）。
     */
    private function toStrExpr(string $text, int $type): string
    {
        if ($type === Type::I_STRING) {
            return $text;
        }
        if ($type === Type::I_INT) {
            return 'tphp_str_of_int(' . $text . ')';
        }
        if ($type === Type::I_BOOL) {
            return 'tphp_str_of_bool(' . $text . ')';
        }
        if ($type === Type::I_FLOAT) {
            return 'tphp_str_of_float((double)(' . $text . '))';
        }
        if ($type === Type::I_DOUBLE) {
            return 'tphp_str_of_double(' . $text . ')';
        }
        // c.* 家族
        $name = $this->table->displayName($type);
        if (in_array($name, ['c.i64', 'c.longlong', 'c.long'], true)) {
            return 'tphp_str_of_long((long long)(' . $text . '))';
        }
        if (in_array($name, ['c.u64', 'c.ulonglong', 'c.ulong'], true)) {
            return 'tphp_str_of_ulong((unsigned long long)(' . $text . '))';
        }
        if (in_array($name, ['c.u8', 'c.u16', 'c.u32', 'c.ushort', 'c.uint', 'c.u128'], true)) {
            return 'tphp_str_of_ulong((unsigned long long)(uint64_t)(' . $text . '))';
        }
        if (in_array($name, ['c.i128'], true)) {
            return 'tphp_str_of_long((long long)(int64_t)(' . $text . '))';
        }
        if ($this->table->isFloatLike($type)) {
            return 'tphp_str_of_double((double)(' . $text . '))';
        }
        return 'tphp_str_of_int((int32_t)(' . $text . '))';
    }

    private function echoStmt(string $text, int $type): string
    {
        if ($type === Type::I_STRING) {
            return 'tphp_echo_str(' . $text . ')';
        }
        if ($type === Type::I_INT) {
            return 'tphp_echo_int(' . $text . ')';
        }
        if ($type === Type::I_BOOL) {
            return 'tphp_echo_bool(' . $text . ')';
        }
        if ($type === Type::I_FLOAT) {
            return 'tphp_echo_float(' . $text . ')';
        }
        if ($type === Type::I_DOUBLE) {
            return 'tphp_echo_double(' . $text . ')';
        }
        // c.* 家族：按位宽选 printf
        $name = $this->table->displayName($type);
        if (in_array($name, ['c.i64', 'c.longlong', 'c.long', 'c.i128'], true)) {
            return $this->printfExpr('%lld', '(long long)(' . $text . ')');
        }
        if (in_array($name, ['c.u64', 'c.ulonglong', 'c.ulong'], true)) {
            return $this->printfExpr('%llu', '(unsigned long long)(' . $text . ')');
        }
        if (in_array($name, ['c.u8', 'c.u16', 'c.u32', 'c.ushort', 'c.uint', 'c.u128'], true)) {
            return $this->printfExpr('%u', '(unsigned int)(' . $text . ')');
        }
        if ($this->table->isFloatLike($type)) {
            return $this->printfExpr('%.14g', '(double)(' . $text . ')');
        }
        return 'tphp_echo_int((int32_t)(' . $text . '))';
    }

    private function printfExpr(string $fmt, string $arg): string
    {
        return 'printf("' . $fmt . '", ' . $arg . ')';
    }

    /** 零值表达式（未初始化变量 / 兜底 return）。 */
    private function zeroValue(int $code): string
    {
        if ($this->table->isBool($code)) {
            return 'false';
        }
        if ($this->table->isString($code)) {
            return 'tphp_str_empty()';
        }
        if ($this->table->isArray($code) || $this->table->isClass($code) || $this->table->isMap($code) || $code === Type::I_NULL) {
            return 'NULL';
        }
        if ($this->table->isCallable($code)) {
            return '(Callable){0}';
        }
        if ($this->table->isInterface($code)) {
            return '{0}';
        }
        if ($this->table->isCStruct($code)) {
            return '{0}';
        }
        if ($this->table->isCPointer($code)) {
            return 'NULL';
        }
        if ($this->table->isFloatLike($code)) {
            return '0';
        }
        return '0';
    }

    /** C 字符串字面量转义（不可打印字节用八进制）。 */
    private function cLiteral(string $bytes): string
    {
        $out = '"';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $c = $bytes[$i];
            $o = ord($c);
            $out .= match (true) {
                $c === '\\' => '\\\\',
                $c === '"' => '\\"',
                $c === "\n" => '\\n',
                $c === "\r" => '\\r',
                $c === "\t" => '\\t',
                $o < 0x20 || $o > 0x7E => sprintf('\\%03o', $o),
                default => $c,
            };
        }
        return $out . '"';
    }

    private function strLitExpr(string $bytes): string
    {
        return 'tphp_str_lit(' . $this->cLiteral($bytes) . ', ' . strlen($bytes) . ')';
    }
}
