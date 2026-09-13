<?php

declare(strict_types=1);

namespace Tphp\Checker;

use Tphp\Ast\Stmt;
use Tphp\Ast\expr\ArrayLit;
use Tphp\Ast\expr\ClosureExpr;
use Tphp\Ast\stmt\BlockStmt;
use Tphp\Ast\stmt\BreakStmt;
use Tphp\Ast\stmt\ContinueStmt;
use Tphp\Ast\stmt\DoWhileStmt;
use Tphp\Ast\stmt\EchoStmt;
use Tphp\Ast\stmt\ExprStmt;
use Tphp\Ast\stmt\ForeachStmt;
use Tphp\Ast\stmt\ForStmt;
use Tphp\Ast\stmt\IfStmt;
use Tphp\Ast\stmt\LocalConstStmt;
use Tphp\Ast\stmt\LocalDecl;
use Tphp\Ast\stmt\ReturnStmt;
use Tphp\Ast\stmt\SwitchStmt;
use Tphp\Ast\stmt\ThrowStmt;
use Tphp\Ast\stmt\WhileStmt;
use Tphp\Table\ConstSymbol;
use Tphp\Table\Scope;
use Tphp\Table\VarSymbol;
use Tphp\Type\Type;

/** 语句检查：控制流合法性、条件必须 bool、局部变量声明。 */
trait CheckStmtTrait
{
    /** @param list<Stmt> $stmts */
    private function checkStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->checkStmt($stmt);
        }
    }

    /**
     * 在新的子作用域中检查语句序列。
     * `$facts` 为该分支/循环体成立时的流敏感收窄事实，仅在子作用域内生效。
     *
     * @param list<Stmt> $stmts
     * @param list<array{name:string,class:int,cStorageType:int,pos:\Tphp\Token\Pos}> $facts
     */
    private function checkStmtsScoped(array $stmts, array $facts = []): void
    {
        $saved = $this->scope;
        $this->scope = new Scope($saved, $saved->fn);
        if ($facts !== []) {
            $this->applyFacts($facts);
        }
        $this->checkStmts($stmts);
        $this->scope = $saved;
    }

    private function checkStmt(Stmt $s): void
    {
        if ($s instanceof ExprStmt) {
            $this->checkExpr($s->expr);
            return;
        }

        if ($s instanceof EchoStmt) {
            foreach ($s->parts as $part) {
                $t = $this->checkExpr($part);
                if (!$this->table->isScalar($t)) {
                    $this->error(
                        'echo 不支持 ' . $this->table->displayName($t) . ' 类型',
                        $part->pos,
                    );
                }
            }
            return;
        }

        if ($s instanceof IfStmt) {
            $this->requireBool($s->cond, 'if 条件');
            // then 分支：条件为真 ⇒ 条件中的 instanceof 事实成立，仅在该子作用域内生效
            $this->checkStmtsScoped($s->then, $this->narrowFacts($s->cond));
            // else 分支：条件为假不产生正向收窄
            if ($s->else !== null) {
                $this->checkStmtsScoped($s->else);
            }
            // 守卫子句：if (!($x instanceof C)) { return/throw/...; } ⇒ 其后 $x 收窄为 C
            $neg = $this->negativeNarrowFact($s->cond);
            if ($neg !== null && $this->alwaysTerminates($s->then)) {
                $this->writeNarrow($neg['name'], $neg['class'], $neg['cStorageType'], $neg['pos']);
            }
            return;
        }

        if ($s instanceof WhileStmt) {
            $this->requireBool($s->cond, 'while 条件');
            // 每轮都以条件为真进入循环体 ⇒ 条件中的 instanceof 事实在体内成立
            $facts = $this->narrowFacts($s->cond);
            $this->loopDepth++;
            $this->checkStmtsScoped($s->body, $facts);
            $this->loopDepth--;
            return;
        }

        if ($s instanceof DoWhileStmt) {
            // 循环体先于条件执行：首轮进入时条件未必成立，故不做收窄
            $this->loopDepth++;
            $this->checkStmtsScoped($s->body);
            $this->loopDepth--;
            $this->requireBool($s->cond, 'do-while 条件');
            return;
        }

        if ($s instanceof ForStmt) {
            $saved = $this->scope;
            $this->scope = new Scope($saved, $saved->fn);
            if ($s->init !== null) {
                $this->checkStmt($s->init);
            }
            if ($s->cond !== null) {
                $this->requireBool($s->cond, 'for 条件');
            }
            if ($s->post !== null) {
                $this->checkExpr($s->post);
            }
            // init/post 已检查完毕；条件为真才进入循环体 ⇒ 条件中的 instanceof 事实应用到体内
            if ($s->cond !== null) {
                $this->applyFacts($this->narrowFacts($s->cond));
            }
            $this->loopDepth++;
            $this->checkStmts($s->body);
            $this->loopDepth--;
            $this->scope = $saved;
            return;
        }

        if ($s instanceof ForeachStmt) {
            $arrType = $this->checkExpr($s->arr);
            if (!$this->table->isArray($arrType)) {
                if ($this->table->isMap($arrType)) {
                    $this->error('map 的遍历请用 array_keys($m)（哈希无序）+ 下标读', $s->arr->pos);
                } else {
                    $this->error('foreach 只能遍历数组，得到 ' . $this->table->displayName($arrType), $s->arr->pos);
                }
                return;
            }
            $elem = $this->table->arrayElemOf($arrType);
            $saved = $this->scope;
            $this->scope = new Scope($saved, $saved->fn);
            if ($s->keyVar !== '') {
                if ($s->keyVar === $s->valVar) {
                    $this->error('foreach 的键变量与值变量不能同名', $s->pos);
                }
                $this->scope->vars[$s->keyVar] = new VarSymbol($s->keyVar, Type::I_INT, $s->pos);
            }
            $this->scope->vars[$s->valVar] = new VarSymbol($s->valVar, $elem, $s->pos);
            $this->loopDepth++;
            $this->checkStmts($s->body);
            $this->loopDepth--;
            $this->scope = $saved;
            return;
        }

        if ($s instanceof SwitchStmt) {
            $condType = $this->checkExpr($s->cond);
            if (!$this->table->isScalar($condType)) {
                $this->error('switch 条件必须是标量', $s->cond->pos);
                return;
            }
            $hasDefault = false;
            foreach ($s->cases as $case) {
                if ($case->cond === null) {
                    if ($hasDefault) {
                        $this->error('switch 只能有一个 default', $s->pos);
                    }
                    $hasDefault = true;
                    continue;
                }
                $caseType = $this->checkExpr($case->cond);
                if (!$this->comparable($condType, $caseType)) {
                    $this->error(
                        'case 表达式类型 ' . $this->table->displayName($caseType)
                        . ' 与 switch 条件 ' . $this->table->displayName($condType) . ' 不可比较',
                        $case->cond->pos,
                    );
                }
            }
            $this->switchDepth++;
            foreach ($s->cases as $case) {
                $this->checkStmtsScoped($case->stmts);
            }
            $this->switchDepth--;
            return;
        }

        if ($s instanceof ThrowStmt) {
            $t = $this->checkExpr($s->expr);
            if (!$this->table->isString($t)) {
                $this->error(
                    'throw 的错误消息必须是 string（得到 ' . $this->table->displayName($t) . '）',
                    $s->expr->pos,
                );
            }
            return;
        }

        if ($s instanceof BreakStmt) {
            if ($this->loopDepth === 0 && $this->switchDepth === 0) {
                $this->error('break 只能出现在循环或 switch 中', $s->pos);
            }
            return;
        }

        if ($s instanceof ContinueStmt) {
            if ($this->loopDepth === 0) {
                $this->error('continue 只能出现在循环中', $s->pos);
            }
            return;
        }

        if ($s instanceof ReturnStmt) {
            $fnRet = $this->curFn?->ret ?? Type::I_VOID;
            if ($fnRet === Type::NONE) {
                // 箭头闭包返回类型推断（省略 : T 时取 return 表达式类型）
                if ($s->expr === null) {
                    $this->error('箭头闭包必须 return 一个值', $s->pos);
                    return;
                }
                $this->curFn->ret = $this->checkExpr($s->expr);
                return;
            }
            $ret = $fnRet;
            if ($s->expr === null) {
                if (!$this->table->isVoid($ret)) {
                    $this->error(
                        $this->curFn === null
                            ? 'return 不能出现在函数外'
                            : "函数必须返回 {$this->table->displayName($ret)} 类型的值",
                        $s->pos,
                    );
                }
                return;
            }
            if ($this->table->isVoid($ret)) {
                $this->error('void 函数不能返回值', $s->pos);
                $this->checkExpr($s->expr);
                return;
            }
            $t = $this->checkExpr($s->expr);
            if (!$this->assignableExpr($ret, $s->expr)) {
                $this->error(
                    '返回类型不匹配：期望 ' . $this->table->displayName($ret)
                    . '，得到 ' . $this->table->displayName($t) . $this->narrowHint($ret, $t),
                    $s->expr->pos,
                );
            }
            // callable 返回 + 闭包字面量：签名挂到函数（调用点经 retClosureSig 流向接收变量）
            if ($this->table->isCallable($ret) && $s->expr instanceof ClosureExpr) {
                $this->curFn->retClosureSig = $s->expr->sig;
            }
            return;
        }

        if ($s instanceof BlockStmt) {
            $this->checkStmtsScoped($s->stmts);
            return;
        }

        if ($s instanceof LocalConstStmt) {
            $type = $s->typeRef !== null ? $this->resolveTypeRef($s->typeRef) : Type::NONE;
            if ($type !== Type::NONE && !$this->table->isScalar($type)) {
                $this->error('常量类型必须是标量（int/float/double/bool/string 或 c.* 标量）', $s->pos);
                return;
            }
            if (!$this->isLiteralScalar($s->value) && $this->inferLiteralType($s->value) === Type::NONE) {
                $this->error('常量值必须是标量字面量', $s->value->pos);
                return;
            }
            if ($type === Type::NONE) {
                $type = $this->inferLiteralType($s->value);
                if (!$this->table->isScalar($type)) {
                    $this->error('无法推断常量类型（值必须是标量字面量）', $s->value->pos);
                    return;
                }
            }
            if (!$this->literalMatchesType($s->value, $type)) {
                $this->error(
                    "常量值类型与 {$this->table->displayName($type)} 不匹配",
                    $s->value->pos,
                );
            }
            if ($this->scope->findLocal($s->name) !== null || $this->scope->findConst($s->name) !== null) {
                $this->error("常量 '{$s->name}' 在同一作用域重复声明", $s->pos);
                return;
            }
            $s->varType = $type;
            $this->scope->consts[$s->name] = new ConstSymbol($s->name, $type, $s->value, pos: $s->pos);
            return;
        }

        if ($s instanceof LocalDecl) {
            $type = $this->resolveTypeRef($s->typeRef);
            $s->varType = $type;
            if ($this->table->isVoid($type)) {
                $this->error('变量类型不能是 void', $s->typeRef->pos);
            }
            if ($this->scope->findLocal($s->name) !== null) {
                $this->error("变量 '\${$s->name}' 在同一作用域重复声明", $s->pos);
            }
            if ($s->init !== null) {
                if ($s->init instanceof ArrayLit && $this->table->isArray($type)) {
                    // 数组字面量借目标元素类型做上下文检查
                    if (!$this->checkArrayLitAgainst($s->init, $this->table->arrayElemOf($type))) {
                        $s->init->type = $type;
                    }
                } elseif ($s->init instanceof ArrayLit && $this->table->isMap($type)) {
                    // map 字面量按目标 K/V 逐对校验，回填 map 类型（Gen 据此生成 map_new）
                    $this->checkMapLitAgainst($s->init, $type);
                    $s->init->type = $type;
                } else {
                    $t = $this->checkExpr($s->init);
                    if (!$this->assignableExpr($type, $s->init)) {
                        $this->error(
                            '初始化类型不匹配：期望 ' . $this->table->displayName($type)
                            . '，得到 ' . $this->table->displayName($t) . $this->narrowHint($type, $t),
                            $s->init->pos,
                        );
                    }
                }
            }
            $vs = new VarSymbol($s->name, $type, $s->pos);
            if ($s->init instanceof ClosureExpr) {
                $vs->closureSig = $s->init->sig;
            }
            if (isset($this->boxedNames[$s->name])) {
                $vs->boxed = true;
                $s->boxed = true;
            }
            $this->scope->vars[$s->name] = $vs;
            return;
        }
    }

    private function requireBool(object $expr, string $what): void
    {
        $t = $this->checkExpr($expr);
        // CVAL 允许出现在条件上下文（如 if (c->is_ready())，非零即真）
        if (!$this->table->isBool($t) && $t !== Type::I_CVAL) {
            $this->error(
                "{$what}必须是 bool（得到 " . $this->table->displayName($t) . '）',
                $expr->pos,
            );
        }
    }
}
