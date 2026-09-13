<?php

declare(strict_types=1);

namespace Tphp\Parser;

use Tphp\Ast\Expr;
use Tphp\Ast\File;
use Tphp\Ast\Node;
use Tphp\Errors\Errors;
use Tphp\Scanner\Scanner;
use Tphp\Token\Pos;
use Tphp\Token\Token;
use Tphp\Token\TokenKind;

/**
 * 递归下降语法分析：Token 流 → AST。
 *
 * 主类只持 Token 流与导航辅助；表达式 / 语句 / 声明三个语法域
 * 分别在 trait 中实现（按域拆分）。
 */
final class Parser
{
    use ParserExprTrait;
    use ParserStmtTrait;
    use ParserDeclTrait;

    private string $file = '';

    /** @var list<Token> */
    private array $toks = [];

    private int $i = 0;

    /** 当前文件的命名空间（'' = 全局）。 */
    private string $fileNs = '';

    /** @var array<string, string> use 导入表：短名 → FQ（类/接口） */
    private array $classImports = [];

    /** @var array<string, string> use function 导入表 */
    private array $functionImports = [];

    /** @var array<string, string> use const 导入表 */
    private array $constImports = [];

    /** @var list<string> #include 头文件 */
    private array $fileIncludes = [];

    /** @var list<string> #flag 编译参数 */
    private array $fileCflags = [];

    /** @var list<string> #import 参数（包名） */
    private array $fileImports = [];

    /** 匿名类升格产生的合成 ClassDecl（解析结束后并入 File->decls）。 @var list<object> */
    private array $anonClassDecls = [];

    /** 匿名类合成名序号（跨文件递增，保证全局唯一）。 */
    private int $anonClassSeq = 0;

    /** 编译目标（平台条件编译 #if 求值用）。 */
    private string $targetOs = 'windows';
    private string $targetArch = 'x86_64';
    private string $targetCc = 'tcc';

    public function __construct(private readonly Errors $errors) {}

    /**
     * @param array{os?: string, arch?: string, cc?: string} $target 编译目标（#if 条件求值用）
     */
    public function parseFile(string $path, string $src, array $target = []): File
    {
        $this->file = $path;
        $this->targetOs = $target['os'] ?? 'windows';
        $this->targetArch = $target['arch'] ?? 'x86_64';
        $this->targetCc = $target['cc'] ?? 'tcc';
        $this->toks = (new Scanner($path, $src, $this->errors))->scan();
        $this->preprocessConditionals();
        $this->i = 0;
        $this->fileNs = '';
        $this->classImports = [];
        $this->functionImports = [];
        $this->constImports = [];
        $this->fileIncludes = [];
        $this->fileCflags = [];
        $this->fileImports = [];

        // namespace 必须是文件第一条声明（语句式，每文件最多一个）
        $namespace = '';
        if ($this->is(TokenKind::KwNamespace)) {
            $this->next();
            $namespace = $this->parseQualifiedName();
            $this->expect(TokenKind::Semicolon, "';'");
            $this->fileNs = $namespace;
        }

        // 匿名类升格：解析期间收集的合成 ClassDecl 并入本文件声明列表
        $this->anonClassDecls = [];
        $decls = $this->parseTopLevel();
        foreach ($this->anonClassDecls as $anonDecl) {
            $decls[] = $anonDecl;
        }
        $file = new File($path, $decls, $namespace, $this->fileIncludes, $this->fileCflags, $this->fileImports);
        return $file;
    }

    // ---------------------------------------------------- 平台条件编译（#if 预过滤）

    /** 求值条件：os / arch / cc 名，支持 `!` 取反。 */
    private function evalCondition(string $cond): bool
    {
        $neg = str_starts_with($cond, '!');
        if ($neg) {
            $cond = trim(substr($cond, 1));
        }
        $val = $cond === $this->targetOs
            || $cond === $this->targetArch
            || $cond === $this->targetCc;
        return $neg ? !$val : $val;
    }

    /**
     * 解析前过滤平台条件分支：非命中分支的 token 直接丢弃（不解析、不检查、不生成）。
     * 与 Zig `$if` / 旧版 tphp 口径一致。指令 token 本身不进入解析。
     */
    private function preprocessConditionals(): void
    {
        $out = [];
        $stack = []; // list<array{taken: bool, active: bool, pos: Pos}>
        foreach ($this->toks as $tok) {
            $n = count($stack);
            $top = $n === 0 ? null : $stack[$n - 1];
            $topActive = $top === null ? true : $top['active'];

            if ($tok->kind === TokenKind::DirIf) {
                $cond = $topActive && $this->evalCondition($tok->lit);
                $stack[] = ['taken' => $cond, 'active' => $cond, 'pos' => $tok->pos];
                continue;
            }
            if ($tok->kind === TokenKind::DirElif) {
                if ($top === null) {
                    $this->errors->add('#elif 缺少匹配的 #if', $tok->pos);
                    continue;
                }
                // 外层激活状态取父帧（当前帧正在切换分支）
                $parentActive = $n < 2 ? true : $stack[$n - 2]['active'];
                $idx = $n - 1;
                $active = !$stack[$idx]['taken'] && $parentActive && $this->evalCondition($tok->lit);
                $stack[$idx]['active'] = $active;
                if ($active) {
                    $stack[$idx]['taken'] = true;
                }
                continue;
            }
            if ($tok->kind === TokenKind::DirElse) {
                if ($top === null) {
                    $this->errors->add('#else 缺少匹配的 #if', $tok->pos);
                    continue;
                }
                $parentActive = $n < 2 ? true : $stack[$n - 2]['active'];
                $idx = $n - 1;
                $stack[$idx]['active'] = !$stack[$idx]['taken'] && $parentActive;
                if ($stack[$idx]['active']) {
                    $stack[$idx]['taken'] = true;
                }
                continue;
            }
            if ($tok->kind === TokenKind::DirEndif) {
                if ($top === null) {
                    $this->errors->add('#endif 缺少匹配的 #if', $tok->pos);
                    continue;
                }
                array_pop($stack);
                continue;
            }

            if ($top === null || $topActive) {
                $out[] = $tok;
            }
        }
        if ($stack !== []) {
            $this->errors->add('#if 缺少匹配的 #endif', $stack[0]['pos']);
        }
        $this->toks = $out;
    }

