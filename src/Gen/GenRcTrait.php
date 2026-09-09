<?php

declare(strict_types=1);

namespace Tphp\Gen;

use Tphp\Ast\Expr;
use Tphp\Ast\expr\ArrayLit;
use Tphp\Ast\expr\CallExpr;
use Tphp\Ast\expr\MethodCall;
use Tphp\Ast\expr\NewExpr;
use Tphp\Ast\expr\StaticCall;
use Tphp\Table\FnSymbol;
use Tphp\Table\VarSymbol;
use Tphp\Type\Type;

/**
 * 内存安全插桩（引用计数）。
 *
 * 模型：堆值表达式求值即持有（owned），语句结束释放未转移的持有，
 * 作用域退出释放堆局部变量，容器写经 runtime 钩子转移所有权。
 * string（池）/ cstruct（值）/ phpc 指针（C 所有）不插桩。
 */
trait GenRcTrait
{
    /** @var list<array{kind: string, vars: array<string, int>}> RC 作用域栈（C 名 → 类型码） */
    private array $rcScopes = [];

    /** @var list<array{string, int}> 当前语句的 hoist 临时（语句尾释放） */
    private array $rcStmtReleases = [];

    private int $rcTmpSeq = 0;

    // ------------------------------------------------------------------ 判定

    public function isHeapType(int $code): bool
    {
        return $this->table->isArray($code)
            || $this->table->isClass($code)
            || $this->table->isInterface($code)
            || $this->table->isMap($code);
    }

    /** 是否堆值产生式（new / 函数与方法调用 / 静态方法调用 / 数组字面量）。 */
    private function isFreshProducer(Expr $e): bool
    {
        if ($e instanceof NewExpr || $e instanceof ArrayLit) {
            return $this->isHeapType($e->type);
        }
        if ($e instanceof CallExpr) {
            return $e->name !== 'len' && $e->name !== 'var_dump' && $this->isHeapType($e->type);
        }
        if ($e instanceof MethodCall) {
            return $this->isHeapType($e->type);
        }
        if ($e instanceof StaticCall) {
            return $this->isHeapType($e->type);
        }
        return false;
    }

    // ------------------------------------------------------------------ ref/unref

    /** 接口胖指针的引用计数走 .obj 成员。 */
    private function rcHeapPath(string $c, int $t): string
    {
        return $this->table->isInterface($t) ? $c . '.obj' : $c;
    }

    private function rcRefStmt(string $c, int $t): void
    {
        if ($this->table->isArray($t)) {
            $this->w('tphp_arr_ref(' . $c . ');');
        } elseif ($this->table->isMap($t)) {
            $this->w('tphp_map_ref(' . $c . ');');
        } else {
            $this->w('tphp_object_ref(' . $this->rcHeapPath($c, $t) . ');');
        }
    }

    private function rcUnrefText(string $c, int $t): string
    {
        if ($this->table->isArray($t)) {
            return 'tphp_arr_unref(' . $c . ')';
        }
        if ($this->table->isMap($t)) {
            return 'tphp_map_unref(' . $c . ')';
        }
        return 'tphp_object_unref(' . $this->rcHeapPath($c, $t) . ')';
    }

    private function rcUnrefStmt(string $c, int $t): void
    {
        $this->w($this->rcUnrefText($c, $t) . ';');
    }

    // ------------------------------------------------------------------ 作用域

    private function rcScopeBegin(string $kind): void
    {
        $this->rcScopes[] = ['kind' => $kind, 'vars' => []];
    }

    private function rcScopeEnd(): void
    {
        $scope = array_pop($this->rcScopes);
        foreach (array_reverse($scope['vars'], true) as $name => $v) {
            if (!$v['keep'] && $this->isHeapType($v['t'])) {
                $this->rcUnrefStmt($name, $v['t']);
            }
        }
    }

    /**
     * 登记一个局部变量到当前作用域（全部类型登记，供"是否已声明"判定；清理时仅堆类型递减）。
     * $keep=true：所有权不归本作用域（闭包捕获字段 / 引用盒子内容），退出不递减。
     */
    private function rcDeclareLocal(string $cName, int $type, bool $keep = false): void
    {
        $top = count($this->rcScopes) - 1;
        $this->rcScopes[$top]['vars'][$cName] = ['t' => $type, 'keep' => $keep];
    }

    /** 查找变量当前持有的类型码（未声明返回 null）。 */
    private function rcScopeFind(string $cName): ?int
    {
        for ($i = count($this->rcScopes) - 1; $i >= 0; $i--) {
            if (isset($this->rcScopes[$i]['vars'][$cName])) {
                return $this->rcScopes[$i]['vars'][$cName]['t'];
            }
        }
        return null;
    }

