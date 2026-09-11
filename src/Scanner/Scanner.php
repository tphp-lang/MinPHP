<?php

declare(strict_types=1);

namespace Tphp\Scanner;

use Tphp\Errors\Errors;
use Tphp\Token\Pos;
use Tphp\Token\Token;
use Tphp\Token\TokenKind;

/**
 * 词法分析：字符流 → Token 流。
 *
 * 文件必须以 `<?php` 开头；`?>` 结束标记不支持（整个文件都是代码）。
 * 字符串 Token 保存引号之间的原始字节，转义与插值交给 Parser 处理。
 */
final class Scanner
{
    private string $src;
    private int $n;
    private int $i = 0;
    private int $line = 1;
    private int $col = 1;
    private int $tokStart = 0;
    private bool $lineHasOnlyWs = true; // 当前行至今只有空白（指令允许缩进）

    /** @var list<Token> */
    private array $tokens = [];

    public function __construct(
        private readonly string $file,
        string $src,
        private readonly Errors $errors,
    ) {
        // 去掉 UTF-8 BOM
        if (str_starts_with($src, "\xEF\xBB\xBF")) {
            $src = substr($src, 3);
        }
        $this->src = $src;
        $this->n = strlen($src);
    }

    /** @return list<Token> */
    public function scan(): array
    {
        $this->skipOpenTag();

        while (!$this->eof()) {
            $this->skipTrivia();
            if ($this->eof()) {
                break;
            }
            // PHP 结束标签 = 源码结束符：其后内容整体忽略（文件尾标签习惯）
            if ($this->peek() === '?' && $this->peek(1) === '>') {
                break;
            }
            $this->tokStart = $this->i;
            $this->scanToken();
        }

        $this->tokens[] = new Token(TokenKind::Eof, '', $this->here());
        return $this->tokens;
    }

    // ------------------------------------------------------------------ 基础

    private function eof(): bool
    {
        return $this->i >= $this->n;
    }

    private function peek(int $offset = 0): string
    {
        $j = $this->i + $offset;
        return $j < $this->n ? $this->src[$j] : '';
    }

    private function here(): Pos
    {
        return new Pos($this->file, $this->line, $this->col);
    }

    private function step(): string
    {
        $c = $this->src[$this->i++];
        if ($c === "\n") {
            $this->line++;
            $this->col = 1;
            $this->lineHasOnlyWs = true;
        } else {
            $this->col++;
            if ($c !== ' ' && $c !== "\t") {
                $this->lineHasOnlyWs = false;
            }
        }
        return $c;
    }

    private function error(string $msg): void
    {
        $this->errors->add($msg, $this->here());
    }

    /** `<?php` 可选（与旧版一致）：开头有则跳过，整文件即代码。 */
    private function skipOpenTag(): void
    {
        if (substr($this->src, $this->i, 5) === '<?php') {
            for ($k = 0; $k < 5; $k++) {
                $this->step();
            }
        }
    }

    /** 当前行首是否为 `#<word>`（word 后是行尾/空白/<）。 */
    private function matchDirectiveKeyword(string $word): bool
    {
        $len = strlen($word);
        if (substr($this->src, $this->i + 1, $len) !== $word) {
            return false;
        }
        $after = $this->src[$this->i + 1 + $len] ?? '';
        return $after === '' || $after === ' ' || $after === "\t"
            || $after === "\n" || $after === "\r"
            || $after === '<' || $after === '"';
    }

    /** `#include <...>` / `#include "..."`：捕获整体为单个 token。 */
    private function scanIncludeDirective(): void
    {
        $start = $this->here();
        $this->step(); // #
        $begin = $this->i;
        while (!$this->eof() && $this->peek() !== "\n") {
            $this->step();
        }
        $this->tokens[] = new Token(TokenKind::DirInclude, trim(substr($this->src, $begin, $this->i - $begin)), $start);
    }

