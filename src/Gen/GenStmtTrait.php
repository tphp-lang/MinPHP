<?php

declare(strict_types=1);

namespace Tphp\Gen;

use Tphp\Ast\Expr;
use Tphp\Ast\Stmt;
use Tphp\Ast\expr\AssignExpr;
use Tphp\Ast\expr\CallExpr;
use Tphp\Ast\expr\IndexExpr;
use Tphp\Ast\expr\PropFetch;
use Tphp\Ast\expr\VarExpr;
use Tphp\Ast\expr\NullLit;
use Tphp\Ast\stmt\BlockStmt;
use Tphp\Ast\stmt\BreakStmt;
use Tphp\Ast\stmt\CaseClause;
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
use Tphp\Token\TokenKind;
use Tphp\Type\Type;

/** 语句生成。 */
trait GenStmtTrait
{
    private function genStmt(Stmt $s): void
    {
        $this->sourceLine($s->pos);
        $this->rcStmtBegin();

        if ($s instanceof ExprStmt) {
            if ($s->expr instanceof CallExpr && $s->expr->name === 'unset') {
                $this->genUnsetStmt($s->expr);
            } elseif ($s->expr instanceof AssignExpr) {
                $this->genAssignStmt($s->expr); // 顶层赋值：支持推断声明落 C
            } else {
                $this->w($this->genExpr($s->expr) . ';');
            }
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof EchoStmt) {
            foreach ($s->parts as $part) {
                $this->w($this->echoStmt($this->genExpr($part), $part->type) . ';');
            }
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof IfStmt) {
            $this->genIf($s);
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof WhileStmt) {
            $this->w('while (' . $this->genExpr($s->cond) . ') {');
            $this->indent++;
            $this->rcScopeBegin('loop');
            $this->genStmtsRaw($s->body);
            $this->rcScopeEnd();
            $this->indent--;
            $this->w('}');
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof DoWhileStmt) {
            $this->w('do {');
            $this->indent++;
            $this->rcScopeBegin('loop');
            $this->genStmtsRaw($s->body);
            $this->rcScopeEnd();
            $this->indent--;
            $this->w('} while (' . $this->genExpr($s->cond) . ');');
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof ForStmt) {
            $this->genFor($s);
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof ForeachStmt) {
            $this->genForeach($s);
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof SwitchStmt) {
            $this->genSwitch($s);
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof BreakStmt) {
            $this->rcCleanupBreak();
            $this->w('break;');
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof ContinueStmt) {
            $this->rcCleanupContinue();
            $this->w('continue;');
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof ReturnStmt) {
            $this->genReturn($s);
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof ThrowStmt) {
            // 设置错误并立即传播（零值返回）：先清理本函数持有的引用
            $this->w('tphp_err_set(' . $this->genExpr($s->expr) . ');');
            $this->rcCleanupReturn();
            $this->w($this->propagateReturn() . ' /* throw 传播 */');
            $this->lastReturned = true;
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof BlockStmt) {
            $this->w('{');
            $this->indent++;
            $this->rcScopeBegin('block');
            $this->genStmtsRaw($s->stmts);
            $this->rcScopeEnd();
            $this->indent--;
            $this->w('}');
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof LocalConstStmt) {
            // 函数内常量：const <ctype> NAME = 字面量;
            $this->w('const ' . $this->cType($s->varType) . ' '
                . Names::localVar($s->name) . ' = ' . $this->genExpr($s->value) . ';');
            $this->rcStmtEnd();
            return;
        }
        if ($s instanceof LocalDecl) {
            $this->genLocalDecl($s);
            $this->rcStmtEnd();
            return;
        }
        $this->rcStmtEnd();
    }

