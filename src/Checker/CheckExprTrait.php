<?php

declare(strict_types=1);

namespace Tphp\Checker;

use Tphp\Ast\Expr;
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
use Tphp\Ast\expr\IntLit;
use Tphp\Ast\expr\InstanceOfExpr;
use Tphp\Ast\expr\InterpStr;
use Tphp\Ast\expr\InvokeExpr;
use Tphp\Ast\expr\MethodCall;
use Tphp\Ast\expr\NameExpr;
use Tphp\Ast\expr\NewExpr;
use Tphp\Ast\expr\NullLit;
use Tphp\Ast\expr\PostfixExpr;
use Tphp\Ast\expr\OrExpr;
use Tphp\Ast\expr\PropFetch;
use Tphp\Ast\expr\StaticCall;
use Tphp\Ast\expr\StaticConst;
use Tphp\Ast\expr\StaticProp;
use Tphp\Ast\expr\StrLit;
use Tphp\Ast\expr\TernaryExpr;
use Tphp\Ast\expr\ThisExpr;
use Tphp\Ast\expr\UnaryExpr;
use Tphp\Ast\expr\VarExpr;
use Tphp\Table\ClassSymbol;
use Tphp\Table\ConstSymbol;
use Tphp\Table\FnSymbol;
use Tphp\Table\InterfaceSymbol;
use Tphp\Table\ParamSymbol;
use Tphp\Table\Scope;
use Tphp\Table\TypeKind;
use Tphp\Table\VarSymbol;
use Tphp\Token\Pos;
use Tphp\Ast\stmt\BreakStmt;
use Tphp\Ast\stmt\ContinueStmt;
use Tphp\Ast\stmt\ExprStmt;
use Tphp\Ast\stmt\ReturnStmt;
use Tphp\Ast\stmt\ThrowStmt;
use Tphp\Type\Type;
use Tphp\Token\TokenKind;

/** 表达式检查：自底向上推导类型，回填到节点。 */
trait CheckExprTrait
{
    private function checkExpr(Expr $e): int
    {
        $t = $this->doCheckExpr($e);
        $e->type = $t;
        return $t;
    }

    private function doCheckExpr(Expr $e): int
    {
        if ($e instanceof IntLit) {
            return Type::I_INT;
        }
        if ($e instanceof FloatLit) {
            return Type::I_DOUBLE;
        }
        if ($e instanceof BoolLit) {
            return Type::I_BOOL;
        }
        if ($e instanceof NullLit) {
            return Type::I_NULL;
        }
        if ($e instanceof StrLit) {
            return Type::I_STRING;
        }
        if ($e instanceof InterpStr) {
            return $this->checkInterpStr($e);
        }
        if ($e instanceof VarExpr) {
            return $this->checkVar($e);
        }
        if ($e instanceof ThisExpr) {
            if ($this->curFn === null || !$this->curFn->isMethod || $this->curFn->isStatic) {
                $this->error('$this 只能在实例方法中使用', $e->pos);
                return Type::NONE;
            }
            return $this->curFn->ownerClass->code;
        }
        if ($e instanceof ArrayLit) {
            return $this->checkArrayLit($e);
        }
        if ($e instanceof IndexExpr) {
            return $this->checkIndex($e);
        }
        if ($e instanceof InstanceOfExpr) {
            return $this->checkInstanceOf($e);
        }
        if ($e instanceof BinaryExpr) {
            return $this->checkBinary($e);
        }
        if ($e instanceof UnaryExpr) {
            return $this->checkUnary($e);
        }
        if ($e instanceof PostfixExpr) {
            return $this->checkIncDec($e->expr, $e, $e->op);
        }
        if ($e instanceof TernaryExpr) {
            $this->requireBool($e->cond, '三元表达式条件');
            // then 分支在条件为真时求值：可据条件中的 instanceof 事实收窄
            $saved = $this->applyFacts($this->narrowFacts($e->cond));
            $tt = $this->checkExpr($e->then);
            $this->restoreFacts($saved);
            $et = $this->checkExpr($e->else);
            $common = $this->commonType($tt, $et);
            if ($common === Type::NONE) {
                $this->error(
                    '三元表达式两个分支类型不一致：'
                    . $this->table->displayName($tt) . ' 与 ' . $this->table->displayName($et),
                    $e->pos,
                );
            }
            return $common;
        }
        if ($e instanceof AssignExpr) {
            return $this->checkAssign($e);
        }
        if ($e instanceof CallExpr) {
            return $this->checkCall($e);
        }
        if ($e instanceof NewExpr) {
            return $this->checkNew($e);
        }
        if ($e instanceof PropFetch) {
            return $this->resolveProp($e);
        }
        if ($e instanceof MethodCall) {
            return $this->checkMethodCall($e);
        }
        if ($e instanceof StaticCall) {
            return $this->checkStaticCall($e);
        }
        if ($e instanceof StaticProp) {
            return $this->checkStaticProp($e);
        }
        if ($e instanceof CastExpr) {
            return $this->checkCast($e);
        }
        if ($e instanceof NameExpr) {
            return $this->checkName($e);
        }
        if ($e instanceof OrExpr) {
            return $this->checkOr($e);
        }
        if ($e instanceof CCallExpr) {
            return $this->checkCCall($e);
        }
        if ($e instanceof CConstExpr) {
            $e->type = Type::I_CVAL;
            return Type::I_CVAL;
        }
        if ($e instanceof StaticConst) {
            return $this->checkStaticConst($e);
        }
        if ($e instanceof ClosureExpr) {
            return $this->checkClosure($e);
        }
        if ($e instanceof InvokeExpr) {
            return $this->checkInvoke($e);
        }
        return Type::NONE;
    }

    // ------------------------------------------------------------------ 基础

    /** 裸名字：函数内常量 → 全局常量（Ns\name 未命中时回退全局 name，PHP 语义）。 */
    private function checkName(NameExpr $e): int
    {
        $local = $this->scope->findConst($e->name);
        if ($local !== null) {
            $e->isLocal = true;
            return $local->type;
        }
        $global = $this->table->consts[$e->name] ?? null;
        if ($global === null && str_contains($e->name, '\\')) {
            $short = substr($e->name, strrpos($e->name, '\\') + 1);
            if (isset($this->table->consts[$short])) {
                $e->name = $short; // 全局回退
                $global = $this->table->consts[$short];
            }
        }
        if ($global !== null) {
            $e->isLocal = false;
            return $global->type;
        }
        $this->error("未定义的名字 '{$e->name}'", $e->pos);
        return Type::NONE;
    }

    /**
     * f() or { ... }：错误时执行块。
     * 值上下文要求块以表达式语句结尾（或以 return/break/continue 终止）。
     */
    private function checkOr(OrExpr $e): int
    {
        $ret = $this->checkExpr($e->call);

        $saved = $this->scope;
        $this->scope = new Scope($saved, $saved->fn);
        $this->scope->consts['err'] = new ConstSymbol('err', Type::I_STRING, null, pos: $e->pos);
        $this->checkStmts($e->block);

        if (!$this->table->isVoid($ret)) {
            $last = $e->block === [] ? null : $e->block[count($e->block) - 1];
            if ($last instanceof ReturnStmt || $last instanceof BreakStmt || $last instanceof ContinueStmt) {
                // 块终止，不提供值
            } elseif ($last instanceof ExprStmt) {
                $vt = $last->expr->type;
                if (!$this->assignableExpr($ret, $last->expr)) {
                    $this->error(
                        'or 块结果类型不匹配：期望 ' . $this->table->displayName($ret)
                        . '，得到 ' . $this->table->displayName($vt) . $this->narrowHint($ret, $vt),
                        $e->pos,
                    );
                }
            } else {
                $this->error('or 块必须以表达式结尾以提供返回值', $e->pos);
            }
        }
        $this->scope = $saved;
        return $ret;
    }

    /** c-> 调用：参数必须是 C 侧值（string 需先 c_str()），返回 CVAL。 */
    private function checkCCall(CCallExpr $e): int
    {
        foreach ($e->args as $arg) {
            $t = $this->checkExpr($arg);
            if (!$this->isCArgType($t)) {
                $hint = $this->table->isString($t) ? '（string 需先经 c_str() 转换）' : '';
                $this->error(
                    'c-> 调用的参数必须是 C 侧值，得到 ' . $this->table->displayName($t) . $hint,
                    $arg->pos,
                );
            }
        }
        return Type::I_CVAL;
    }

    /** c-> 调用可接受的实参类型。 */
    private function isCArgType(int $t): bool
    {
        return $t === Type::I_INT || $t === Type::I_FLOAT || $t === Type::I_DOUBLE
            || $t === Type::I_BOOL || $t === Type::I_CVAL || $t === Type::I_NULL
            || $t === ($this->table->findNamed('c.ptr') ?? -1)
            || $this->table->isIntLike($t) || $this->table->isFloatLike($t)
            || $this->table->isCStruct($t) || $this->table->isCPointer($t);
    }