    /** 所有权转移：把变量从作用域清理表中摘除（return 变量）。 */
    private function rcScopeTake(string $cName): bool
    {
        for ($i = count($this->rcScopes) - 1; $i >= 0; $i--) {
            if (isset($this->rcScopes[$i]['vars'][$cName])) {
                unset($this->rcScopes[$i]['vars'][$cName]);
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ 控制流清理

    private function rcCleanupScope(array $scope): void
    {
        foreach (array_reverse($scope['vars'], true) as $name => $v) {
            if (!$v['keep'] && $this->isHeapType($v['t'])) {
                $this->rcUnrefStmt($name, $v['t']);
            }
        }
    }

    /** break：清理到最近的 loop / switch / foreach 作用域（含——foreach 连同遍历数组一起清理）。 */
    private function rcCleanupBreak(): void
    {
        for ($i = count($this->rcScopes) - 1; $i >= 0; $i--) {
            $scope = $this->rcScopes[$i];
            $this->rcCleanupScope($scope);
            if (in_array($scope['kind'], ['loop', 'switch', 'foreach'], true)) {
                return;
            }
        }
    }

    /** continue：清理到最近的 loop 作用域（含）；foreach 作用域是屏障（遍历数组仍存活）。 */
    private function rcCleanupContinue(): void
    {
        for ($i = count($this->rcScopes) - 1; $i >= 0; $i--) {
            $scope = $this->rcScopes[$i];
            if ($scope['kind'] === 'foreach') {
                return; // 遍历数组在后续迭代还要用
            }
            $this->rcCleanupScope($scope);
            if ($scope['kind'] === 'loop') {
                return;
            }
        }
    }

    /** return：清理全部作用域。 */
    private function rcCleanupReturn(): void
    {
        for ($i = count($this->rcScopes) - 1; $i >= 0; $i--) {
            $this->rcCleanupScope($this->rcScopes[$i]);
        }
    }

    // ------------------------------------------------------------------ 语句临时

    /** 语句开始：清空 hoist 临时收集。 */
    private function rcStmtBegin(): void
    {
        $this->rcStmtReleases = [];
    }

    /** hoist 一个堆值产生式到临时变量（用于实参等借用位置）。 */
    private function rcHoist(Expr $e): string
    {
        $tmp = '__h' . ($this->rcTmpSeq++);
        $ctype = $this->cType($e->type);
        $this->w($ctype . ' ' . $tmp . ' = ' . $this->genExpr($e) . ';');
        $this->rcStmtReleases[] = [$tmp, $e->type];
        return $tmp;
    }

    /** 语句结束：释放全部 hoist 临时。 */
    private function rcStmtEnd(): void
    {
        foreach (array_reverse($this->rcStmtReleases) as [$name, $t]) {
            $this->rcUnrefStmt($name, $t);
        }
        $this->rcStmtReleases = [];
    }

    // ------------------------------------------------------------------ 赋值辅助

    /**
     * 生成堆变量的赋值序列（R4/R5）。
     * $rhsFresh：RHS 是堆值产生式（所有权移交，无需 incref）；
     * 否则 RHS 是借用（先 incref 源，再 decref 旧目标，再赋值）。
     */
    private function rcAssignText(string $lhs, int $lhsType, string $rhs, bool $rhsFresh): string
    {
        if (!$this->isHeapType($lhsType)) {
            return $lhs . ' = ' . $rhs;
        }
        if (!$rhsFresh) {
            $rhs = '(' . $this->rcRefText($rhs, $lhsType) . ', ' . $rhs . ')';
        }
        // 先求值 RHS 入临时，再释放旧值，再赋值（self-assign 安全）
        return '({ ' . $this->cType($lhsType) . ' __r = ' . $rhs . '; '
            . $this->rcUnrefText($lhs, $lhsType) . '; ' . $lhs . ' = __r; })';
    }

    private function rcRefText(string $c, int $t): string
    {
        if ($this->table->isArray($t)) {
            return 'tphp_arr_ref(' . $c . ')';
        }
        if ($this->table->isMap($t)) {
            return 'tphp_map_ref(' . $c . ')';
        }
        return 'tphp_object_ref(' . $this->rcHeapPath($c, $t) . ')';
    }

    /** foreach 元素绑定后的 incref（get 返回借用，值变量需要持有）。 */
    private function rcElemRefStmt(string $c, int $elem): void
    {
        if ($this->table->isArray($elem)) {
            $this->w('tphp_arr_ref(' . $c . ');');
        } elseif ($this->table->isClass($elem) || $this->table->isInterface($elem)) {
            $this->w('tphp_object_ref(' . $this->rcHeapPath($c, $elem) . ');');
        }
    }

    // ------------------------------------------------------------------ 参数

    /** 函数入口：堆形参 incref（形参拥有自己的引用）。 */
    private function rcParamIncrefs(FnSymbol $fn): void
    {
        foreach ($fn->params as $param) {
            if ($this->isHeapType($param->type)) {
                $this->rcRefStmt(Names::localVar($param->name), $param->type);
            }
        }
    }

    /** 函数出口：堆形参与局部变量的 decref 由 rcCleanupReturn/rcScopeEnd 统一处理。 */

    /** 形参登记到函数作用域。 */
    private function rcRegisterParams(FnSymbol $fn): void
    {
        foreach ($fn->params as $param) {
            $this->rcDeclareLocal(Names::localVar($param->name), $param->type);
        }
    }

    // ------------------------------------------------------------------ 杂项

    /** dump 的参数位置也允许借用（dump 不持有）。 */
    private function rcBorrowArg(Expr $arg): string
    {
        if ($this->isFreshProducer($arg)) {
            return $this->rcHoist($arg);
        }
        return $this->genExpr($arg);
    }
}