    /** `#flag ...`：捕获整行参数为单个 token。 */
    private function scanFlagDirective(): void
    {
        $start = $this->here();
        $this->step(); // #
        $begin = $this->i;
        while (!$this->eof() && $this->peek() !== "\n") {
            $this->step();
        }
        $this->tokens[] = new Token(TokenKind::DirFlag, trim(substr($this->src, $begin + 4, $this->i - $begin - 4)), $start);
    }

    /** `#import <包名|.相对路径>` 与 `#php <模块名>`：捕获整行参数为单个 token。 */
    private function scanPackageDirective(TokenKind $kind, string $word): void
    {
        $start = $this->here();
        $this->step(); // #
        $begin = $this->i; // 指向关键字首字符
        while (!$this->eof() && $this->peek() !== "\n") {
            $this->step();
        }
        $len = strlen($word);
        $rest = substr($this->src, $begin + $len, $this->i - $begin - $len);
        $this->tokens[] = new Token($kind, trim($rest), $start);
    }

    /** `#if` / `#elif` / `#else` / `#endif`：条件原文进 lit（else/endif 为空）。 */
    private function scanCondDirective(string $kind): void
    {
        $start = $this->here();
        $this->step(); // #
        $begin = $this->i;
        while (!$this->eof() && $this->peek() !== "\n") {
            $this->step();
        }
        $rest = substr($this->src, $begin, $this->i - $begin);
        $cond = '';
        if ($kind === 'if' || $kind === 'elif') {
            $cond = trim(substr($rest, strlen($kind)));
            if ($cond === '') {
                $this->error("#{$kind} 缺少条件（os / arch / cc 名）");
            }
        }
        $tokenKind = match ($kind) {
            'if' => TokenKind::DirIf,
            'elif' => TokenKind::DirElif,
            'else' => TokenKind::DirElse,
            default => TokenKind::DirEndif,
        };
        $this->tokens[] = new Token($tokenKind, $cond, $start);
    }

    /** `#[export("c_name")]`：捕获整行为单个 token，lit 为 C 符号名。 */
    private function scanExportAnnotation(): void
    {
        $start = $this->here();
        $lineEnd = strpos($this->src, "\n", $this->i);
        $line = rtrim($lineEnd === false ? substr($this->src, $this->i) : substr($this->src, $this->i, $lineEnd - $this->i), "\r");
        $ok = preg_match(
            '/^#\[\s*export\s*\(\s*(?:\'([A-Za-z_][A-Za-z0-9_]*)\'|"([A-Za-z_][A-Za-z0-9_]*)")\s*\)\s*\]$/',
            $line,
            $m,
        ) === 1;
        $name = $ok ? (($m[1] ?? '') !== '' ? $m[1] : $m[2]) : '';
        if (!$ok) {
            $this->error('#[...] 注解仅支持 #[export("C符号名")]，且必须紧跟全局 function 声明');
        }
        while (!$this->eof() && $this->peek() !== "\n") {
            $this->step();
        }
        $this->tokens[] = new Token(TokenKind::DirExport, $name, $start);
    }