    private function checkStaticConst(StaticConst $e): int
    {
        $class = $this->resolveStaticClass($e->class);
        if ($class === null) {
            return Type::NONE;
        }
        // 枚举 case 引用：类型 = 枚举类（单例，== 即指针恒等）
        if ($class->isEnum) {
            $case = $class->findEnumCase($e->name);
            if ($case !== null) {
                $e->isEnumCase = true;
                return $class->code;
            }
        }
        $const = $class->findConst($e->name);
        if ($const === null) {
            $this->error("类 {$class->name} 没有常量 {$e->name}", $e->pos);
            return Type::NONE;
        }
        if (!$this->canAccess($const->owner, $const->vis)) {
            $this->error("无法访问 {$const->owner->name} 的 {$const->vis} 常量 {$e->name}", $e->pos);
            return Type::NONE;
        }
        return $const->type;
    }

    private function checkVar(VarExpr $e): int
    {
        $sym = $this->scope->find($e->name);
        if ($sym === null) {
            $this->error("未定义的变量 \${$e->name}", $e->pos);
            return Type::NONE;
        }
        $this->gateClosureVar($e->name, $e->pos);
        $e->sym = $sym;
        $e->ifaceUnwrap = $sym->ifaceCValue;
        $e->narrowCast = $sym->narrowShadow && $sym->cStorageType !== $sym->type;
        return $sym->type;
    }

    /**
     * 闭包体变量访问门（doc/closure.md §3.7）：
     * 箭头闭包自动按值捕获"直接外层函数/闭包"的变量；function 闭包必须显式 use。
     * 跨越闭包边界的传递捕获报错。
     */
    private function gateClosureVar(string $name, Pos $pos): void
    {
        if ($this->closureCtx === []) {
            return;
        }
        $ctx = $this->closureCtx[count($this->closureCtx) - 1];
        if (isset($ctx['scope']->vars[$name])) {
            return; // 已是本闭包的捕获 / 参数 / 局部
        }
        $owner = $this->findOwnerScope($name);
        if ($owner === null) {
            return; // 未定义：由调用方报错
        }
        if ($owner->fn !== $ctx['containerFn']) {
            $this->error('嵌套闭包只能捕获直接外层函数/闭包的变量', $pos);
            return;
        }
        if (!$ctx['auto']) {
            $this->error("闭包体内的变量 \${$name} 必须 use (...) 捕获", $pos);
            return;
        }
        $sym = $owner->vars[$name];
        $ctx['node']->resolvedCaptures[] = ['name' => $name, 'byRef' => false, 'type' => $sym->type, 'boxed' => $sym->boxed];
        $vs = new VarSymbol($name, $sym->type, $pos);
        $vs->isCapture = true;
        $ctx['scope']->vars[$name] = $vs;
    }

