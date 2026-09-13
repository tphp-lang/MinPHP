<?php

declare(strict_types=1);

namespace Tphp\Parser;

use Tphp\Ast\Expr;
use Tphp\Ast\stmt\ReturnStmt;
use Tphp\Ast\expr\CCallExpr;
use Tphp\Ast\expr\CConstExpr;
use Tphp\Ast\expr\ArrayLit;
use Tphp\Ast\expr\AssignExpr;
use Tphp\Ast\expr\BinaryExpr;
use Tphp\Ast\expr\BoolLit;
use Tphp\Ast\expr\CallExpr;
use Tphp\Ast\expr\CastExpr;
use Tphp\Ast\expr\ClosureExpr;
use Tphp\Ast\expr\FloatLit;
use Tphp\Ast\expr\IndexExpr;
use Tphp\Ast\expr\InstanceOfExpr;
use Tphp\Ast\expr\IntLit;
use Tphp\Ast\expr\InterpStr;
use Tphp\Ast\expr\InvokeExpr;
use Tphp\Ast\expr\MethodCall;
use Tphp\Ast\expr\NameExpr;
use Tphp\Ast\expr\NewExpr;
use Tphp\Ast\expr\NullLit;
use Tphp\Ast\expr\OrExpr;
use Tphp\Ast\expr\PlaceholderExpr;
use Tphp\Ast\expr\PostfixExpr;
use Tphp\Ast\expr\PropFetch;
use Tphp\Ast\expr\StaticCall;
use Tphp\Ast\expr\StaticConst;
use Tphp\Ast\expr\StaticProp;
use Tphp\Ast\expr\StrLit;
use Tphp\Ast\expr\TernaryExpr;
use Tphp\Ast\expr\ThisExpr;
use Tphp\Ast\expr\UnaryExpr;
use Tphp\Ast\expr\VarExpr;
use Tphp\Token\Pos;
use Tphp\Token\Token;
use Tphp\Token\TokenKind;

/**
 * 表达式解析（优先级爬升）。
 *
 * 优先级从低到高：
 *   赋值 → 管道 |> → 三元 → || → && → | → ^ → & → == != → < > <= >=
 *   → << >> → . + - → * / % → 前缀一元 → ** → 后缀
 * （** 右结合且高于前缀一元，与 PHP 一致：-2**2 === -(2**2)）
 */
trait ParserExprTrait
{
    private const ASSIGN_OPS = [
        TokenKind::Eq, TokenKind::PlusEq, TokenKind::MinusEq, TokenKind::StarEq,
        TokenKind::SlashEq, TokenKind::PercentEq, TokenKind::PowEq, TokenKind::DotEq,
        TokenKind::AmpEq, TokenKind::PipeEq, TokenKind::CaretEq, TokenKind::ShlEq,
        TokenKind::ShrEq,
    ];

    /** 管道右值解析深度：>0 时调用参数中的 ... 是占位符。 */
    private int $pipeRhsDepth = 0;

    public function parseExpr(): Expr
    {
        return $this->parseAssign();
    }

    private function parseAssign(): Expr
    {
        $left = $this->parsePipe();
        $kind = $this->peekKind();
        if (in_array($kind, self::ASSIGN_OPS, true)) {
            $this->next();
            $this->checkAssignTarget($left);
            $value = $this->parseAssign(); // 右结合
            return $this->at(new AssignExpr($kind, $left, $value), $left->pos);
        }
        return $left;
    }

    private function checkAssignTarget(Expr $e): void
    {
        if (!$e instanceof VarExpr && !$e instanceof IndexExpr
            && !$e instanceof PropFetch && !$e instanceof StaticProp) {
            $this->errHere('赋值目标必须是变量、数组元素或属性');
        }
    }