    /** 生成语句序列（不做 return 兜底）。 @param list<Stmt> $stmts */
    private function genStmtsRaw(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->genStmt($stmt);
        }
    }

    /**
     * 顶层赋值语句：支持推断声明的 C 落地（T name = value;）与链式赋值。
     */
    private function genAssignStmt(AssignExpr $e): void
    {
        if ($e->value instanceof AssignExpr) {
            $this->genAssignStmt($e->value); // 链式：先落地内层声明
        }
        $this->rcStmtBegin();
        $target = $e->target;
        if ($e->op === TokenKind::Eq && $target instanceof \Tphp\Ast\expr\VarExpr
            && $e->boxedDecl) {
            // 引用捕获变量的推断声明：落地为堆盒子，赋值经解引用（doc/closure.md §3.5）
            $lv = Names::localVar($target->name);
            foreach ($this->genBoxDecl($target->name, $e->type) as $line) {
                $this->w($line);
            }
            $this->rcDeclareLocal($lv, $e->type, true);
            $this->w($this->rcAssignText('(*' . $lv . '_box)', $e->type, $this->genExpr($e->value), $this->isFreshProducer($e->value)) . ';');
            $this->rcStmtEnd();
            return;
        }
        if ($e->op === TokenKind::Eq && $target instanceof \Tphp\Ast\expr\VarExpr
            && $this->rcScopeFind(Names::localVar($target->name)) === null) {
            // 推断声明的 C 落地
            $lv = Names::localVar($target->name);
            $ctype = $this->cType($e->type);
            $this->w($ctype . ' ' . $lv . ' = ' . $this->genExpr($e->value) . ';');
            $this->rcDeclareLocal($lv, $e->type);
            if ($this->isHeapType($e->type)
                && ($e->value instanceof \Tphp\Ast\expr\VarExpr || $e->value instanceof PropFetch
                    || $e->value instanceof IndexExpr)) {
                $this->rcRefStmt($lv, $e->type); // 借用初始化：变量需要自己的引用
            }
            $this->rcStmtEnd();
            return;
        }
        $this->w($this->genAssign($e) . ';');
        $this->rcStmtEnd();
    }

    private function genLocalDecl(LocalDecl $s): void
    {
        $type = $s->varType;
        $name = Names::localVar($s->name);
        if ($s->boxed) {
            // 引用捕获变量：存储提升为堆盒子（doc/closure.md §3.5）
            foreach ($this->genBoxDecl($s->name, $type) as $line) {
                $this->w($line);
            }
            $this->rcDeclareLocal($name, $type, true);
            if ($s->init !== null) {
                $this->w($this->rcAssignText('(*' . $name . '_box)', $type, $this->genExpr($s->init), $this->isFreshProducer($s->init)) . ';');
            }
            return;
        }
        $init = $s->init !== null ? $this->genExpr($s->init) : $this->zeroValue($type);
        // 类类型的向上转型：显式 C 指针转型
        if ($s->init !== null && $this->table->isClass($type)
            && $this->table->isClass($s->init->type) && $type !== $s->init->type) {
            $init = '(' . $this->cType($type) . ')(' . $init . ')';
        }
        // object 声明：类实例零成本指针转型；接口值取 .obj 解包
        if ($s->init !== null && $this->table->isObject($type)) {
            if ($this->needsIfaceUnwrap($s->init, $type)) {
                $init = $this->genIfaceUnwrap($init);
            } elseif ($this->table->isClass($s->init->type)) {
                $init = '(' . $this->cType($type) . ')(' . $init . ')';
            }
        }
        // 接口声明：null → {0}；类实例 → 包 itab 胖指针
        if ($this->table->isInterface($type)) {
            if ($s->init === null || $s->init instanceof NullLit) {
                $init = '{0}';
            } elseif ($this->table->isClass($s->init->type)) {
                $init = $this->genIfaceWrap($s->init, $init, $type);
            }
        }
        $name = Names::localVar($s->name);
        $this->w($this->cType($type) . ' ' . $name . ' = ' . $init . ';');
        // 堆类型登记进 RC 作用域；借用初始化（变量/字段读）需要自己的引用
        $this->rcDeclareLocal($name, $type);
        if ($s->init !== null && $this->isHeapType($type)
            && ($s->init instanceof \Tphp\Ast\expr\VarExpr || $s->init instanceof PropFetch
                || $s->init instanceof IndexExpr)) {
            $this->rcRefStmt($name, $type);
        }
    }

    /** return：所有权转移 / 借用 incref / 全作用域清理。 */
    /** 当前函数有深度保护时输出 leave（调用图环检测收敛后的条件插桩）。 */
    private function condDepthLeave(): void
    {
        if ($this->curDepthGuard) {
            $this->w('tphp_depth_leave();');
        }
    }

    private function genReturn(ReturnStmt $s): void
    {
        if ($s->expr === null) {
            $this->rcCleanupReturn();
            $this->condDepthLeave();
            $this->w('tphp_cmem_free_since(__cmem);');
            $this->w('return;');
            $this->lastReturned = true;
            return;
        }
        $text = $this->genExpr($s->expr);
        // 返回接口而表达式是类实例：包 itab 胖指针；null → {0}
        if ($this->table->isInterface($this->curRet)) {
            if ($s->expr instanceof NullLit) {
                $text = '{0}';
            } elseif ($this->table->isClass($s->expr->type)) {
                $text = $this->genIfaceWrap($s->expr, $text, $this->curRet);
            }
        }
        // 返回 object 而表达式是接口：取 .obj 解包；类是零成本指针转型
        if ($this->table->isObject($this->curRet)) {
            if ($this->needsIfaceUnwrap($s->expr, $this->curRet)) {
                $text = $this->genIfaceUnwrap($text);
            } elseif ($this->table->isClass($s->expr->type)) {
                $text = '(' . $this->cType($this->curRet) . ')(' . $text . ')';
            }
        }
        $heap = $this->isHeapType($s->expr->type);
        if ($heap && $s->expr instanceof \Tphp\Ast\expr\VarExpr) {
            // 返回局部变量：所有权转移给调用方（作用域清理跳过）
            $this->rcScopeTake(Names::localVar($s->expr->name));
        } elseif ($heap && !($s->expr instanceof NullLit)
            && ($s->expr instanceof PropFetch || $s->expr instanceof IndexExpr)) {
            // 返回借用（字段/元素）：调用方需要自己的引用
            $this->rcRefStmt($text, $s->expr->type);
        } elseif ($heap && $s->expr instanceof \Tphp\Ast\expr\ThisExpr) {
            // 返回 $this（: self 链式）：借用 incref——调用方持有自己的引用
            $this->rcRefStmt($text, $s->expr->type);
        }
        // 顺序很关键：**先求值返回表达式**，再做本帧清理。
        // 返回表达式可能"读"局部堆变量（如 return implode("", $parts)），
        // 若先 rcCleanupReturn() 会释放它 → 读到已释放内存（段错误）。
        // 顺带：递归调用发生在求值处，本帧深度必须仍在计数，故 leave 也放在求值之后。
        $this->w($this->cType($this->curRet) . ' __rv = ' . $text . ';');
        $this->rcCleanupReturn();
        $this->w('tphp_cmem_free_since(__cmem);');
        $this->condDepthLeave();
        $this->w('return __rv;');
        $this->lastReturned = true;
    }

    // ------------------------------------------------------------------ if / else-if 链

    private function genIf(IfStmt $s): void
    {
        $text = $this->buildIf($s, '');
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            $this->w($line);
        }
    }

    private function buildIf(IfStmt $s, string $prefix): string
    {
        $out = $prefix . 'if (' . $this->genExpr($s->cond) . ") {\n";
        $out .= $this->captureBlock($s->then);
        if ($s->else === null) {
            return $out . "}\n";
        }
        if (count($s->else) === 1 && $s->else[0] instanceof IfStmt) {
            return $out . $this->buildIf($s->else[0], '} else '); // else-if 链
        }
        return $out . "} else {\n" . $this->captureBlock($s->else) . "}\n";
    }

    /** 在独立缓冲中生成语句块（相对缩进 1，含 RC 作用域）。 @param list<Stmt> $stmts */
    private function captureBlock(array $stmts): string
    {
        $savedBuf = $this->sections[$this->cur];
        $savedIndent = $this->indent;
        $this->sections[$this->cur] = '';
        $this->indent = 1;
        $this->rcScopeBegin('block');
        $this->genStmtsRaw($stmts);
        $this->rcScopeEnd();
        $out = $this->sections[$this->cur];
        $this->sections[$this->cur] = $savedBuf;
        $this->indent = $savedIndent;
        return $out;
    }

    // ------------------------------------------------------------------ for / foreach

    private function genFor(ForStmt $s): void
    {
        $init = '';
        if ($s->init instanceof LocalDecl) {
            $type = $s->init->varType;
            $init = $this->cType($type) . ' ' . Names::localVar($s->init->name)
                . ' = ' . ($s->init->init !== null ? $this->genExpr($s->init->init) : $this->zeroValue($type));
        } elseif ($s->init instanceof ExprStmt) {
            $init = $this->genExpr($s->init->expr);
        }
        $cond = $s->cond !== null ? $this->genExpr($s->cond) : '';
        $post = $s->post !== null ? $this->genExpr($s->post) : '';
        $this->w('for (' . $init . '; ' . $cond . '; ' . $post . ') {');
        $this->indent++;
        $this->rcScopeBegin('loop');
        $this->genStmtsRaw($s->body);
        $this->rcScopeEnd();
        $this->indent--;
        $this->w('}');
    }

    private function genForeach(ForeachStmt $s): void
    {
        $arrType = $s->arr->type;
        $elem = $this->table->arrayElemOf($arrType);
        $arr = $this->tmp('arr');
        $i = $this->tmp('i');
        $val = Names::localVar($s->valVar);

        $this->w('{');
        $this->indent++;
        // foreach 作用域：只放遍历数组（break 时清理，continue 时保持存活）
        $this->rcScopeBegin('foreach');
        $this->w('Array* ' . $arr . ' = ' . $this->genExpr($s->arr) . ';');
        if ($this->isFreshProducer($s->arr)) {
            $this->rcDeclareLocal($arr, $arrType);
        }
        $this->w('if (!' . $arr . ') ' . $this->panicCall('foreach 遍历了 null 数组') . ';');
        $this->w('for (int32_t ' . $i . ' = 0; ' . $i . ' < ' . $arr . '->length; ' . $i . '++) {');
        $this->indent++;
        if ($s->keyVar !== '') {
            $this->w('int32_t ' . Names::localVar($s->keyVar) . ' = ' . $i . ';');
        }
        $this->w($this->elemBindCode($elem, $val, $arr, $i));
        // 值变量每轮持有一个元素引用：放进本轮的 loop 作用域，
        // 正常迭代由作用域结束释放；break/continue 由清理规则释放
        if ($this->isHeapType($elem)) {
            $this->rcElemRefStmt($val, $elem);
            $this->rcScopeBegin('loop');
            $this->rcDeclareLocal($val, $elem);
            $this->genStmtsRaw($s->body);
            $this->rcScopeEnd();
        } else {
            $this->genStmtsRaw($s->body);
        }
        $this->indent--;
        $this->w('}');
        $this->rcScopeEnd();
        $this->indent--;
        $this->w('}');
    }

    private function panicCall(string $msg): string
    {
        return 'tphp_panic("' . addcslashes($msg, '"\\') . '")';
    }

    /** foreach 值变量绑定语句。 */
    private function elemBindCode(int $elem, string $val, string $arr, string $i): string
    {
        if ($elem === Type::I_INT) {
            return 'int32_t ' . $val . ' = tphp_arr_get_int(' . $arr . ', ' . $i . ');';
        }
        if ($elem === Type::I_DOUBLE) {
            return 'double ' . $val . ' = tphp_arr_get_double(' . $arr . ', ' . $i . ');';
        }
        if ($elem === Type::I_FLOAT) {
            return 'float ' . $val . ' = tphp_arr_get_float(' . $arr . ', ' . $i . ');';
        }
        if ($elem === Type::I_BOOL) {
            return 'bool ' . $val . ' = tphp_arr_get_bool(' . $arr . ', ' . $i . ');';
        }
        if ($elem === Type::I_STRING) {
            return 'String ' . $val . ' = tphp_arr_get_str(' . $arr . ', ' . $i . ');';
        }
        if ($this->table->isArray($elem)) {
            return 'Array* ' . $val . ' = tphp_arr_get_arr(' . $arr . ', ' . $i . ');';
        }
        if ($this->table->isClass($elem)) {
            $struct = Names::classStruct($this->table->className($elem));
            return $struct . '* ' . $val . ' = (' . $struct . '*)tphp_arr_get_obj(' . $arr . ', ' . $i . ');';
        }
        if ($this->table->isObject($elem)) {
            return $this->elemCType($elem) . ' ' . $val . ' = (' . $this->elemCType($elem)
                . ')tphp_arr_get_obj(' . $arr . ', ' . $i . ');';
        }
        // c.* 标量：按原始字节读取
        $ctype = $this->elemCType($elem);
        return $ctype . ' ' . $val . ";\n"
            . $this->indentText() . 'tphp_arr_get_raw(' . $arr . ', ' . $i . ', &' . $val . ');';
    }

    private function indentText(): string
    {
        return str_repeat('    ', max(0, $this->indent));
    }

    // ------------------------------------------------------------------ switch（PHP 语义：不隐式穿透）

    private function genSwitch(SwitchStmt $s): void
    {
        $condType = $s->cond->type;
        $sw = $this->tmp('sw');
        $this->w('do {');
        $this->indent++;
        $this->w($this->cType($condType) . ' ' . $sw . ' = ' . $this->genExpr($s->cond) . ';');

        $defaultStmts = null;
        $first = true;
        foreach ($s->cases as $case) {
            if ($case->cond === null) {
                $defaultStmts = $case->stmts;
                continue;
            }
            $cmp = $this->caseCompare($sw, $condType, $case);
            $this->w(($first ? 'if' : '} else if') . ' (' . $cmp . ') {');
            $this->indent++;
            $this->rcScopeBegin('switch');
            $this->genStmtsRaw($case->stmts);
            $this->rcScopeEnd();
            $this->indent--;
            $first = false;
        }
        if ($defaultStmts !== null) {
            $this->w('} else {');
            $this->indent++;
            $this->rcScopeBegin('switch');
            $this->genStmtsRaw($defaultStmts);
            $this->rcScopeEnd();
            $this->indent--;
        }
        $this->w('}');
        $this->indent--;
        $this->w('} while (0);');
    }

    private function caseCompare(string $sw, int $condType, CaseClause $case): string
    {
        $value = $this->genExpr($case->cond);
        if ($condType === Type::I_STRING) {
            return 'tphp_str_eq(' . $sw . ', ' . $value . ')';
        }
        return '(' . $sw . ' == ' . $value . ')';
    }
}