    /**
     * Heredoc / Nowdoc（<<<Label / <<<'Label'）。
     * 内容自下一行起，到以 Label 开头（允许缩进）的行结束；
     * heredoc → DStrLit（支持插值），nowdoc → NowdocLit（原文，无转义处理）。
     */
    private function scanHeredoc(Pos $start): void
    {
        $label = '';
        while (!$this->eof() && (ctype_alpha($this->peek()) || $this->peek() === '_')) {
            $label .= $this->step();
        }
        $nowdoc = false;
        if ($this->peek() === "'") {
            $this->step();
            $label = '';
            while (!$this->eof() && (ctype_alpha($this->peek()) || $this->peek() === '_')) {
                $label .= $this->step();
            }
            if ($this->peek() !== "'") {
                $this->error("nowdoc 终止引号缺失：<<<'{$label}");
                return;
            }
            $this->step();
            $nowdoc = true;
        } elseif ($this->peek() === '"') {
            $this->step(); // <<"Label" 形式 = heredoc
        }
        if ($label === '') {
            $this->error('heredoc 缺少标签：<<< 后应为标识符');
            return;
        }
        // 开标签行剩余内容必须为空白
        while (!$this->eof() && ($this->peek() === ' ' || $this->peek() === "\t" || $this->peek() === "\r")) {
            $this->step();
        }
        if (!$this->eof() && $this->peek() !== "\n") {
            $this->error('heredoc 开标签行尾只允许空白');
            return;
        }
        if (!$this->eof()) {
            $this->step(); // 行尾
        }
        // 收集行直到 label 行（允许缩进；label 后的 `;` 归还给语句层）
        $lines = [];
        $closed = false;
        $closeSemi = false;
        while (!$this->eof()) {
            $lineStart = $this->i;
            while (!$this->eof() && $this->peek() !== "\n") {
                $this->step();
            }
            $line = rtrim(substr($this->src, $lineStart, $this->i - $lineStart), "\r");
            if (!$this->eof()) {
                $this->step(); // 行尾
            }
            $bare = ltrim($line);
            if (rtrim($bare, "; \t") === $label) {
                $closed = true;
                $closeSemi = str_ends_with(rtrim($bare), ';');
                break;
            }
            $lines[] = $line;
        }
        if (!$closed) {
            $this->error("heredoc 未终止：缺少 {$label} 结束行");
            return;
        }
        // 先内容（pos = 开标签处），后结束行的 `;`（归还给语句层）
        $content = implode("\n", $lines);
        $this->tokens[] = new Token($nowdoc ? TokenKind::NowdocLit : TokenKind::DStrLit, $content, $start);
        if ($closeSemi) {
            $this->tokens[] = new Token(TokenKind::Semicolon, ';', $this->here());
        }
    }