    /**
     * 管道：x |> f(a) → f(x, a)，左结合可链式。
     * 纯解析期脱糖：把左操作数插入右侧调用首参，语义与直接调用完全一致
     * （Checker / Gen 无感知，类型检查、or 错误传播、引用计数自动继承）。
     */
    private function parsePipe(): Expr
    {
        $left = $this->parseTernary();
        while ($this->match(TokenKind::PipeRight)) {
            $this->pipeRhsDepth++;
            $rhs = $this->parseTernary();
            $this->pipeRhsDepth--;
            $left = $this->at($this->pipeInto($rhs, $left), $left->pos);
        }
        return $left;
    }

    /** 把管道左值插入右侧调用首参；右侧必须是调用形式（or 块先解包再回包）。 */
    private function pipeInto(Expr $rhs, Expr $piped): Expr
    {
        if ($rhs instanceof OrExpr) {
            return new OrExpr($this->pipeInto($rhs->call, $piped), $rhs->block);
        }
        if ($rhs instanceof CallExpr) {
            return new CallExpr($rhs->name, $this->pipeFill($rhs->args, $piped));
        }
        if ($rhs instanceof MethodCall && $this->isSimpleReceiver($rhs->obj)) {
            return new MethodCall($rhs->obj, $rhs->name, $this->pipeFill($rhs->args, $piped));
        }
        if ($rhs instanceof StaticCall) {
            return new StaticCall($rhs->class, $rhs->method, $this->pipeFill($rhs->args, $piped));
        }
        if ($rhs instanceof CCallExpr) {
            return new CCallExpr($rhs->name, $this->pipeFill($rhs->args, $piped));
        }
        if ($rhs instanceof InvokeExpr && $rhs->callee instanceof VarExpr) {
            return new InvokeExpr($rhs->callee, $this->pipeFill($rhs->args, $piped));
        }
        if ($rhs instanceof ClosureExpr) {
            return new InvokeExpr($rhs, [$piped]); // x |> (fn...) = 以 x 调用闭包
        }
        $this->errHere('「|>」右侧必须是函数调用、方法调用或静态调用');
        return $rhs;
    }

    /** 管道实参填充：... 占位符处插入左值（仅一次）；无占位符时默认插入首参。 */
    private function pipeFill(array $args, Expr $piped): array
    {
        $out = [];
        $filled = false;
        foreach ($args as $a) {
            if ($a instanceof PlaceholderExpr) {
                if ($filled) {
                    $this->errHere('「|>」占位符 ... 在一次管道中只能出现一次');
                }
                $out[] = $piped;
                $filled = true;
            } else {
                $out[] = $a;
            }
        }
        if (!$filled) {
            array_unshift($out, $piped);
        }
        return $out;
    }

    /** 管道方法接收者不得嵌套调用，避免管道值被求值两次。 */
    private function isSimpleReceiver(Expr $e): bool
    {
        return $e instanceof VarExpr || $e instanceof ThisExpr || $e instanceof StaticProp
            || ($e instanceof PropFetch && $this->isSimpleReceiver($e->obj));
    }

    private function parseTernary(): Expr
    {
        $cond = $this->parseBinary(1);
        if ($this->match(TokenKind::Question)) {
            $then = $this->parseTernary();
            $this->expect(TokenKind::Colon, "':'");
            $else = $this->parseTernary();
            return $this->at(new TernaryExpr($cond, $then, $else), $cond->pos);
        }
        return $cond;
    }

    private function binPrec(TokenKind $kind): int
    {
        return match ($kind) {
            TokenKind::OrOr => 1,
            TokenKind::AndAnd => 2,
            TokenKind::Pipe => 3,
            TokenKind::Caret => 4,
            TokenKind::Amp => 5,
            TokenKind::EqEq, TokenKind::NotEq => 6,
            TokenKind::Lt, TokenKind::Gt, TokenKind::LtEq, TokenKind::GtEq => 7,
            TokenKind::Shl, TokenKind::Shr => 8,
            TokenKind::Dot, TokenKind::Plus, TokenKind::Minus => 9,
            TokenKind::Star, TokenKind::Slash, TokenKind::Percent => 10,
            TokenKind::KwInstanceof => 11, // 高于乘除、低于前缀一元（PHP 优先级）
            default => 0,
        };
    }