    private function findOwnerScope(string $name): ?Scope
    {
        for ($s = $this->scope; $s !== null; $s = $s->parent) {
            if (isset($s->vars[$name])) {
                return $s;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ 闭包与调用

    private function checkClosure(ClosureExpr $e): int
    {
        $paramTypes = [];
        // FnSymbol 按 AST 节点记忆化：两遍检查共用，调用点（pass 1）回填的
        // callable 形参签名经 paramRebuild 保留，pass 2 闭包体内可 invoke callable 形参
        $prevSigs = [];
        if (isset($this->closureFns[$e])) {
            foreach ($this->closureFns[$e]->params as $op) {
                if ($op->closureSig !== null) {
                    $prevSigs[$op->name] = $op->closureSig;
                }
            }
            $closureFn = $this->closureFns[$e];
            $closureFn->params = []; // 参数重建（类型解析幂等），签名由 prevSigs 回带
        } else {
            $closureFn = new FnSymbol('%closure', $e->pos);
            $this->closureFns[$e] = $closureFn;
        }
        if ($e->adapterOf !== null) {
            // f(...) 一等可调用适配器：签名 = 被引用函数（单参适配）
            $target = $this->table->fns[$e->adapterOf] ?? null;
            if ($target === null) {
                $this->error("未定义的函数 '{$e->adapterOf}()'", $e->pos);
                $e->sig = ['ret' => Type::NONE, 'params' => []];
                return Type::I_CALLABLE;
            }
            if (count($target->params) !== 1) {
                $this->error("'{$e->adapterOf}(...)' 一等可调用只支持单参数函数（得到 "
                    . count($target->params) . ' 个）', $e->pos);
            }
            $pt = $target->params[0]->type;
            $paramTypes = [$pt];
            $closureFn->params[] = new ParamSymbol($pt, 'arg0', false, null, $e->pos);
            $closureFn->ret = $target->ret;
        }
        foreach ($e->params as $p) {
            if ($e->adapterOf !== null) {
                break; // 适配器参数来自函数签名
            }
            $t = $this->resolveTypeRef($p->typeRef);
            if ($t === Type::NONE || $t === Type::I_VOID) {
                $this->error('闭包参数类型无效', $p->pos);
            }
            $paramTypes[] = $t;
            $closureFn->params[] = new ParamSymbol($t, $p->name, $p->hasDefault, $p->default, $p->pos);
        }
        // 上遍（pass 1）调用点回填的 callable 形参签名回带到重建后的形参
        foreach ($closureFn->params as $ps) {
            if ($ps->closureSig === null && isset($prevSigs[$ps->name])) {
                $ps->closureSig = $prevSigs[$ps->name];
            }
        }

        // 捕获列表：类型取自创建点作用域；byRef 标记外层变量为盒子存储
        $resolved = [];
        foreach ($e->captures as $c) {
            $sym = $this->scope->find($c['name']);
            if ($sym === null) {
                $this->error("捕获变量 \${$c['name']} 不存在", $e->pos);
                continue;
            }
            if ($c['byRef']) {
                if ($this->closureDepth > 0 || $sym->isCapture) {
                    $this->error('引用捕获的变量必须是直接外层函数的局部变量/参数', $e->pos);
                } else {
                    $sym->boxed = true;
                }
            }
            $resolved[] = ['name' => $c['name'], 'byRef' => $c['byRef'], 'type' => $sym->type, 'boxed' => $sym->boxed, 'closureSig' => $sym->closureSig];
        }
        foreach ($e->params as $p) {
            foreach ($resolved as $c) {
                if ($c['name'] === $p->name) {
                    $this->error("捕获变量 \${$p->name} 与闭包参数同名", $p->pos);
                }
            }
        }
        $e->resolvedCaptures = $resolved;

        $ret = $e->ret !== null ? $this->resolveTypeRef($e->ret) : Type::NONE;
        if ($ret === Type::NONE && !$e->isArrow) {
            $ret = Type::I_VOID; // 块体闭包省略返回类型 = void
        }
        $closureFn->ret = $ret;

        // 闭包体：独立作用域（穿透查外层由 gateClosureVar 把关）+ 独立函数上下文
        $savedScope = $this->scope;
        $savedFn = $this->curFn;
        $scope = new Scope($savedScope, $closureFn);
        foreach ($resolved as $c) {
            $vs = new VarSymbol($c['name'], $c['type'], $e->pos);
            $vs->isCapture = true;
            $vs->closureSig = $c['closureSig']; // 调用签名跨闭包边界：use 捕获的 callable 仍可 invoke
            $scope->vars[$c['name']] = $vs;
        }
        foreach ($closureFn->params as $ps) {
            $vs = new VarSymbol($ps->name, $ps->type, $ps->pos);
            $vs->closureSig = $ps->closureSig; // 调用点回填的签名：闭包自身 callable 形参可 invoke
            $scope->vars[$ps->name] = $vs;
        }
        $this->scope = $scope;
        $this->curFn = $closureFn;
        $this->closureCtx[] = ['auto' => $e->isArrow, 'scope' => $scope, 'node' => $e, 'containerFn' => $savedFn];
        $this->closureDepth++;
        $this->checkStmts($e->body);
        $this->closureDepth--;
        array_pop($this->closureCtx);
        $this->scope = $savedScope;
        $this->curFn = $savedFn;

        $ret = $closureFn->ret;
        if ($ret === Type::NONE) {
            $ret = Type::I_VOID; // 箭头闭包体无 return
        }
        $e->sig = ['ret' => $ret, 'params' => $paramTypes];
        return Type::I_CALLABLE; // 闭包表达式的静态类型；签名经节点 sig 传递
    }

    private function checkInvoke(InvokeExpr $e): int
    {
        // 闭包字面量直接调用（管道右侧 (fn...) 等）：签名在节点上
        if ($e->callee instanceof ClosureExpr) {
            $this->checkExpr($e->callee); // 先检查闭包（填充 sig / 捕获）
            $sig = $e->callee->sig;
            if ($sig === null) {
                return Type::NONE;
            }
            $e->sig = $sig;
            // 闭包字面量直接调用：经 thunk 间接执行，静态不可分析 → 强制深度保护
            if ($this->curFn !== null) {
                $this->curFn->forceDepthGuard = true;
            }
            $n = count($e->args);
            if ($n > count($sig['params'])) {
                $this->error('闭包调用参数过多', $e->pos);
            }
            foreach ($sig['params'] as $i => $pt) {
                if ($i < $n) {
                    $at = $this->checkExpr($e->args[$i]);
                    if (!$this->assignableExpr($pt, $e->args[$i])) {
                        $this->error('闭包调用第 ' . ($i + 1) . ' 个参数类型不匹配：期望 '
                            . $this->table->displayName($pt) . '，得到 '
                            . $this->table->displayName($at), $e->args[$i]->pos);
                    }
                } else {
                    $this->error('闭包调用缺少第 ' . ($i + 1) . ' 个参数', $e->pos);
                }
            }
            // callable 形参回填：实参已检查，字面量实参的 sig 就绪
            if (isset($this->closureFns[$e->callee])) {
                $this->backfillCallableParams($this->closureFns[$e->callee], $e->args, $n);
            }
            return $sig['ret'];
        }
        $name = $e->callee instanceof VarExpr ? $e->callee->name : '';
        $sym = $name !== '' ? $this->scope->find($name) : null;
        if ($sym === null || $sym->closureSig === null) {
            $this->error("变量 \${$name} 不可调用（需赋值闭包以推导签名）", $e->pos);
            foreach ($e->args as $arg) {
                $this->checkExpr($arg);
            }
            return Type::NONE;
        }
        $this->gateClosureVar($name, $e->pos);
        if ($sym->closureSig === null) {
            return Type::NONE; // gate 已报错
        }
        // 闭包调用经 thunk 间接执行，静态不可分析 → 调用方强制深度保护
        if ($this->curFn !== null) {
            $this->curFn->forceDepthGuard = true;
        }
        $sig = $sym->closureSig;
        $e->sig = $sig;
        $n = count($e->args);
        if ($n > count($sig['params'])) {
            $this->error('闭包调用参数过多', $e->pos);
        }
        foreach ($sig['params'] as $i => $pt) {
            if ($i < $n) {
                $at = $this->checkExpr($e->args[$i]);
                if (!$this->assignableExpr($pt, $e->args[$i])) {
                    $this->error('闭包调用第 ' . ($i + 1) . ' 个参数类型不匹配：期望 '
                        . $this->table->displayName($pt) . '，得到 '
                        . $this->table->displayName($at), $e->args[$i]->pos);
                }
            } else {
                $this->error('闭包调用缺少第 ' . ($i + 1) . ' 个参数', $e->pos);
            }
        }
        // callable 形参回填：实参已检查，签名就绪（两遍检查下闭包体内可 invoke callable 形参）
        if ($sym->closureFn !== null) {
            $this->backfillCallableParams($sym->closureFn, $e->args, $n);
        }
        return $sig['ret'];
    }

    private function checkInterpStr(InterpStr $e): int
    {
        foreach ($e->parts as $part) {
            if (is_string($part)) {
                continue;
            }
            $t = $this->checkExpr($part);
            if (!$this->table->isScalar($t)) {
                $this->error(
                    '插值表达式必须是标量（得到 ' . $this->table->displayName($t) . '）',
                    $part->pos,
                );
            }
        }
        return Type::I_STRING;
    }

    private function checkArrayLit(ArrayLit $e): int
    {
        if ($e->items === []) {
            return Type::I_ARRAY; // 空数组：借赋值目标的元素类型（或 map 类型，见 assignable）
        }
        // 全键形式 => map<K,V>（键类型统合后限 int/string）
        $hasKey = false;
        $hasNoKey = false;
        foreach ($e->keys as $k) {
            if ($k === null) {
                $hasNoKey = true;
            } else {
                $hasKey = true;
            }
        }
        if ($hasKey && $hasNoKey) {
            $this->error('map 字面量不能混用带键与不带键的元素', $e->pos);
            foreach ($e->items as $item) {
                $this->checkExpr($item);
            }
            return Type::NONE;
        }
        if ($hasKey) {
            $kt = null;
            $vt = null;
            foreach ($e->keys as $i => $kExpr) {
                $thisK = $this->checkExpr($kExpr);
                $thisV = $this->checkExpr($e->items[$i]);
                if (!$this->table->isString($thisK) && !$this->table->isIntLike($thisK)) {
                    $this->error('map 键类型只支持 int 与 string', $kExpr->pos);
                    return Type::NONE;
                }
                if ($kt === null) {
                    $kt = $thisK;
                    $vt = $thisV;
                    continue;
                }
                $kt = $this->commonType($kt, $thisK);
                $vt = $this->commonType($vt, $thisV);
                if ($kt === Type::NONE || $vt === Type::NONE) {
                    $this->error('map 键或值类型不一致', $e->items[$i]->pos);
                    return Type::NONE;
                }
            }
            if (!$this->table->isString($kt) && !$this->table->isIntLike($kt)) {
                $this->error('map 键类型只支持 int 与 string', $e->pos);
                return Type::NONE;
            }
            return $this->table->mapOf($kt, $vt);
        }
        $t = $this->checkExpr($e->items[0]);
        for ($i = 1; $i < count($e->items); $i++) {
            $et = $this->checkExpr($e->items[$i]);
            $common = $this->commonType($t, $et);
            if ($common === Type::NONE) {
                $this->error(
                    '数组元素类型不一致：' . $this->table->displayName($t)
                    . ' 与 ' . $this->table->displayName($et),
                    $e->items[$i]->pos,
                );
                return Type::NONE;
            }
            $t = $common;
        }
        return $this->table->arrayOf($t);
    }

    /** 数组字面量按目标元素类型做上下文检查（new Cat(...) → array<Animal>）。 */
    /** map 字面量按目标 map<K,V> 逐对校验（键与值）。 */
    private function checkMapLitAgainst(ArrayLit $e, int $mapType): void
    {
        $k = $this->table->mapKeyOf($mapType);
        $v = $this->table->mapValOf($mapType);
        foreach ($e->keys as $i => $kExpr) {
            if ($kExpr === null) {
                $this->error('map 字面量不能混用带键与不带键的元素', $e->items[$i]->pos);
                $this->checkExpr($e->items[$i]);
                continue;
            }
            $kt = $this->checkExpr($kExpr);
            if (!$this->assignableExpr($k, $kExpr)) {
                $this->error(
                    'map 键类型不匹配：期望 ' . $this->table->displayName($k)
                    . '，得到 ' . $this->table->displayName($kt),
                    $kExpr->pos,
                );
            }
            $vt = $this->checkExpr($e->items[$i]);
            if (!$this->assignableExpr($v, $e->items[$i])) {
                $this->error(
                    'map 值类型不匹配：期望 ' . $this->table->displayName($v)
                    . '，得到 ' . $this->table->displayName($vt),
                    $e->items[$i]->pos,
                );
            }
        }
    }

    private function checkArrayLitAgainst(ArrayLit $e, int $elem): void
    {
        foreach ($e->items as $item) {
            $t = $this->checkExpr($item);
            if (!$this->assignableExpr($elem, $item)) {
                $this->error(
                    '数组元素类型不匹配：期望 ' . $this->table->displayName($elem)
                    . '，得到 ' . $this->table->displayName($t) . $this->narrowHint($elem, $t),
                    $item->pos,
                );
            }
        }
    }

    /** instanceof：左侧须为对象；右侧为类/接口并尝试编译期折叠。 */
    private function checkInstanceOf(InstanceOfExpr $e): int
    {
        $objT = $this->checkExpr($e->obj);
        if (!$this->table->isClass($objT) && !$this->table->isInterface($objT) && !$this->table->isObject($objT)) {
            $this->error(
                'instanceof 左侧必须是对象（得到 ' . $this->table->displayName($objT) . '）',
                $e->obj->pos,
            );
            return Type::I_BOOL;
        }
        $cls = $this->table->classes[$e->class] ?? null;
        $iface = $this->table->ifaces[$e->class] ?? null;
        if ($cls === null && $iface === null && isset($this->table->traits[$e->class])) {
            $this->error("trait '{$e->class}' 不能用于 instanceof（展开后不存在的类型痕迹）", $e->pos);
            return Type::I_BOOL;
        }
        if ($cls === null && $iface === null) {
            if ($e->class !== '<error>') {
                $this->error("instanceof 右侧的类/接口 '{$e->class}' 未定义", $e->pos);
            }
            return Type::I_BOOL;
        }
        $e->folded = $this->foldInstanceOf($objT, $cls, $iface);
        return Type::I_BOOL;
    }

    /**
     * 编译期折叠：静态类型足以判定结果时返回常量，否则 null（运行时判定）。
     * 规则（单继承下后代集合不相交即可判定 false）：
     *  - 左侧类型是右侧类的后代 → true；两者无继承关系（类/类）→ false
     *  - 右侧类是左侧类的后代 → 运行时（实例可能是该类或其子类）
     *  - 接口参与（左侧为接口，或右侧为接口且左侧类未实现且非 final）→ 运行时
     */
    private function foldInstanceOf(int $objT, ?ClassSymbol $cls, ?InterfaceSymbol $iface): ?bool
    {
        $objIsClass = $this->table->isClass($objT);
        $objIsIface = $this->table->isInterface($objT);

        if ($this->table->isObject($objT)) {
            return null; // object 的底层具体类编译期未知 → 运行时判定
        }

        if ($cls !== null) {
            if ($objT === $cls->code) {
                return true;
            }
            if ($objIsClass) {
                $sc = $this->table->classByCode($objT);
                if ($sc === null) {
                    return null;
                }
                if ($sc->isSubclassOf($cls)) {
                    return true;
                }
                if ($cls->isSubclassOf($sc)) {
                    return null; // 右侧是左侧的后代：实例可能是它
                }
                return false; // 类/类无继承关系 → 后代集合不相交
            }
            return null; // 左侧是接口：实现类未知
        }

        // 右侧是接口
        if ($objIsClass) {
            $sc = $this->table->classByCode($objT);
            if ($sc !== null && $this->classImplements($sc, $iface)) {
                return true;
            }
            if ($sc !== null && $sc->isFinal) {
                return false; // final 类无子类：不会再有人实现该接口
            }
            return null; // 子类可能实现
        }
        if ($objIsIface) {
            $si = $this->table->interfaceByCode($objT);
            if ($si !== null && $si->isSubinterfaceOf($iface)) {
                return true; // 子接口的实现类必然实现该接口
            }
            return null;
        }
        return null;
    }

    // -------------------------------------------------------------- 流敏感收窄

    /**
     * 从 `$x instanceof C` 提取「收窄事实」。
     * 仅当 C 为具体类（非接口/未定义/trait）且与 $x 当前静态类型构成合法收窄（`refinedClass`）时成立。
     *
     * @return array{name:string,class:int,cStorageType:int,pos:Pos}|null
     */
    private function instanceofFact(Expr $e): ?array
    {
        if (!$e instanceof InstanceOfExpr || !$e->obj instanceof VarExpr) {
            return null;
        }
        $cls = $this->table->classes[$e->class] ?? null;
        if ($cls === null) {
            return null; // 右侧为接口 / 未定义 / trait：不收窄（硬边界）
        }
        $sym = $this->scope->find($e->obj->name);
        if ($sym === null) {
            return null;
        }
        $refined = $this->refinedClass($sym->type, $cls->code);
        if ($refined === null) {
            return null;
        }
        // 存储类型取原始声明类型：影子叠加时沿用外层的存储类型（C 变量未变）
        return [
            'name' => $e->obj->name,
            'class' => $refined,
            'cStorageType' => $sym->narrowShadow ? $sym->cStorageType : $sym->type,
            'pos' => $e->obj->pos,
        ];
    }

    /**
     * 从条件表达式提取「正向收窄事实」。
     * 递归分解 `&&`（左右并集）；`!` / `||` / 三元不产生正向事实。
     *
     * @return list<array{name:string,class:int,cStorageType:int,pos:Pos}>
     */
    private function narrowFacts(Expr $cond): array
    {
        if ($cond instanceof BinaryExpr && $cond->op === TokenKind::AndAnd) {
            return array_merge($this->narrowFacts($cond->left), $this->narrowFacts($cond->right));
        }
        $fact = $this->instanceofFact($cond);
        return $fact === null ? [] : [$fact];
    }

    /**
     * 从条件表达式提取「否定收窄事实」：形如 `!($x instanceof C)` 时，
     * 条件为假 ⇒ $x 必为具体类 C（供守卫子句在 if 之后收窄）。其它形态返回 null。
     *
     * @return array{name:string,class:int,cStorageType:int,pos:Pos}|null
     */
    private function negativeNarrowFact(Expr $cond): ?array
    {
        if ($cond instanceof UnaryExpr && $cond->op === TokenKind::Not) {
            return $this->instanceofFact($cond->expr);
        }
        return null;
    }

    /**
     * 在**当前作用域**写入收窄事实，返回被覆盖的旧符号表。
     * 有语句作用域处（if/while/for 体）依托子作用域自然失效，无需回退；
     * 无语句作用域处（`&&` 右侧、三元 then）需成对 `restoreFacts` 回退。
     */
    private function applyFacts(array $facts): array
    {
        $saved = [];
        foreach ($facts as $f) {
            if (array_key_exists($f['name'], $saved)) {
                continue; // 同段内同名事实只保存一次原始符号
            }
            $saved[$f['name']] = $this->writeNarrow($f['name'], $f['class'], $f['cStorageType'], $f['pos']);
        }
        return $saved;
    }

    /** 回退 `applyFacts` 写入的收窄影子（恢复原符号或删除）。 */
    private function restoreFacts(array $saved): void
    {
        foreach ($saved as $name => $old) {
            if ($old === null) {
                unset($this->scope->vars[$name]);
            } else {
                $this->scope->vars[$name] = $old;
            }
        }
    }

    /**
     * 语句序列按控制流是否「必然终止」（所有路径以 return / throw / break / continue 结束）。
     * 至少覆盖「最后一条语句即上述之一」的常见形态，供守卫子句收窄使用。
     */
    private function alwaysTerminates(array $stmts): bool
    {
        if ($stmts === []) {
            return false;
        }
        $last = $stmts[count($stmts) - 1];
        return $last instanceof ReturnStmt
            || $last instanceof ThrowStmt
            || $last instanceof BreakStmt
            || $last instanceof ContinueStmt;
    }

    private function checkIndex(IndexExpr $e): int
    {
        $base = $this->checkExpr($e->base);
        if ($this->table->isString($base)) {
            if ($e->index === null) {
                $this->error('字符串不可变，不能追加写', $e->pos);
                return Type::NONE;
            }
            $this->requireIntLike($e->index, '字符串下标');
            return Type::I_STRING;
        }
        if ($this->table->isArray($base)) {
            if ($e->index === null) {
                $this->error('数组追加写（$a[] = v）只能作为赋值目标', $e->pos);
                return Type::NONE;
            }
            $this->requireIntLike($e->index, '数组下标');
            return $this->table->arrayElemOf($base);
        }
        if ($this->table->isMap($base)) {
            if ($e->index === null) {
                $this->error('map 不支持追加写（$m[] = v），请使用下标写入', $e->pos);
                return Type::NONE;
            }
            $kt = $this->checkExpr($e->index);
            $k = $this->table->mapKeyOf($base);
            $ok = $this->table->isString($k)
                ? $this->table->isString($kt)
                : $this->table->isIntLike($kt);
            if (!$ok) {
                $this->error(
                    'map 键类型不匹配：期望 ' . $this->table->displayName($k)
                    . '，得到 ' . $this->table->displayName($kt),
                    $e->index->pos,
                );
            }
            return $this->table->mapValOf($base);
        }
        $this->error(
            $e->index === null
                ? '只有数组可以追加写'
                : $this->table->displayName($base) . ' 类型不支持下标访问',
            $e->pos,
        );
        return Type::NONE;
    }

    // ------------------------------------------------------------------ 运算

    private function checkBinary(BinaryExpr $e): int
    {
        $op = $e->op;

        if ($op === TokenKind::AndAnd) {
            // 短路：右侧仅在左侧为真时求值，可用左侧的 instanceof 事实收窄后再检查右侧
            $lt = $this->checkExpr($e->left);
            $saved = $this->applyFacts($this->narrowFacts($e->left));
            $rt = $this->checkExpr($e->right);
            $this->restoreFacts($saved);
            if ($this->table->isBool($lt) && $this->table->isBool($rt)) {
                return Type::I_BOOL;
            }
            $this->error('逻辑运算只支持 bool', $e->pos);
            return Type::NONE;
        }

        $lt = $this->checkExpr($e->left);
        $rt = $this->checkExpr($e->right);
        $table = $this->table;

        switch ($op) {
            case TokenKind::Pow:
                if ($lt === Type::I_INT && $rt === Type::I_INT) {
                    return Type::I_INT;
                }
                if ($table->isNumeric($lt) && $table->isNumeric($rt)) {
                    return Type::I_DOUBLE;
                }
                $this->error('幂运算只支持 int 与浮点类型', $e->pos);
                return Type::NONE;

            case TokenKind::Plus:
            case TokenKind::Minus:
            case TokenKind::Star:
            case TokenKind::Slash:
                if ($lt === Type::I_STRING || $rt === Type::I_STRING) {
                    $this->error('字符串拼接请使用 . 运算符', $e->pos);
                    return Type::NONE;
                }
                return $this->requireNumericPair($lt, $rt, $e->pos);

            case TokenKind::Percent:
                if ($table->isIntLike($lt) && $table->isIntLike($rt)) {
                    return $this->numericPromote($lt, $rt);
                }
                $this->error('取模运算只支持整数类型', $e->pos);
                return Type::NONE;

            case TokenKind::Dot:
                if ($table->isScalar($lt) && $table->isScalar($rt)) {
                    return Type::I_STRING;
                }
                $this->error('拼接只支持标量与字符串', $e->pos);
                return Type::NONE;

            case TokenKind::EqEq:
            case TokenKind::NotEq:
                if ($this->comparable($lt, $rt)) {
                    return Type::I_BOOL;
                }
                $this->error(
                    $this->table->displayName($lt) . ' 与 '
                    . $this->table->displayName($rt) . ' 不可比较',
                    $e->pos,
                );
                return Type::NONE;

            case TokenKind::Lt:
            case TokenKind::Gt:
            case TokenKind::LtEq:
            case TokenKind::GtEq:
                if ($this->orderable($lt, $rt)) {
                    return Type::I_BOOL;
                }
                $this->error('关系比较只支持数值与字符串', $e->pos);
                return Type::NONE;

            case TokenKind::OrOr:
                if ($table->isBool($lt) && $table->isBool($rt)) {
                    return Type::I_BOOL;
                }
                $this->error('逻辑运算只支持 bool', $e->pos);
                return Type::NONE;

            case TokenKind::Amp:
            case TokenKind::Pipe:
            case TokenKind::Caret:
            case TokenKind::Shl:
            case TokenKind::Shr:
                if ($lt === Type::I_CVAL || $rt === Type::I_CVAL) {
                    // CVAL（c-> 常量/调用）混入位运算：结果 CVAL（生成 C 原样，信任程序员）
                    return Type::I_CVAL;
                }
                if ($table->isIntLike($lt) && $table->isIntLike($rt)) {
                    return $this->numericPromote($lt, $rt);
                }
                $this->error('位运算只支持整数类型', $e->pos);
                return Type::NONE;

            default:
                $this->error('不支持的运算符', $e->pos);
                return Type::NONE;
        }
    }

    private function requireNumericPair(int $lt, int $rt, ?Pos $pos): int
    {
        // CVAL 混入算术：结果 CVAL（生成 C 原样）
        if ($lt === Type::I_CVAL || $rt === Type::I_CVAL) {
            return Type::I_CVAL;
        }
        if ($lt === Type::I_BOOL || $rt === Type::I_BOOL) {
            $this->error('bool 不参与算术运算', $pos);
            return Type::NONE;
        }
        if (!$this->table->isNumeric($lt) || !$this->table->isNumeric($rt)) {
            $this->error(
                $this->table->displayName($lt) . ' 与 '
                . $this->table->displayName($rt) . ' 不能做算术运算',
                $pos,
            );
            return Type::NONE;
        }
        $p = $this->numericPromote($lt, $rt);
        if ($p === Type::NONE) {
            $this->error('两个不同的 c.* 整型运算需要显式强转', $pos);
        }
        return $p;
    }

    private function checkUnary(UnaryExpr $e): int
    {
        switch ($e->op) {
            case TokenKind::Minus:
            case TokenKind::Plus:
                $t = $this->checkExpr($e->expr);
                if (!$this->table->isNumeric($t) || $t === Type::I_BOOL) {
                    $this->error('一元 +/- 只支持数值类型', $e->pos);
                    return Type::NONE;
                }
                return $t;
            case TokenKind::Not:
                $t = $this->checkExpr($e->expr);
                if (!$this->table->isBool($t)) {
                    $this->error('! 需要 bool 操作数', $e->pos);
                    return Type::NONE;
                }
                return Type::I_BOOL;
            case TokenKind::Tilde:
                $t = $this->checkExpr($e->expr);
                if (!$this->table->isIntLike($t)) {
                    $this->error('~ 只支持整数类型', $e->pos);
                    return Type::NONE;
                }
                return $t;
            default:
                return $this->checkIncDec($e->expr, $e, $e->op);
        }
    }

    private function checkIncDec(Expr $operand, Expr $node, TokenKind $op): int
    {
        $t = $this->checkExpr($operand);
        if (!$this->table->isNumeric($t) || $t === Type::I_BOOL) {
            $this->error('自增自减只支持数值变量', $node->pos);
            return Type::NONE;
        }
        if (!$operand instanceof VarExpr && !$operand instanceof PropFetch
            && !$operand instanceof StaticProp && !$operand instanceof IndexExpr) {
            $this->error('自增自减的操作数必须是变量', $node->pos);
            return Type::NONE;
        }
        return $t;
    }

    // ------------------------------------------------------------------ 赋值

    private function checkAssign(AssignExpr $e): int
    {
        $target = $e->target;

        // 赋值即失效：该变量此前若处于收窄态，赋值后不再成立
        if ($target instanceof VarExpr) {
            $this->clearNarrowShadow($target->name);
        }

        // 未定义变量的首次赋值 = 类型推断声明（PHP 类型；C 类型必须显式声明）
        if ($e->op === TokenKind::Eq && $target instanceof VarExpr
            && $this->scope->find($target->name) === null
            && $this->scope->findConst($target->name) === null) {
            return $this->inferDeclare($e);
        }

        $targetType = $this->resolveAssignTarget($target, $e->op !== TokenKind::Eq);

        if ($e->op === TokenKind::Eq) {
            if ($e->value instanceof ArrayLit && $this->table->isArray($targetType)) {
                // 数组字面量借目标元素类型做上下文检查
                if (!$this->checkArrayLitAgainst($e->value, $this->table->arrayElemOf($targetType))) {
                    $e->value->type = $targetType;
                }
            } elseif ($e->value instanceof ArrayLit && $this->table->isMap($targetType)) {
                // map 字面量按目标 K/V 逐对校验，回填 map 类型（Gen 据此生成 map_new）
                $this->checkMapLitAgainst($e->value, $targetType);
                $e->value->type = $targetType;
            } else {
                $vt = $this->checkExpr($e->value);
                $this->warnSelfCycle($target, $e->value);
                if ($targetType !== Type::NONE && !$this->assignableExpr($targetType, $e->value)) {
                    $this->error(
                        '赋值类型不匹配：期望 ' . $this->table->displayName($targetType)
                        . '，得到 ' . $this->table->displayName($vt) . $this->narrowHint($targetType, $vt),
                        $e->value->pos,
                    );
                }
                if ($e->value instanceof ClosureExpr && $target instanceof VarExpr) {
                    $tsym = $this->scope->find($target->name);
                    if ($tsym !== null) {
                        $tsym->closureSig = $e->value->sig;
                        $tsym->closureFn = $this->closureFns[$e->value] ?? null;
                    }
                } elseif ($e->value instanceof CallExpr && $e->value->retClosureSig !== null
                    && $target instanceof VarExpr) {
                    $tsym = $this->scope->find($target->name);
                    if ($tsym !== null) {
                        $tsym->closureSig = $e->value->retClosureSig;
                    }
                } elseif ($e->value instanceof VarExpr && $target instanceof VarExpr) {
                    $ssym = $this->scope->find($e->value->name);
                    $tsym = $this->scope->find($target->name);
                    if ($ssym?->closureSig !== null && $tsym !== null) {
                        $tsym->closureSig = $ssym->closureSig;
                    }
                    if ($ssym !== null && $tsym !== null) {
                        $tsym->closureFn = $ssym->closureFn;
                    }
                }
            }
            return $targetType;
        }

        $vt = $this->checkExpr($e->value);
        if ($targetType === Type::NONE) {
            return Type::NONE;
        }
        $common = match ($e->op) {
            TokenKind::PlusEq, TokenKind::MinusEq, TokenKind::StarEq, TokenKind::SlashEq, TokenKind::PercentEq =>
                $this->requireNumericPair($targetType, $vt, $e->pos),
            TokenKind::DotEq => $this->table->isString($targetType) && $this->table->isScalar($vt)
                ? Type::I_STRING
                : Type::NONE,
            TokenKind::AmpEq, TokenKind::PipeEq, TokenKind::CaretEq, TokenKind::ShlEq, TokenKind::ShrEq =>
                ($this->table->isIntLike($targetType) && $this->table->isIntLike($vt))
                    ? $this->numericPromote($targetType, $vt)
                    : Type::NONE,
            TokenKind::PowEq => ($targetType === Type::I_INT && $vt === Type::I_INT)
                ? Type::I_INT
                : (($this->table->isNumeric($targetType) && $this->table->isNumeric($vt)) ? Type::I_DOUBLE : Type::NONE),
            default => Type::NONE,
        };
        if ($common === Type::NONE || !$this->assignable($targetType, $common)) {
            $this->error(
                "复合赋值运算符与目标类型 {$this->table->displayName($targetType)} 不匹配"
                . $this->narrowHint($targetType, $common),
                $e->pos,
            );
            return $targetType;
        }
        return $targetType;
    }

    /**
     * 首次赋值推断声明：$x = expr; → 类型由值推断并定死。
     * C 侧类型（c.* / cstruct / CVAL）禁止推断——必须显式声明。
     */
    private function inferDeclare(AssignExpr $e): int
    {
        $target = $e->target;
        if ($e->value instanceof ArrayLit && $e->value->items === []) {
            $this->error(
                '空数组无法推断元素类型，请显式声明：array<T> $' . $target->name . ' = []',
                $e->pos,
            );
            return Type::NONE;
        }
        $vt = $this->checkExpr($e->value);
        if ($vt === Type::NONE || $vt === Type::I_ARRAY) {
            return Type::NONE; // 值检查已报错 / 裸数组
        }
        if ($this->isCValue($vt)) {
            $this->error(
                'C 类型必须显式声明（如 c.i32 $' . $target->name . ' = ...），不允许推断',
                $e->pos,
            );
            return Type::NONE;
        }
        $vs = new VarSymbol($target->name, $vt, $e->pos);
        if ($e->value instanceof ClosureExpr) {
            $vs->closureSig = $e->value->sig;
            $vs->closureFn = $this->closureFns[$e->value] ?? null;
        } elseif ($e->value instanceof CallExpr && $e->value->retClosureSig !== null) {
            $vs->closureSig = $e->value->retClosureSig;
        } elseif ($e->value instanceof VarExpr) {
            $ssym = $this->scope->find($e->value->name);
            if ($ssym !== null) {
                $vs->closureSig = $ssym->closureSig;
                $vs->closureFn = $ssym->closureFn;
            }
        }
        if (isset($this->boxedNames[$target->name])) {
            $vs->boxed = true;
            $e->boxedDecl = true;
        }
        $this->scope->vars[$target->name] = $vs;
        return $vt;
    }

    /** 是否 C 侧值（禁止推断）：c.* 别名 / c.ptr / cstruct / CVAL。 */
    private function isCValue(int $type): bool
    {
        return $this->table->kindOf($type) === TypeKind::Ctype
            || $this->table->kindOf($type) === TypeKind::CStruct
            || $this->table->kindOf($type) === TypeKind::CPointer
            || $type === Type::I_CVAL;
    }

    /** 解析赋值目标类型。$forWrite=true 时下标必须有索引。 */
    private function resolveAssignTarget(Expr $t, bool $forWrite): int
    {
        if ($t instanceof VarExpr) {
            $sym = $this->scope->find($t->name);
            if ($sym === null) {
                $this->error("未定义的变量 \${$t->name}", $t->pos);
                return Type::NONE;
            }
            $this->gateClosureVar($t->name, $t->pos);
            $t->sym = $sym;
            $t->ifaceUnwrap = $sym->ifaceCValue;
            return $sym->type;
        }

        if ($t instanceof IndexExpr) {
            $base = $this->checkExpr($t->base);
            if ($this->table->isString($base)) {
                $this->error('字符串不可变，不能下标赋值', $t->pos);
                return Type::NONE;
            }
            if ($this->table->isMap($base)) {
                // map 下标写：键不存在即插入，键类型与 K 一致
                if ($t->index === null) {
                    $this->error('map 不支持追加写（$m[] = v），请使用下标写入', $t->pos);
                    return Type::NONE;
                }
                $kt = $this->checkExpr($t->index);
                $k = $this->table->mapKeyOf($base);
                $ok = $this->table->isString($k)
                    ? $this->table->isString($kt)
                    : $this->table->isIntLike($kt);
                if (!$ok) {
                    $this->error(
                        'map 键类型不匹配：期望 ' . $this->table->displayName($k)
                        . '，得到 ' . $this->table->displayName($kt),
                        $t->index->pos,
                    );
                }
                return $this->table->mapValOf($base);
            }
            if (!$this->table->isArray($base)) {
                $this->error($this->table->displayName($base) . ' 类型不能下标赋值', $t->pos);
                return Type::NONE;
            }
            if ($t->index === null) {
                return $this->table->arrayElemOf($base); // $a[] = v
            }
            $this->requireIntLike($t->index, '数组下标');
            return $this->table->arrayElemOf($base);
        }

        if ($t instanceof PropFetch) {
            // 枚举 case 的 name/value 只读（赋值目标拦截）
            if ($t->obj instanceof VarExpr || $t->obj instanceof ThisExpr) {
                $ot = $this->checkExpr($t->obj);
                if ($this->table->isClass($ot)) {
                    $cls = $this->table->classByCode($ot);
                    if ($cls->isEnum && ($t->name === 'name' || $t->name === 'value')) {
                        $this->error("枚举 case 的 ->{$t->name} 是只读的", $t->pos);
                        return Type::NONE;
                    }
                }
            }
            return $this->resolveProp($t);
        }

        if ($t instanceof StaticProp) {
            return $this->resolveStaticProp($t);
        }

        $this->error('无效的赋值目标', $t->pos);
        return Type::NONE;
    }

    private function resolveProp(PropFetch $e): int
    {
        $ot = $this->checkExpr($e->obj);


        // cstruct 值字段访问：$v->field（生成 C 点访问）
        if ($this->table->isCStruct($ot)) {
            return $this->resolveCStructField($this->table->cstructs[$this->table->displayName($ot)] ?? null, $e, $ot);
        }
        // C 指针字段访问：$p->field（生成 C 箭头 + 空指针 panic）
        if ($this->table->isCPointer($ot)) {
            $baseName = rtrim($this->table->displayName($ot), '*');
            $baseCode = $this->table->findNamed($baseName)
                ?? ($this->table->cstructs[$baseName]->code ?? null);
            if ($baseCode === null || !$this->table->isCStruct($baseCode)) {
                $this->error("指针类型 {$this->table->displayName($ot)} 不支持字段访问（仅 cstruct 指针可以）", $e->pos);
                return Type::NONE;
            }
            return $this->resolveCStructField($this->table->cstructs[$baseName] ?? null, $e, $ot);
        }

        if (!$this->table->isClass($ot)) {
            if ($this->table->isObject($ot)) {
                $this->error('object 类型无属性布局，请先用 instanceof 收窄到具体类再访问 $' . $e->name, $e->pos);
            } else {
                $this->error(
                    $this->table->displayName($ot) . ' 类型没有属性 $' . $e->name,
                    $e->pos,
                );
            }
            return Type::NONE;
        }
        $class = $this->table->classByCode($ot);
        // 枚举的隐式只读属性：->name / ->value（backed）
        if ($class->isEnum && ($e->name === 'name' || $e->name === 'value')) {
            if ($e->name === 'value' && $class->enumBacking === null) {
                $this->error("纯枚举 {$class->name} 的 case 没有 ->value", $e->pos);
                return Type::NONE;
            }
            return $e->name === 'name' ? Type::I_STRING : $class->enumBacking;
        }
        $prop = $class->findProp($e->name);
        if ($prop === null) {
            $this->error("类 {$this->clsLabel($class)} 没有属性 \${$e->name}", $e->pos);
            return Type::NONE;
        }
        if ($prop->isStatic) {
            $this->error("静态属性请用 {$this->clsLabel($class)}::\${$e->name} 访问", $e->pos);
            return Type::NONE;
        }
        if (!$this->canAccess($prop->owner, $prop->vis)) {
            $this->error("无法访问 {$this->clsLabel($prop->owner)} 的 {$prop->vis} 属性 \${$e->name}", $e->pos);
            return Type::NONE;
        }
        return $prop->type;
    }

    /** cstruct 字段查找。 */
    private function resolveCStructField(?\Tphp\Table\CStructSymbol $struct, PropFetch $e, int $recvType): int
    {
        if ($struct === null) {
            $this->error("未知 cstruct", $e->pos);
            return Type::NONE;
        }
        foreach ($struct->resolvedFields as $field) {
            if ($field['name'] === $e->name) {
                return $field['type'];
            }
        }
        $this->error("cstruct {$struct->name} 没有字段 {$e->name}", $e->pos);
        return Type::NONE;
    }

    private function resolveStaticClass(string $name): ?ClassSymbol
    {
        if ($name === 'self') {
            if ($this->curClass === null) {
                $this->error('self:: 只能在类中使用', null);
                return null;
            }
            return $this->curClass;
        }
        if ($name === 'parent') {
            if ($this->curClass?->parent === null) {
                $this->error('当前类没有父类，不能用 parent::', null);
                return null;
            }
            return $this->curClass->parent;
        }
        $class = $this->table->classes[$name] ?? null;
        if ($class === null) {
            $this->error("未定义的类 '{$name}'", null);
        }
        return $class;
    }

    private function checkStaticProp(StaticProp $e): int
    {
        return $this->resolveStaticProp($e);
    }

    private function resolveStaticProp(StaticProp $e): int
    {
        $class = $this->resolveStaticClass($e->class);
        if ($class === null) {
            return Type::NONE;
        }
        $prop = $class->findProp($e->name);
        if ($prop === null) {
            $this->error("类 {$this->clsLabel($class)} 没有属性 \${$e->name}", $e->pos);
            return Type::NONE;
        }
        if (!$prop->isStatic) {
            $this->error("实例属性请用 ->\${$e->name} 访问", $e->pos);
            return Type::NONE;
        }
        if (!$this->canAccess($prop->owner, $prop->vis)) {
            $this->error("无法访问 {$this->clsLabel($prop->owner)} 的 {$prop->vis} 属性 \${$e->name}", $e->pos);
            return Type::NONE;
        }
        return $prop->type;
    }

    // ------------------------------------------------------------------ 调用

    private function checkCall(CallExpr $e): int
    {
        $fn = $this->table->fns[$e->name] ?? null;
        if ($fn === null && str_contains($e->name, '\\')) {
            // 全局回退（PHP 语义）：Ns\name 未命中时尝试全局 name
            $short = substr($e->name, strrpos($e->name, '\\') + 1);
            if (isset($this->table->fns[$short])) {
                $e->name = $short;
                $fn = $this->table->fns[$short];
            }
        }
        if ($fn === null) {
            $this->error("未定义的函数 '{$e->name}()'", $e->pos);
            return Type::NONE;
        }
        if ($fn->isBuiltin) {
            return $this->checkBuiltinCall($fn, $e);
        }
        if ($this->curFn !== null) {
            $this->table->addCallEdge($this->table->symbolId($this->curFn), $this->table->symbolId($fn));
        }
        $this->checkArgs($fn, $e->args, $e->pos);
        if ($fn->retClosureSig !== null && $this->table->isCallable($fn->ret)) {
            $e->retClosureSig = $fn->retClosureSig; // 签名挂节点供 $f(...) 推导；静态类型仍是 callable
        }
        return $fn->ret;
    }

    private function checkBuiltinCall(FnSymbol $fn, CallExpr $e): int
    {
        // phpc 桥接：string ↔ char* + C 内存所有权
        if ($fn->name === 'c_str' || $fn->name === 'php_str' || $fn->name === 'php_str_ref'
            || $fn->name === 'c_own' || $fn->name === 'cbuf' || $fn->name === 'c_fn') {
            if (count($e->args) !== 1) {
                $this->error("{$fn->name}() 只接受一个参数", $e->pos);
                return Type::NONE;
            }
            $t = $this->checkExpr($e->args[0]);
            if ($fn->name === 'c_fn') {
                // c_fn($closure)：闭包 → C 回调函数指针（CVAL；约定 C 回调尾参 void* userdata）
                $arg = $e->args[0];
                $sig = $arg instanceof ClosureExpr ? $arg->sig
                    : ($arg instanceof VarExpr ? ($this->scope->find($arg->name)?->closureSig) : null);
                if ($sig === null) {
                    $this->error('c_fn() 需要闭包字面量或已赋值闭包的变量（签名可推导）', $e->pos);
                } else {
                    $e->closureSig = $sig;
                }
                return Type::I_CVAL;
            }
            if ($fn->name === 'c_str') {
                if (!$this->table->isString($t)) {
                    $this->error('c_str() 需要 string 参数', $e->pos);
                }
                return $this->table->pointerOf('c.char', $this->table->findNamed('c.char'));
            }
            if ($fn->name === 'php_str' || $fn->name === 'php_str_ref') {
                // char* / c.ptr(void*) / null / CVAL → string
                if (!$this->table->isCPointer($t) && $t !== Type::I_CVAL
                    && $t !== Type::I_NULL && $t !== $this->table->findNamed('c.ptr')) {
                    $this->error("{$fn->name}() 需要 char* 指针参数（c-> 调用返回值或 c.char*）", $e->pos);
                }
                return Type::I_STRING;
            }
            // cbuf(n) / c_own(p)：返回 CVAL（已登记所有权，函数出口自动 free）
            if ($fn->name === 'cbuf') {
                if (!$this->table->isIntLike($t) && $t !== Type::I_CVAL) {
                    $this->error('cbuf() 需要整数长度参数', $e->pos);
                }
            } elseif (!$this->table->isCPointer($t) && $t !== Type::I_CVAL && $t !== Type::I_NULL) {
                $this->error('c_own() 需要指针参数（c-> 调用返回值）', $e->pos);
            }
            return Type::I_CVAL;
        }

        if ($fn->name === 'unset') {
            if (count($e->args) === 0) {
                $this->error('unset() 至少需要一个参数', $e->pos);
                return Type::I_VOID;
            }
            foreach ($e->args as $arg) {
                if (!$arg instanceof PropFetch) {
                    $this->checkExpr($arg); // 仍做类型检查，尽量多报错
                    $this->error('unset() 只支持对象属性（$obj->prop）', $arg->pos);
                    continue;
                }
                $ot = $this->checkExpr($arg->obj);
                if (!$this->table->isClass($ot)) {
                    $this->error('unset() 只支持对象属性（$obj->prop）', $arg->pos);
                    continue;
                }
                $ft = $this->resolveProp($arg);
                $arg->type = $ft;
                if ($ft === Type::NONE) {
                    continue;
                }
                if (!$this->table->isRefType($ft)) {
                    $this->error("字段 \${$arg->name} 是值类型（{$this->table->displayName($ft)}），无法 unset（仅 array/类/接口 字段可 unset 为 null）", $arg->pos);
                }
            }
            return Type::I_VOID;
        }

        if ($fn->name === 'implode') {
            // implode(string 分隔符, array<string> 部分): string —— 字符串累加的 O(n) 正解
            if (count($e->args) !== 2) {
                $this->error('implode() 接受两个参数：(string 分隔符, array<string> 部分)', $e->pos);
                foreach ($e->args as $arg) {
                    $this->checkExpr($arg);
                }
                return Type::I_STRING;
            }
            $sepT = $this->checkExpr($e->args[0]);
            $arrT = $this->checkExpr($e->args[1]);
            if (!$this->table->isString($sepT)) {
                $this->error('implode() 第 1 个参数必须是 string 分隔符', $e->args[0]->pos);
            }
            if (!$this->table->isArray($arrT)) {
                $this->error('implode() 第 2 个参数必须是 array', $e->args[1]->pos);
            } elseif (!$this->table->isString($this->table->arrayElemOf($arrT))) {
                $this->error('implode() 要求 array<string>', $e->args[1]->pos);
            }
            return Type::I_STRING;
        }

        if (count($e->args) !== 1) {
            $this->error("{$fn->name}() 只接受一个参数", $e->pos);
            foreach ($e->args as $arg) {
                $this->checkExpr($arg);
            }
            return $fn->name === 'len' ? Type::I_INT : Type::I_VOID;
        }
        $t = $this->checkExpr($e->args[0]);
        if ($fn->name === 'len') {
            if (!$this->table->isString($t) && !$this->table->isArray($t) && !$this->table->isMap($t)) {
                $this->error('len() 只支持 string、array 与 map', $e->pos);
            }
            return Type::I_INT;
        }
        if ($fn->name === 'array_keys') {
            if (!$this->table->isMap($t)) {
                $this->error('array_keys() 需要 map 参数', $e->pos);
                return Type::I_ARRAY;
            }
            return $this->table->arrayOf($this->table->mapKeyOf($t));
        }
        return Type::I_VOID; // dump
    }

    private function checkArgs(FnSymbol $fn, array $args, ?Pos $pos): void
    {
        $n = count($args);
        if ($n > count($fn->params)) {
            $this->error("'{$fn->name}()' 参数过多", $pos);
        }
        foreach ($fn->params as $i => $param) {
            if ($i < $n) {
                $at = $this->checkExpr($args[$i]);
                if (!$this->assignableExpr($param->type, $args[$i])) {
                    $this->error(
                        "'{$fn->name}()' 第 " . ($i + 1) . " 个参数类型不匹配：期望 "
                        . $this->table->displayName($param->type) . '，得到 '
                        . $this->table->displayName($at) . $this->narrowHint($param->type, $at),
                        $args[$i]->pos,
                    );
                }
                // callable 形参：闭包实参的签名流入形参（函数体内 $f(...) 按此校验）
                if ($this->table->isCallable($param->type)) {
                    $argSig = $this->callableArgSig($args[$i]);
                    if ($argSig !== null) {
                        $param->closureSig = $argSig;
                    }
                }
            } elseif (!$param->hasDefault) {
                $this->error("'{$fn->name}()' 缺少参数 \${$param->name}", $pos);
            }
        }
    }

    /** callable 实参的闭包签名：闭包字面量 / 已赋值闭包变量 / 返回闭包的调用表达式。 */
    private function callableArgSig(Expr $arg): ?array
    {
        if ($arg instanceof ClosureExpr) {
            return $arg->sig;
        }
        if ($arg instanceof VarExpr) {
            return $this->scope->find($arg->name)?->closureSig;
        }
        if ($arg instanceof CallExpr) {
            return $arg->retClosureSig;
        }
        return null;
    }

    /** 调用点回填：闭包 callable 形参从实参流入签名（闭包体内 $fn(...) 依赖此签名）。 */
    private function backfillCallableParams(FnSymbol $closureFn, array $args, int $n): void
    {
        foreach ($closureFn->params as $i => $cp) {
            if ($i >= $n || !$this->table->isCallable($cp->type)) {
                continue;
            }
            $argSig = $this->callableArgSig($args[$i]);
            if ($argSig !== null) {
                $cp->closureSig = $argSig;
            }
        }
    }

    private function checkNew(NewExpr $e): int
    {
        if (isset($this->table->ifaces[$e->class])) {
            $this->error("接口 '{$e->class}' 不能实例化", $e->pos);
            return Type::NONE;
        }
        if (isset($this->table->traits[$e->class])) {
            $this->error("trait '{$e->class}' 不能实例化（编译期展开为使用类的方法）", $e->pos);
            return Type::NONE;
        }
        $class = $this->table->classes[$e->class] ?? null;
        if ($class === null) {
            $this->error("未定义的类 '{$e->class}'", $e->pos);
            return Type::NONE;
        }
        if ($class->isEnum) {
            $this->error("枚举 '{$e->class}' 不能实例化（case 是唯一的实例）", $e->pos);
            return Type::NONE;
        }
        if ($class->isAbstract) {
            $this->error("抽象类 '{$e->class}' 不能实例化", $e->pos);
            return Type::NONE;
        }
        $ctor = $class->findMethod('__construct'); // 子类可继承父类构造器
        if ($ctor !== null) {
            if (!$this->canAccess($ctor->ownerClass, $ctor->vis)) {
                $this->error("类 {$class->name} 的构造器不可访问", $e->pos);
            }
            $this->checkArgs($ctor, $e->args, $e->pos);
        } elseif ($e->args !== []) {
            $this->error("类 {$class->name} 没有 __construct，不能传参数", $e->pos);
        }
        return $class->code;
    }

    private function checkMethodCall(MethodCall $e): int
    {
        $ot = $this->checkExpr($e->obj);

        // 接口上的方法调用（itab 分发）：边到全部实现类的最近实现（动态分发保守集）
        if ($this->table->isInterface($ot)) {
            $iface = $this->table->interfaceByCode($ot);
            $sig = $iface->findMethod($e->name);
            if ($sig === null) {
                $this->error("接口 {$iface->name} 没有方法 {$e->name}()", $e->pos);
                foreach ($e->args as $arg) {
                    $this->checkExpr($arg);
                }
                return Type::NONE;
            }
            if ($this->curFn !== null) {
                foreach ($this->table->implsOf($iface) as $impl) {
                    $m = $impl->findMethod($e->name);
                    if ($m !== null) {
                        $this->table->addCallEdge($this->table->symbolId($this->curFn), $this->table->symbolId($m));
                    }
                }
            }
            $this->checkArgs($sig, $e->args, $e->pos);
            return $sig->ret;
        }

        if (!$this->table->isClass($ot)) {
            if ($this->table->isObject($ot)) {
                $this->error('object 类型无方法布局，请先用 instanceof 收窄到具体类再调用 ->' . $e->name . '()', $e->pos);
            } else {
                $this->error(
                    $this->table->displayName($ot) . ' 类型不能调用方法 ->' . $e->name . '()',
                    $e->pos,
                );
            }
            foreach ($e->args as $arg) {
                $this->checkExpr($arg);
            }
            return Type::NONE;
        }
        $class = $this->table->classByCode($ot);
        $fn = $class->findMethod($e->name);
        if ($fn === null) {
            $this->error("类 {$this->clsLabel($class)} 没有方法 {$e->name}()", $e->pos);
            foreach ($e->args as $arg) {
                $this->checkExpr($arg);
            }
            return Type::NONE;
        }
        if ($fn->isStatic) {
            $this->error("静态方法请用 {$this->clsLabel($class)}::{$e->name}() 调用", $e->pos);
        }
        if (!$this->canAccess($fn->ownerClass, $fn->vis)) {
            $this->error("无法访问 {$this->clsLabel($fn->ownerClass)} 的 {$fn->vis} 方法 {$e->name}()", $e->pos);
        }
        // 动态分发保守集：接收者类 + 全部子类的最近实现（运行时实际可能执行的符号）
        if ($this->curFn !== null) {
            foreach ($this->table->subclassesOf($class) as $cls) {
                $m = $cls->findMethod($e->name);
                if ($m !== null) {
                    $this->table->addCallEdge($this->table->symbolId($this->curFn), $this->table->symbolId($m));
                }
            }
        }
        $this->checkArgs($fn, $e->args, $e->pos);
        return $fn->ret;
    }

    private function checkStaticCall(StaticCall $e): int
    {
        $class = $this->resolveStaticClass($e->class);
        if ($class === null) {
            foreach ($e->args as $arg) {
                $this->checkExpr($arg);
            }
            return Type::NONE;
        }
        $fn = $class->findMethod($e->method);
        if ($fn === null) {
            $this->error("类 {$this->clsLabel($class)} 没有方法 {$e->method}()", $e->pos);
            foreach ($e->args as $arg) {
                $this->checkExpr($arg);
            }
            return Type::NONE;
        }
        if (!$fn->isStatic && !$fn->isCtor) {
            $this->error("不能用 :: 调用实例方法，请使用 ->{$e->method}()", $e->pos);
        }
        if ($fn->isAbstract) {
            // 抽象方法无实现（PHP 为运行时错误，这里编译期拦截）
            $this->error("不能调用抽象方法 {$fn->ownerClass->name}::{$e->method}()（无实现）", $e->pos);
        }
        if (!$this->canAccess($fn->ownerClass, $fn->vis)) {
            $this->error("无法访问 {$fn->ownerClass->name} 的 {$fn->vis} 方法 {$e->method}()", $e->pos);
        }
        // 静态调用的动态绑定保守集（self::/parent:: 在子类上下文可能指向重写）
        if ($this->curFn !== null) {
            foreach ($this->table->subclassesOf($class) as $cls) {
                $m = $cls->findMethod($e->method);
                if ($m !== null) {
                    $this->table->addCallEdge($this->table->symbolId($this->curFn), $this->table->symbolId($m));
                }
            }
        }
        $this->checkArgs($fn, $e->args, $e->pos);
        return $fn->ret;
    }

    private function checkCast(CastExpr $e): int
    {
        $target = $this->resolveTypeRef($e->target);
        $src = $this->checkExpr($e->expr);
        if ($target === Type::NONE) {
            return Type::NONE;
        }
        if ($this->table->isVoid($target)) {
            $this->error('不能强转为 void', $e->pos);
            return Type::NONE;
        }
        if (!$this->allowedCast($target, $src)) {
            $this->error(
                '不能把 ' . $this->table->displayName($src)
                . ' 强转为 ' . $this->table->displayName($target),
                $e->pos,
            );
            return Type::NONE;
        }
        return $target;
    }

    private function requireIntLike(Expr $e, string $what): void
    {
        $t = $this->checkExpr($e);
        // CVAL 允许作下标（信任程序员）
        if (!$this->table->isIntLike($t) && $t !== Type::I_CVAL) {
            $this->error(
                "{$what}必须是整数类型（得到 " . $this->table->displayName($t) . '）',
                $e->pos,
            );
        }
    }
}