    private function skipTrivia(): void
    {
        while (!$this->eof()) {
            $c = $this->peek();
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $this->step();
                continue;
            }
            if ($c === '/' && $this->peek(1) === '/') {
                while (!$this->eof() && $this->peek() !== "\n") {
                    $this->step();
                }
                continue;
            }
            if ($c === '#') {
                // 行首（仅空白前缀）的 #include / #flag / #struct / #if 是指令；其余 # 为行注释
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('include')) {
                    $this->scanIncludeDirective();
                    continue;
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('flag')) {
                    $this->scanFlagDirective();
                    continue;
                }
                // 包与能力来源：#import（自研 ext） / #php（PHP 原生，需 libphp）
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('import')) {
                    $this->scanPackageDirective(TokenKind::DirImport, 'import');
                    continue;
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('php')) {
                    $this->scanPackageDirective(TokenKind::DirPhp, 'php');
                    continue;
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('struct')) {
                    $this->step(); // #
                    $this->takeWhile(fn ($ch) => ctype_alpha($ch)); // 吃掉 "struct"
                    $this->tokens[] = new Token(TokenKind::DirStruct, '#struct', $this->here());
                    $this->takeWhile(fn ($ch) => $ch === ' ' || $ch === "\t");
                    continue; // 之后的标识符/花括号走正常 token 流
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('enum')) {
                    $this->step(); // #
                    $this->takeWhile(fn ($ch) => ctype_alpha($ch)); // 吃掉 "enum"
                    $this->tokens[] = new Token(TokenKind::DirEnum, '#enum', $this->here());
                    $this->takeWhile(fn ($ch) => $ch === ' ' || $ch === "\t");
                    continue; // 之后的标识符/花括号走正常 token 流
                }
                // 平台条件编译：#if / #elif / #else / #endif
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('if')) {
                    $this->scanCondDirective('if');
                    continue;
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('elif')) {
                    $this->scanCondDirective('elif');
                    continue;
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('else')) {
                    $this->scanCondDirective('else');
                    continue;
                }
                if ($this->lineHasOnlyWs && $this->matchDirectiveKeyword('endif')) {
                    $this->scanCondDirective('endif');
                    continue;
                }
                // #[export("c_name")] 注解（行首，仅全局函数）
                if ($this->lineHasOnlyWs && $this->peek(1) === '[') {
                    $this->scanExportAnnotation();
                    continue;
                }
                while (!$this->eof() && $this->peek() !== "\n") {
                    $this->step();
                }
                continue;
            }
            if ($c === '/' && $this->peek(1) === '*') {
                $start = $this->here();
                $this->step();
                $this->step();
                while (!$this->eof() && !($this->peek() === '*' && $this->peek(1) === '/')) {
                    $this->step();
                }
                if ($this->eof()) {
                    $this->errors->add('未闭合的块注释', $start);
                    return;
                }
                $this->step();
                $this->step();
                continue;
            }
            break;
        }
    }

    // ------------------------------------------------------------------ Token

    private function scanToken(): void
    {
        $start = $this->here();
        $c = $this->peek();

        if (ctype_digit($c)) {
            $this->scanNumber($start);
            return;
        }
        if ($c === '$') {
            $this->scanVar($start);
            return;
        }
        if ($c === '_' || ctype_alpha($c)) {
            $this->scanIdent($start);
            return;
        }
        if ($c === "'" || $c === '"') {
            $this->scanString($start, $c);
            return;
        }
        $this->scanOperator($start);
    }

    private function scanNumber(Pos $start): void
    {
        $isFloat = false;

        if ($this->peek() === '0' && ($this->peek(1) === 'x' || $this->peek(1) === 'X')) {
            $this->step();
            $this->step();
            if (!$this->takeWhile(fn ($c) => ctype_xdigit($c))) {
                $this->error('无效的十六进制字面量');
            }
        } elseif ($this->peek() === '0' && ($this->peek(1) === 'b' || $this->peek(1) === 'B')) {
            $this->step();
            $this->step();
            if (!$this->takeWhile(fn ($c) => $c === '0' || $c === '1')) {
                $this->error('无效的二进制字面量');
            }
        } elseif ($this->peek() === '0' && ($this->peek(1) === 'o' || $this->peek(1) === 'O')) {
            $this->step();
            $this->step();
            if (!$this->takeWhile(fn ($c) => $c >= '0' && $c <= '7')) {
                $this->error('无效的八进制字面量');
            }
        } else {
            $this->takeWhile(fn ($c) => ctype_digit($c));
            if ($this->peek() === '.' && ctype_digit($this->peek(1))) {
                $isFloat = true;
                $this->step();
                $this->takeWhile(fn ($c) => ctype_digit($c));
            }
            if ($this->peek() === 'e' || $this->peek() === 'E') {
                $nxt = $this->peek(1);
                if (ctype_digit($nxt) || (($nxt === '+' || $nxt === '-') && ctype_digit($this->peek(2)))) {
                    $isFloat = true;
                    $this->step();
                    if ($this->peek() === '+' || $this->peek() === '-') {
                        $this->step();
                    }
                    $this->takeWhile(fn ($c) => ctype_digit($c));
                }
            }
        }

        // 123abc 之类的残缺数字
        if (ctype_alpha($this->peek()) || $this->peek() === '_') {
            $this->error('数字字面量后不能直接跟字母');
        }

        $this->emit($isFloat ? TokenKind::FloatLit : TokenKind::IntLit, $start);
    }

    private function scanVar(Pos $start): void
    {
        $this->step(); // $
        if (!ctype_alpha($this->peek()) && $this->peek() !== '_') {
            $this->error('$ 后必须是标识符');
            return;
        }
        $this->takeWhile(fn ($c) => ctype_alnum($c) || $c === '_');
        // $this 是关键字
        if (substr($this->src, $this->tokStart + 1, $this->i - $this->tokStart - 1) === 'this') {
            $this->tokens[] = new Token(TokenKind::KwThis, '$this', $start);
            return;
        }
        $this->emit(TokenKind::Var, $start);
    }

    private function scanIdent(Pos $start): void
    {
        $this->takeWhile(fn ($c) => ctype_alnum($c) || $c === '_');
        $begin = $this->tokStart;
        $word = substr($this->src, $begin, $this->i - $begin);
        $kind = $this->keyword($word) ?? TokenKind::Ident;
        $this->emit($kind, $start);
    }

    private function scanString(Pos $start, string $quote): void
    {
        $this->step(); // 开引号
        $begin = $this->i;
        while (true) {
            if ($this->eof() || $this->peek() === "\n") {
                $this->error('未闭合的字符串字面量');
                break;
            }
            $c = $this->peek();
            if ($c === '\\') {
                $this->step();
                if (!$this->eof()) {
                    $this->step();
                }
                continue;
            }
            if ($c === $quote) {
                break;
            }
            $this->step();
        }
        $raw = substr($this->src, $begin, $this->i - $begin);
        if (!$this->eof() && $this->peek() === $quote) {
            $this->step(); // 闭引号
        }
        $this->tokens[] = new Token(
            $quote === "'" ? TokenKind::StrLit : TokenKind::DStrLit,
            $raw,
            $start,
        );
    }

    private function scanOperator(Pos $start): void
    {
        $c = $this->step();
        $c2 = $this->peek();
        $c3 = $this->peek(1);

        // 明确拒绝的 PHP 运算符
        if ($c === '=' && $c2 === '=' && $c3 === '=') {
            $this->error('不支持 ===（本语言类型固定，== 即恒等）');
            return;
        }
        if ($c === '!' && $c2 === '=' && $c3 === '=') {
            $this->error('不支持 !==（本语言类型固定，!= 即恒等）');
            return;
        }
        if ($c === '<' && $c2 === '=' && $c3 === '>') {
            $this->error('不支持 <=> 太空船运算符');
            return;
        }
        if ($c === '?' && ($c2 === '?' || $c2 === '-')) {
            $this->error('不支持 ?? 与 ?-> 运算符');
            return;
        }
        if ($c === '.' && $c2 === '.' && $c3 === '.') {
            $this->step(); // 第二个点
            $this->step(); // 第三个点
            $this->emit(TokenKind::Ellipsis, $start);
            return;
        }
        if ($c === '<' && $c2 === '<' && $c3 === '<') {
            $this->step(); // 第二个 <
            $this->step(); // 第三个 <
            $this->scanHeredoc($start);
            return;
        }
        if ($c === '@' || $c === '`') {
            $this->error("意外的字符 '{$c}'");
            return;
        }

        $kind = match ($c . $c2) {
            '**=' => TokenKind::PowEq,
            '<<=' => TokenKind::ShlEq,
            '>>=' => TokenKind::ShrEq,
            '**' => TokenKind::Pow,
            '==' => TokenKind::EqEq,
            '!=' => TokenKind::NotEq,
            '<=' => TokenKind::LtEq,
            '>=' => TokenKind::GtEq,
            '&&' => TokenKind::AndAnd,
            '||' => TokenKind::OrOr,
            '<<' => TokenKind::Shl,
            '>>' => TokenKind::Shr,
            '++' => TokenKind::Inc,
            '--' => TokenKind::Dec,
            '+=' => TokenKind::PlusEq,
            '-=' => TokenKind::MinusEq,
            '*=' => TokenKind::StarEq,
            '/=' => TokenKind::SlashEq,
            '%=' => TokenKind::PercentEq,
            '.=' => TokenKind::DotEq,
            '&=' => TokenKind::AmpEq,
            '|=' => TokenKind::PipeEq,
            '|>' => TokenKind::PipeRight,
            '^=' => TokenKind::CaretEq,
            '->' => TokenKind::Arrow,
            '=>' => TokenKind::FatArrow,
            '::' => TokenKind::DoubleColon,
            default => null,
        };
        if ($kind !== null) {
            $this->step(); // 吃掉第二个字符
            $this->emit($kind, $start);
            return;
        }

        $kind = match ($c) {
            '+' => TokenKind::Plus,
            '-' => TokenKind::Minus,
            '*' => TokenKind::Star,
            '/' => TokenKind::Slash,
            '%' => TokenKind::Percent,
            '.' => TokenKind::Dot,
            '=' => TokenKind::Eq,
            '<' => TokenKind::Lt,
            '>' => TokenKind::Gt,
            '!' => TokenKind::Not,
            '&' => TokenKind::Amp,
            '|' => TokenKind::Pipe,
            '^' => TokenKind::Caret,
            '~' => TokenKind::Tilde,
            '?' => TokenKind::Question,
            ':' => TokenKind::Colon,
            ',' => TokenKind::Comma,
            ';' => TokenKind::Semicolon,
            '\\' => TokenKind::Backslash,
            '(' => TokenKind::Lparen,
            ')' => TokenKind::Rparen,
            '[' => TokenKind::Lbracket,
            ']' => TokenKind::Rbracket,
            '{' => TokenKind::Lbrace,
            '}' => TokenKind::Rbrace,
            default => null,
        };
        if ($kind !== null) {
            $this->emit($kind, $start);
            return;
        }

        $this->error("意外的字符 '{$c}'");
    }

    // ------------------------------------------------------------------ 辅助

    /** @param callable(string):bool $pred */
    private function takeWhile(callable $pred): bool
    {
        $any = false;
        while (!$this->eof() && $pred($this->peek())) {
            $this->step();
            $any = true;
        }
        return $any;
    }

    private function keyword(string $word): ?TokenKind
    {
        return match ($word) {
            'if' => TokenKind::KwIf,
            'elseif' => TokenKind::KwElseIf,
            'else' => TokenKind::KwElse,
            'while' => TokenKind::KwWhile,
            'do' => TokenKind::KwDo,
            'for' => TokenKind::KwFor,
            'foreach' => TokenKind::KwForeach,
            'as' => TokenKind::KwAs,
            'switch' => TokenKind::KwSwitch,
            'case' => TokenKind::KwCase,
            'default' => TokenKind::KwDefault,
            'break' => TokenKind::KwBreak,
            'continue' => TokenKind::KwContinue,
            'return' => TokenKind::KwReturn,
            'throw' => TokenKind::KwThrow,
            'or' => TokenKind::KwOr,
            'function' => TokenKind::KwFunction,
            'fn' => TokenKind::KwFn,
            'class' => TokenKind::KwClass,
            'const' => TokenKind::KwConst,
            'extends' => TokenKind::KwExtends,
            'interface' => TokenKind::KwInterface,
            'enum' => TokenKind::KwEnum,
            'case' => TokenKind::KwCase,
            'implements' => TokenKind::KwImplements,
            'namespace' => TokenKind::KwNamespace,
            'use' => TokenKind::KwUse,
            'new' => TokenKind::KwNew,
            'this' => TokenKind::KwThis,
            'self' => TokenKind::KwSelf,
            'parent' => TokenKind::KwParent,
            'public' => TokenKind::KwPublic,
            'private' => TokenKind::KwPrivate,
            'protected' => TokenKind::KwProtected,
            'static' => TokenKind::KwStatic,
            'true' => TokenKind::KwTrue,
            'false' => TokenKind::KwFalse,
            'null' => TokenKind::KwNull,
            'echo' => TokenKind::KwEcho,
            'int' => TokenKind::KwInt,
            'float' => TokenKind::KwFloat,
            'double' => TokenKind::KwDouble,
            'bool' => TokenKind::KwBool,
            'string' => TokenKind::KwString,
            'array' => TokenKind::KwArray,
            'map' => TokenKind::KwMap,
            'final' => TokenKind::KwFinal,
            'abstract' => TokenKind::KwAbstract,
            'instanceof' => TokenKind::KwInstanceof,
            'trait' => TokenKind::KwTrait,
            'insteadof' => TokenKind::KwInsteadof,
            'callable' => TokenKind::KwCallable,
            'void' => TokenKind::KwVoid,
            default => null,
        };
    }

    private function emit(TokenKind $kind, Pos $start): void
    {
        $lit = substr($this->src, $this->tokStart, $this->i - $this->tokStart);
        $this->tokens[] = new Token($kind, $lit, $start);
    }
}