    private function parseBinary(int $minPrec): Expr
    {
        $left = $this->parseUnary();
        while (true) {
            $prec = $this->binPrec($this->peekKind());
            if ($prec === 0 || $prec < $minPrec) {
                return $left;
            }
            $op = $this->next()->kind;
            if ($op === TokenKind::KwInstanceof) {
                // 右侧是类型名而非表达式（$x instanceof $var 动态类名不支持）
                $left = $this->parseInstanceofRhs($left);
                continue;
            }
            $right = $this->parseBinary($prec + 1);
            $left = $this->at(new BinaryExpr($op, $left, $right), $left->pos);
        }
    }

    /** instanceof 右侧：类名 / 接口名（含 \ 限定与前导 \）。 */
    private function parseInstanceofRhs(Expr $left): Expr
    {
        if ($this->is(TokenKind::Var)) {
            $t = $this->peek();
            $this->errHere('instanceof 右侧只支持类名或接口名（不支持动态类名——AOT 无运行期类型表查找）');
            $this->next();
            return $this->at(new InstanceOfExpr($left, '<error>'), $t->pos);
        }
        $nameTok = $this->expect(TokenKind::Ident, '类名或接口名');
        $name = $nameTok->lit;
        while ($this->is(TokenKind::Backslash)) {
            $this->next();
            $name .= '\\' . $this->expect(TokenKind::Ident, '名字')->lit;
        }
        return $this->at(new InstanceOfExpr($left, $this->resolveClassName($name)), $nameTok->pos);
    }

    private function parseUnary(): Expr
    {
        $kind = $this->peekKind();
        if (in_array($kind, [TokenKind::Minus, TokenKind::Plus, TokenKind::Not,
            TokenKind::Tilde, TokenKind::Inc, TokenKind::Dec], true)) {
            $t = $this->next();
            $operand = $this->parseUnary();
            return $this->at(new UnaryExpr($kind, $operand), $t->pos);
        }
        return $this->parsePower();
    }

    /** 幂运算：右结合，绑定紧于前缀一元。 */
    private function parsePower(): Expr
    {
        $base = $this->parsePostfix();
        if ($this->peekKind() === TokenKind::Pow) {
            $this->next();
            $right = $this->parseUnary(); // 允许 2 ** -3
            return $this->at(new BinaryExpr(TokenKind::Pow, $base, $right), $base->pos);
        }
        return $base;
    }