    // ---------------------------------------------------- 名字解析（Parser 阶段 FQ 化）

    /** 类/接口名：use 表 → 当前 ns 前缀（不回退全局，PHP 语义）。 */
    public function resolveClassName(string $name): string
    {
        if (str_contains($name, '\\')) {
            return $name; // 已是 FQ
        }
        if (isset($this->classImports[$name])) {
            return $this->classImports[$name];
        }
        return $this->fileNs !== '' ? $this->fileNs . '\\' . $name : $name;
    }

    /** 函数名：内置豁免 → use function 表 → 当前 ns 前缀。 */
    public function resolveFunctionName(string $name): string
    {
        if ($name === 'len' || $name === 'var_dump' || $name === 'unset' || str_contains($name, '\\')) {
            return $name;
        }
        if (isset($this->functionImports[$name])) {
            return $this->functionImports[$name];
        }
        return $this->fileNs !== '' ? $this->fileNs . '\\' . $name : $name;
    }

    /** 常量名：use const 表 → 当前 ns 前缀。 */
    public function resolveConstName(string $name): string
    {
        if (str_contains($name, '\\')) {
            return $name;
        }
        if (isset($this->constImports[$name])) {
            return $this->constImports[$name];
        }
        return $this->fileNs !== '' ? $this->fileNs . '\\' . $name : $name;
    }

    /** 限定名：[\] IDENT (\ IDENT)*，规范化去掉前导反斜杠。
     *  $stopBeforeGroup=true 时遇到 "\ {" 停止（use 分组导入的前缀）。 */
    public function parseQualifiedName(bool $stopBeforeGroup = false): string
    {
        $leading = $this->match(TokenKind::Backslash);
        $parts = [$this->expect(TokenKind::Ident, '名字')->lit];
        while ($this->is(TokenKind::Backslash)
            && !($stopBeforeGroup && $this->peekKindAt(1) === TokenKind::Lbrace)) {
            $this->next();
            $parts[] = $this->expect(TokenKind::Ident, '名字')->lit;
        }
        $name = implode('\\', $parts);
        return $leading ? ltrim($name, '\\') : $name;
    }

    // ------------------------------------------------------------------ 导航

    public function peek(): Token
    {
        return $this->toks[$this->i];
    }

    public function peekKind(): TokenKind
    {
        return $this->toks[$this->i]->kind;
    }

    public function peekKindAt(int $offset): TokenKind
    {
        $j = min($this->i + $offset, count($this->toks) - 1);
        return $this->toks[$j]->kind;
    }

    public function next(): Token
    {
        $t = $this->toks[$this->i];
        if ($t->kind !== TokenKind::Eof) {
            $this->i++;
        }
        return $t;
    }

    public function is(TokenKind $kind): bool
    {
        return $this->peekKind() === $kind;
    }

    public function match(TokenKind $kind): bool
    {
        if ($this->is($kind)) {
            $this->next();
            return true;
        }
        return false;
    }

    public function expect(TokenKind $kind, string $what): Token
    {
        if ($this->is($kind)) {
            return $this->next();
        }
        $found = $this->peek()->lit !== '' ? "'{$this->peek()->lit}'" : $this->peekKind()->name;
        $this->errHere("期望 {$what}，得到 {$found}");
        return $this->peek();
    }

    public function errHere(string $msg): void
    {
        $this->errors->add($msg, $this->peek()->pos);
    }

    /** @template T of Node @param T $node @return T */
    public function at($node, Pos $pos)
    {
        $node->pos ??= $pos;
        return $node;
    }

    /** 在字符串插值 `{$expr}` 中解析子表达式。 */
    public function parseSubExpr(string $inner, Pos $pos): Expr
    {
        $tokens = (new Scanner($this->file, '<?php ' . $inner, $this->errors))->scan();
        [$savedToks, $savedI] = [$this->toks, $this->i];
        $this->toks = $tokens;
        $this->i = 0;
        $expr = $this->parseExpr();
        if (!$this->is(TokenKind::Eof)) {
            $this->errHere('插值 {} 中含有多余内容');
        }
        $this->toks = $savedToks;
        $this->i = $savedI;
        return $expr;
    }

    public function isIdentStart(string $c): bool
    {
        return $c === '_' || ctype_alpha($c);
    }

    public function isIdentChar(string $c): bool
    {
        return $c === '_' || ctype_alnum($c);
    }
}