    private function parsePostfix(): Expr
    {
        $e = $this->parsePrimary();
        while (true) {
            $kind = $this->peekKind();
            if ($kind === TokenKind::Lbracket) {
                $this->next();
                $index = null;
                if (!$this->is(TokenKind::Rbracket)) {
                    $index = $this->parseExpr();
                }
                $this->expect(TokenKind::Rbracket, "']'");
                $e = $this->at(new IndexExpr($e, $index), $e->pos);
                continue;
            }
            if ($kind === TokenKind::Arrow) {
                $this->next();
                $name = $this->expect(TokenKind::Ident, '属性或方法名')->lit;
                if ($this->match(TokenKind::Lparen)) {
                    $args = $this->parseArgs();
                    $e = $this->at(new MethodCall($e, $name, $args), $e->pos);
                } else {
                    $e = $this->at(new PropFetch($e, $name), $e->pos);
                }
                continue;
            }
            if ($kind === TokenKind::Inc || $kind === TokenKind::Dec) {
                $this->next();
                $e = $this->at(new PostfixExpr($kind, $e), $e->pos);
                continue;
            }
            // 调用/构造可带错误处理块：f() / $f() / $o->m() / X::m() / new X() or { ... }
            if ($kind === TokenKind::KwOr && $this->peekKindAt(1) === TokenKind::Lbrace
                && ($e instanceof CallExpr || $e instanceof InvokeExpr || $e instanceof MethodCall
                    || $e instanceof StaticCall || $e instanceof NewExpr)) {
                $this->next(); // or
                $block = $this->parseBracedBlock();
                $e = $this->at(new OrExpr($e, $block), $e->pos);
                continue;
            }
            if ($kind === TokenKind::Lparen && $e instanceof NameExpr) {
                $this->next();
                $args = $this->parseArgs();
                $e = $this->at(new CallExpr($e->name, $args), $e->pos);
                continue;
            }
            // $f(args)：callable 变量调用
            if ($kind === TokenKind::Lparen && $e instanceof VarExpr) {
                $this->next();
                $args = $this->parseArgs();
                $e = $this->at(new InvokeExpr($e, $args), $e->pos);
                continue;
            }
            // f(...)：一等可调用 → fn(arg0) => f(arg0)（签名 Checker 从函数表推导）
            if ($kind === TokenKind::Ellipsis && $e instanceof NameExpr) {
                $this->next();
                $arg0 = new Param(new TypeRef('<adapter>'), 'arg0');
                $call = new CallExpr($e->name, [new VarExpr('arg0')]);
                $rs = new ReturnStmt($call);
                $c = new ClosureExpr([$arg0], null, [$rs], [], false);
                $c->adapterOf = $e->name;
                $e = $this->at($c, $e->pos);
                continue;
            }
            if ($kind === TokenKind::DoubleColon && $e instanceof NameExpr) {
                $this->next();
                $e = $this->parseStaticAccess($e->name, $e->pos);
                continue;
            }
            return $e;
        }
    }

    /** 闭包：function (params) [use ($a, &$b)] [: T] { body }。 */
    private function parseClosure(): ClosureExpr
    {
        $pos = $this->peek()->pos;
        $this->expect(TokenKind::KwFunction, "'function'");
        $params = $this->parseParamList();
        $captures = [];
        if ($this->match(TokenKind::KwUse)) {
            $this->expect(TokenKind::Lparen, "'('");
            if (!$this->is(TokenKind::Rparen)) {
                do {
                    $byRef = $this->match(TokenKind::Amp);
                    $name = substr($this->expect(TokenKind::Var, '捕获变量名')->lit, 1);
                    $captures[] = ['name' => $name, 'byRef' => $byRef];
                } while ($this->match(TokenKind::Comma));
            }
            $this->expect(TokenKind::Rparen, "')'");
        }
        $ret = $this->match(TokenKind::Colon) ? $this->parseTypeRef() : null;
        $body = $this->parseBracedBlock();
        return $this->at(new ClosureExpr($params, $ret, $body, $captures), $pos);
    }

    /** 箭头闭包：fn (params) [: T] => expr | { body }（自动按值捕获自由变量）。 */
    private function parseArrowFn(): ClosureExpr
    {
        $pos = $this->peek()->pos;
        $this->expect(TokenKind::KwFn, "'fn'");
        $params = $this->parseParamList();
        $ret = $this->match(TokenKind::Colon) ? $this->parseTypeRef() : null;
        $this->expect(TokenKind::FatArrow, "'=>'");
        if ($this->is(TokenKind::Lbrace)) {
            $body = $this->parseBracedBlock(); // 块体：须自带 return（Checker 校验）
            return $this->at(new ClosureExpr($params, $ret, $body, [], true), $pos);
        }
        $expr = $this->parseTernary();
        $rs = new ReturnStmt($expr);
        $rs->pos = $expr->pos;
        return $this->at(new ClosureExpr($params, $ret, [$rs], [], true), $pos);
    }

    private function parseStaticAccess(string $class, ?Pos $pos): Expr
    {
        if ($this->peekKind() === TokenKind::Var) {
            $name = substr($this->next()->lit, 1);
            return $this->at(new StaticProp($class, $name), $pos);
        }
        $name = $this->expect(TokenKind::Ident, '常量名 / 静态属性 $name / 方法名')->lit;
        if ($this->match(TokenKind::Lparen)) {
            $args = $this->parseArgs();
            return $this->at(new StaticCall($class, $name, $args), $pos);
        }
        // 类常量：ClassName::NAME / self::NAME / parent::NAME
        return $this->at(new StaticConst($class, $name), $pos);
    }

    /** @return list<object> */
    private function parseArgs(): array
    {
        $args = [];
        if (!$this->is(TokenKind::Rparen)) {
            while (true) {
                if ($this->is(TokenKind::Ellipsis)) {
                    $t = $this->next();
                    if ($this->pipeRhsDepth === 0) {
                        $this->errHere('「...」占位符只能在管道 |> 右侧的调用参数中使用');
                    }
                    if (!$this->is(TokenKind::Comma) && !$this->is(TokenKind::Rparen)) {
                        $this->errHere('「...」占位符后只能是 "," 或 ")"');
                    }
                    $args[] = $this->at(new PlaceholderExpr(), $t->pos);
                } else {
                    $args[] = $this->parseExpr();
                }
                if (!$this->match(TokenKind::Comma)) {
                    break;
                }
            }
        }
        $this->expect(TokenKind::Rparen, "')'");
        return $args;
    }

    private function parsePrimary(): Expr
    {
        $t = $this->peek();
        switch ($t->kind) {
            case TokenKind::IntLit:
                $this->next();
                [$text, $value] = $this->normalizeInt($t->lit);
                return $this->at(new IntLit($text, $value), $t->pos);
            case TokenKind::FloatLit:
                $this->next();
                return $this->at(new FloatLit($t->lit, (float) $t->lit), $t->pos);
            case TokenKind::StrLit:
                $this->next();
                return $this->at(new StrLit($this->resolveSingleQuoted($t->lit)), $t->pos);
            case TokenKind::NowdocLit:
                $this->next();
                return $this->at(new StrLit($t->lit), $t->pos); // nowdoc：原文，无转义处理
            case TokenKind::DStrLit:
                $this->next();
                return $this->parseInterpolated($t);
            case TokenKind::KwTrue:
                $this->next();
                return $this->at(new BoolLit(true), $t->pos);
            case TokenKind::KwFalse:
                $this->next();
                return $this->at(new BoolLit(false), $t->pos);
            case TokenKind::KwNull:
                $this->next();
                return $this->at(new NullLit(), $t->pos);
            case TokenKind::Var:
                $this->next();
                return $this->at(new VarExpr(substr($t->lit, 1)), $t->pos);
            case TokenKind::KwThis:
                $this->next();
                return $this->at(new ThisExpr(), $t->pos);
            case TokenKind::KwFunction:
                return $this->parseClosure();
            case TokenKind::KwFn:
                return $this->parseArrowFn();
            case TokenKind::Ident:
                $this->next();
                // c-> 前缀：直连 C 函数调用 / C 常量引用
                if ($t->lit === 'c' && $this->peekKind() === TokenKind::Arrow) {
                    $this->next(); // ->
                    $name = $this->expect(TokenKind::Ident, 'C 符号名')->lit;
                    if ($this->match(TokenKind::Lparen)) {
                        $args = $this->parseArgs();
                        return $this->at(new CCallExpr($name, $args), $t->pos);
                    }
                    return $this->at(new CConstExpr($name), $t->pos);
                }
                // 限定名：Lib\Calc / \Lib\Calc（类引用 / FQ 函数调用 / FQ 常量）
                if ($this->is(TokenKind::Backslash)) {
                    $name = $t->lit;
                    while ($this->is(TokenKind::Backslash)) {
                        $this->next();
                        $name .= '\\' . $this->expect(TokenKind::Ident, '名字')->lit;
                    }
                    return $this->at(new NameExpr($name), $t->pos);
                }
                // 裸名按后续 Token 判定用途：调用 / 类静态访问 / 常量引用
                $nxt = $this->peekKind();
                if ($nxt === TokenKind::Lparen) {
                    return $this->at(new NameExpr($this->resolveFunctionName($t->lit)), $t->pos);
                }
                if ($nxt === TokenKind::DoubleColon) {
                    return $this->at(new NameExpr($this->resolveClassName($t->lit)), $t->pos);
                }
                return $this->at(new NameExpr($this->resolveConstName($t->lit)), $t->pos);
            case TokenKind::KwSelf:
            case TokenKind::KwParent:
                $this->next();
                // 只为 self::/parent:: 服务；裸 self/parent 由 Checker 报错
                return $this->at(new NameExpr($t->kind === TokenKind::KwSelf ? 'self' : 'parent'), $t->pos);
            case TokenKind::KwNew:
                $this->next();
                if ($this->is(TokenKind::KwClass)) {
                    return $this->at($this->parseAnonClass(), $t->pos);
                }
                $class = $this->resolveClassName($this->parseQualifiedName());
                $this->expect(TokenKind::Lparen, "'('");
                $args = $this->parseArgs();
                return $this->at(new NewExpr($class, $args), $t->pos);
            case TokenKind::Lbracket:
                return $this->parseArrayLit();
            case TokenKind::Lparen:
                if ($this->peekIsCast()) {
                    $this->next(); // (
                    $target = $this->parseTypeRef();
                    $this->expect(TokenKind::Rparen, "')'");
                    $operand = $this->parseUnary();
                    return $this->at(new CastExpr($target, $operand), $t->pos);
                }
                $this->next();
                $e = $this->parseExpr();
                $this->expect(TokenKind::Rparen, "')'");
                return $e;
            default:
                $this->errHere('意外的 Token，期望表达式');
                $this->next();
                return $this->at(new NullLit(), $t->pos);
        }
    }

    /** '(' 之后是不是强转类型：内置类型关键字、c.* 别名（Ident . Ident）或 CStruct 名带星号。 */
    private function peekIsCast(): bool
    {
        $nxt = $this->peekKindAt(1);
        if (in_array($nxt, [TokenKind::KwInt, TokenKind::KwFloat, TokenKind::KwDouble,
            TokenKind::KwBool, TokenKind::KwString], true)) {
            return true;
        }
        if ($nxt === TokenKind::Ident && $this->peekKindAt(2) === TokenKind::Dot) {
            return true; // c.i8 / c.ptr 等
        }
        // CStruct 名 / CStruct* / c.ptr：Ident 后跟 ) 或 *
        if ($nxt === TokenKind::Ident) {
            $after = $this->peekKindAt(2);
            if ($after === TokenKind::Rparen || $after === TokenKind::Star) {
                return true;
            }
        }
        return false;
    }

    private function parseArrayLit(): Expr
    {
        $start = $this->expect(TokenKind::Lbracket, "'['")->pos;
        $items = [];
        $keys = []; // 与 items 平行；null = 无键（纯列表形式）
        if (!$this->is(TokenKind::Rbracket)) {
            while (true) {
                $first = $this->parseExpr();
                if ($this->match(TokenKind::FatArrow)) {
                    // k => v：键值对（map 字面量）
                    $keys[] = $first;
                    $items[] = $this->parseExpr();
                } else {
                    $keys[] = null;
                    $items[] = $first;
                }
                if (!$this->match(TokenKind::Comma)) {
                    break;
                }
            }
        }
        $this->expect(TokenKind::Rbracket, "']'");
        return $this->at(new ArrayLit($items, $keys), $start);
    }

    // ------------------------------------------------------- 字面量与字符串

    /**
     * 归一化整数字面量：hex 保持原样（C 兼容），二进制/八进制转十进制。
     *
     * @return array{string, int}
     */
    private function normalizeInt(string $lit): array
    {
        $low = strtolower($lit);
        if (str_starts_with($low, '0x')) {
            return [strtolower($lit), (int) hexdec(substr($lit, 2))];
        }
        if (str_starts_with($low, '0b')) {
            $v = (int) bindec(substr($lit, 2));
            return [(string) $v, $v];
        }
        if (str_starts_with($low, '0o')) {
            $v = (int) octdec(substr($lit, 2));
            return [(string) $v, $v];
        }
        return [$lit, (int) $lit];
    }

    private function resolveSingleQuoted(string $s): string
    {
        $out = '';
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $n && ($s[$i + 1] === "'" || $s[$i + 1] === '\\')) {
                $out .= $s[$i + 1];
                $i += 2;
                continue;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }

    /** 解析 $s[$i] 处的反斜杠转义（双引号串），原地推进 $i。 */
    private function resolveEscape(string $s, int &$i): string
    {
        $c = $s[$i + 1] ?? '';
        switch ($c) {
            case 'n': $i += 2; return "\n";
            case 'r': $i += 2; return "\r";
            case 't': $i += 2; return "\t";
            case 'v': $i += 2; return "\v";
            case 'f': $i += 2; return "\f";
            case '0': $i += 2; return "\0";
            case '\\': $i += 2; return '\\';
            case '"': $i += 2; return '"';
            case '$': $i += 2; return '$';
            default: $i += 1; return '\\'; // 未知转义保留反斜杠（PHP 行为）
        }
    }

    private function parseInterpolated(Token $t): Expr
    {
        $raw = $t->lit;
        $parts = [];
        $buf = '';
        $i = 0;
        $n = strlen($raw);
        $flush = static function () use (&$parts, &$buf): void {
            if ($buf !== '') {
                $parts[] = $buf;
                $buf = '';
            }
        };

        while ($i < $n) {
            $c = $raw[$i];
            if ($c === '\\') {
                $buf .= $this->resolveEscape($raw, $i);
                continue;
            }
            if ($c === '$' && $i + 1 < $n && $this->isIdentStart($raw[$i + 1])) {
                $j = $i + 1;
                while ($j < $n && $this->isIdentChar($raw[$j])) {
                    $j++;
                }
                $name = substr($raw, $i + 1, $j - $i - 1);
                $flush();
                if ($name === 'this') {
                    $this->errors->add('$this 不能裸插值，请使用 {$this->prop}', $t->pos);
                } else {
                    $parts[] = $this->at(new VarExpr($name), $t->pos);
                }
                $i = $j;
                continue;
            }
            if ($c === '{' && ($raw[$i + 1] ?? '') === '$') {
                [$inner, $next] = $this->extractBraced($raw, $i);
                $flush();
                $parts[] = $this->parseSubExpr($inner, $t->pos);
                $i = $next;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        $flush();

        if ($parts === []) {
            return $this->at(new StrLit(''), $t->pos);
        }
        // 全部是字面片段（无插值）→ 折叠为普通字符串字面量
        $allLiteral = true;
        foreach ($parts as $part) {
            if (!is_string($part)) {
                $allLiteral = false;
                break;
            }
        }
        if ($allLiteral) {
            return $this->at(new StrLit(implode('', $parts)), $t->pos);
        }
        return $this->at(new InterpStr($parts), $t->pos);
    }

    /** 提取 {$...} 的花括号内容，返回 [内容, 结束偏移]。 */
    private function extractBraced(string $raw, int $start): array
    {
        $depth = 0;
        $i = $start;
        $n = strlen($raw);
        while ($i < $n) {
            $c = $raw[$i];
            if ($c === "'" || $c === '"') {
                $q = $c;
                $i++;
                while ($i < $n && $raw[$i] !== $q) {
                    if ($raw[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($raw, $start + 1, $i - $start - 1), $i + 1];
                }
            }
            $i++;
        }
        $this->errors->add('插值 {$...} 缺少闭合的 }', $this->peek()->pos);
        return ['', $n];
    }
}
