<?php

declare(strict_types=1);

namespace Tphp\Gen;

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
use Tphp\Ast\expr\InstanceOfExpr;
use Tphp\Ast\expr\IntLit;
use Tphp\Ast\expr\InterpStr;
use Tphp\Ast\expr\InvokeExpr;
use Tphp\Ast\expr\MethodCall;
use Tphp\Ast\expr\NameExpr;
use Tphp\Ast\expr\NewExpr;
use Tphp\Ast\expr\NullLit;
use Tphp\Ast\expr\OrExpr;
use Tphp\Ast\stmt\ExprStmt;
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
use Tphp\Table\ClassSymbol;
use Tphp\Table\FnSymbol;
use Tphp\Token\TokenKind;
use Tphp\Type\Type;

/**
 * 表达式生成：返回 C 表达式文本。
 *
 * 需要提前执行语句的场景（数组字面量、c.* 数组元素读取等）
 * 使用 GCC 语句表达式 ({ ... })，TCC/GCC/Clang 均支持。
 */
trait GenExprTrait
{
    /** 当前生成的类（self::/parent:: 解析用）。 */
    private ?ClassSymbol $curClassSym = null;

    /** @var array<string, bool> 无子类的类缓存 */
    private array $leafCache = [];

    /** @var list<array{int, string}> 待生成的 dump 函数体（嵌套时延后） */
    private array $pendingDumps = [];

    // ------------------------------------------------------------------ 分发

    private function genExpr(Expr $e): string
    {
        if ($e instanceof IntLit) {
            return $e->text;
        }
        if ($e instanceof FloatLit) {
            return $e->text;
        }
        if ($e instanceof BoolLit) {
            return $e->value ? 'true' : 'false';
        }
        if ($e instanceof NullLit) {
            return 'NULL';
        }
        if ($e instanceof StrLit) {
            return $this->strLitExpr($e->value);
        }
        if ($e instanceof InterpStr) {
            return $this->genInterp($e);
        }
        if ($e instanceof VarExpr) {
            return $this->varReadText($e);
        }
        if ($e instanceof ThisExpr) {
            return $this->genThis($e);
        }
        if ($e instanceof ArrayLit) {
            return $this->genArrayLit($e);
        }
        if ($e instanceof IndexExpr) {
            return $this->genIndex($e);
        }
        if ($e instanceof InstanceOfExpr) {
            return $this->genInstanceOf($e);
        }
        if ($e instanceof BinaryExpr) {
            return $this->genBinary($e);
        }
        if ($e instanceof UnaryExpr) {
            return $this->genUnary($e);
        }
        if ($e instanceof PostfixExpr) {
            return '(' . $this->genIncDec($e->expr, $e->op, false) . ')';
        }
        if ($e instanceof TernaryExpr) {
            return '(' . $this->genExpr($e->cond) . ' ? '
                . $this->genExpr($e->then) . ' : ' . $this->genExpr($e->else) . ')';
        }
        if ($e instanceof AssignExpr) {
            return $this->genAssign($e);
        }
        if ($e instanceof CallExpr) {
            $call = $this->genCall($e);
        if ($e->name === 'implode') {
            // O(n) 拼接：先算总长一次分配（字符串累加 `$s = $s . x` 的 O(n²) 正解工具）
            $sep = $this->genExpr($e->args[0]);
            $arr = $this->genExpr($e->args[1]);
            return 'tphp_str_implode(' . $sep . ', ' . $arr . ')';
        }
        // 内置函数（len/var_dump/phpc 桥接/c_fn）不会失败；用户函数统一包装错误传播
        if ($e->name === 'len' || $e->name === 'var_dump' || $e->name === 'c_str'
            || $e->name === 'php_str' || $e->name === 'php_str_ref'
            || $e->name === 'cbuf' || $e->name === 'c_own' || $e->name === 'c_fn') {
            return $call;
        }
        return $this->wrapFailable($call, $e->type);
    }
        if ($e instanceof OrExpr) {
            return $this->genOrExpr($e);
        }
        if ($e instanceof CCallExpr) {
            // 直连 C 符号：名字原样、不经过错误通道
            return $e->name . '(' . $this->genArgs($e->args) . ')';
        }
        if ($e instanceof CConstExpr) {
            return $e->name;
        }
        if ($e instanceof NewExpr) {
            return $this->wrapFailable($this->genNew($e), $e->type);
        }
        if ($e instanceof PropFetch) {
            return $this->genPropFetch($e);
        }
        if ($e instanceof MethodCall) {
            return $this->genMethodCall($e);
        }
        if ($e instanceof StaticCall) {
            return $this->genStaticCall($e);
        }
        if ($e instanceof StaticProp) {
            return $this->genStaticPropName($e);
        }
        if ($e instanceof NameExpr) {
            // 常量引用（Checker 已解析 isLocal）
            return $e->isLocal ? Names::localVar($e->name) : Names::constMacro($e->name);
        }
        if ($e instanceof StaticConst) {
            return $this->genStaticConstName($e);
        }
        if ($e instanceof ClosureExpr) {
            return $this->genClosureExpr($e);
        }
        if ($e instanceof InvokeExpr) {
            return $this->wrapFailable($this->genInvoke($e), $e->type);
        }
        if ($e instanceof CastExpr) {
            return $this->genCast($e);
        }
        return '0 /* unreachable */';
    }

    // ------------------------------------------------------------------ 字符串

    private function genInterp(InterpStr $e): string
    {
        $expr = null;
        foreach ($e->parts as $part) {
            $text = is_string($part)
                ? $this->strLitExpr($part)
                : $this->toStrExpr($this->genExpr($part), $part->type);
            $expr = $expr === null ? $text : 'tphp_str_concat(' . $expr . ', ' . $text . ')';
        }
        return $expr ?? 'tphp_str_empty()';
    }

    // ------------------------------------------------------------------ 数组

    private function genArrayLit(ArrayLit $e): string
    {
        if ($this->table->isMap($e->type)) {
            return $this->genMapLit($e);
        }
        $elem = $this->table->arrayElemOf($e->type);
        $flags = $this->table->arrayElemFlags($e->type);
        $t = $this->tmp('arr');
        $lines = [
            '({',
            $this->indentText() . '    Array* ' . $t . ' = tphp_arr_new(sizeof('
                . $this->elemCType($elem) . '), ' . count($e->items) . ', ' . $flags . ');',
        ];
        foreach ($e->items as $item) {
            // 新鲜堆值元素：先入临时（容器 incref 借用，语句尾释放临时）
            $itemText = $this->isFreshProducer($item) && $this->isHeapType($item->type)
                ? $this->rcHoist($item)
                : $this->genExpr($item);
            $lines[] = $this->indentText() . '    ' . $this->pushCall($t, $elem, $itemText, $item) . ';';
        }
        $lines[] = $this->indentText() . '    ' . $t . ';';
        $lines[] = $this->indentText() . '})';
        return implode("\n", $lines);
    }

    private function pushCall(string $arr, int $elem, string $value, ?Expr $item = null): string
    {
        if ($elem === Type::I_INT) {
            return 'tphp_arr_push_int(' . $arr . ', ' . $value . ')';
        }
        if ($elem === Type::I_DOUBLE) {
            return 'tphp_arr_push_double(' . $arr . ', ' . $value . ')';
        }
        if ($elem === Type::I_FLOAT) {
            return 'tphp_arr_push_float(' . $arr . ', ' . $value . ')';
        }
        if ($elem === Type::I_BOOL) {
            return 'tphp_arr_push_bool(' . $arr . ', ' . $value . ')';
        }
        if ($elem === Type::I_STRING) {
            return 'tphp_arr_push_str(' . $arr . ', ' . $value . ')';
        }
        if ($this->table->isArray($elem)) {
            return 'tphp_arr_push_arr(' . $arr . ', ' . $value . ')';
        }
        if ($this->table->isClass($elem)) {
            return 'tphp_arr_push_obj(' . $arr . ', ' . $value . ')';
        }
        if ($item !== null && $this->needsIfaceWrap($item, $elem)) {
            $value = $this->genIfaceWrap($item, $value, $elem);
        }
        if ($this->table->isInterface($elem)) {
            // 裸拷贝胖指针，容器需要自己的对象引用
            return '({ TphpIface __v = (' . $value . '); if (__v.obj) tphp_object_ref(__v.obj); '
                . 'tphp_arr_push_raw(' . $arr . ', &__v); })';
        }
        // c.* 标量：取地址推送
        $ctype = $this->elemCType($elem);
        return '({ ' . $ctype . ' __v = (' . $value . '); tphp_arr_push_raw(' . $arr . ', &__v); })';
    }

    private function genIndex(IndexExpr $e): string
    {
        $base = $this->genExpr($e->base);
        $idx = $e->index !== null ? $this->genExpr($e->index) : '0';
        $baseType = $e->base->type;

        if ($this->table->isMap($baseType)) {
            return $this->mapGetCall($e, $base, $idx);
        }
        if ($baseType === Type::I_STRING) {
            return 'tphp_str_char(' . $base . ', ' . $idx . ')';
        }
        $elem = $this->table->arrayElemOf($baseType);
        if ($elem === Type::I_INT) {
            return 'tphp_arr_get_int(' . $base . ', ' . $idx . ')';
        }
        if ($elem === Type::I_DOUBLE) {
            return 'tphp_arr_get_double(' . $base . ', ' . $idx . ')';
        }
        if ($elem === Type::I_FLOAT) {
            return 'tphp_arr_get_float(' . $base . ', ' . $idx . ')';
        }
        if ($elem === Type::I_BOOL) {
            return 'tphp_arr_get_bool(' . $base . ', ' . $idx . ')';
        }
        if ($elem === Type::I_STRING) {
            return 'tphp_arr_get_str(' . $base . ', ' . $idx . ')';
        }
        if ($this->table->isArray($elem)) {
            return 'tphp_arr_get_arr(' . $base . ', ' . $idx . ')';
        }
        if ($this->table->isClass($elem)) {
            $struct = Names::classStruct($this->table->className($elem));
            return '((' . $struct . '*)tphp_arr_get_obj(' . $base . ', ' . $idx . '))';
        }
        // c.* 标量：语句表达式读取
        $ctype = $this->elemCType($elem);
        return '({ ' . $ctype . ' __e; tphp_arr_get_raw(' . $base . ', ' . $idx . ', &__e); __e; })';
    }

    // ------------------------------------------------------------------ 运算

    /** map 下标读：键入适配缓冲 → get_raw → 按 V 解释值槽。缺失键运行时 panic。 */
    private function mapGetCall(IndexExpr $e, string $base, string $idx): string
    {
        $kCode = $this->table->mapKeyOf($e->base->type);
        $vCode = $this->table->mapValOf($e->base->type);
        $kis = $this->table->mapKeyIsString($e->base->type) ? 1 : 0;
        $kC = $this->elemCType($kCode);
        $vC = $this->elemCType($vCode);
        $k = '__mk';
        $v = '__mv';
        $lines = [
            '({',
            $this->indentText() . '    ' . $kC . ' ' . $k . ' = ' . $idx . ';',
            $this->indentText() . '    ' . $vC . ' ' . $v . ';',
            $this->indentText() . '    tphp_map_get_raw(' . $base . ', &' . $k . ', ' . $kis . ', &' . $v . ');',
            $this->indentText() . '    ' . $v . ';',
            $this->indentText() . '})',
        ];
        return implode("\n", $lines);
    }

    /** map 下标写：键不存在插入、存在覆盖（值槽 raw 拷贝）。 */
    private function mapSetCall(IndexExpr $target, string $value): string
    {
        $base = $this->genExpr($target->base);
        $idx = $this->genExpr($target->index);
        $kCode = $this->table->mapKeyOf($target->base->type);
        $kis = $this->table->mapKeyIsString($target->base->type) ? 1 : 0;
        $kC = $this->elemCType($kCode);
        $vC = $this->elemCType($this->table->mapValOf($target->base->type));
        $k = '__mk';
        $v = '__mv';
        $lines = [
            '({',
            $this->indentText() . '    ' . $kC . ' ' . $k . ' = ' . $idx . ';',
            $this->indentText() . '    ' . $vC . ' ' . $v . ' = ' . $value . ';',
            $this->indentText() . '    tphp_map_set_raw(' . $base . ', &' . $k . ', ' . $kis . ', &' . $v . ');',
            $this->indentText() . '})',
        ];
        return implode("\n", $lines);
    }

    /** map 字面量：map_new + 逐对 set_raw（键/值各入适配缓冲）。 */
    private function genMapLit(ArrayLit $e): string
    {
        $kis = $this->table->mapKeyIsString($e->type) ? 1 : 0;
        $vsize = $this->table->mapValSize($e->type);
        $kCode = $this->table->mapKeyOf($e->type);
        $vCode = $this->table->mapValOf($e->type);
        $kC = $this->elemCType($kCode);
        $vC = $this->elemCType($vCode);
        $t = $this->tmp('map');
        $lines = [
            '({',
            $this->indentText() . '    Map* ' . $t . ' = tphp_map_new(' . $vsize . ', ' . $kis . ');',
        ];
        foreach ($e->keys as $i => $kExpr) {
            $k = '__mk' . $i;
            $v = '__mv' . $i;
            $lines[] = $this->indentText() . '    ' . $kC . ' ' . $k . ' = ' . $this->genExpr($kExpr) . ';';
            $lines[] = $this->indentText() . '    ' . $vC . ' ' . $v . ' = ' . $this->genExpr($e->items[$i]) . ';';
            $lines[] = $this->indentText() . '    tphp_map_set_raw(' . $t . ', &' . $k . ', ' . $kis . ', &' . $v . ');';
        }
        $lines[] = $this->indentText() . '    ' . $t . ';';
        $lines[] = $this->indentText() . '})';
        return implode("\n", $lines);
    }

    /** instanceof：编译期折叠直出常量；否则判定 vtable 类型头（类沿祖先链、接口查 id 集）。 */
    private function genInstanceOf(InstanceOfExpr $e): string
    {
        if ($e->folded === true) {
            return 'true';
        }
        if ($e->folded === false) {
            return 'false';
        }
        $obj = $this->isFreshProducer($e->obj) && $this->isHeapType($e->obj->type)
            ? $this->rcHoist($e->obj)
            : $this->genExpr($e->obj);
        // 接口值是 TphpIface 胖指针：取 obj 字段（null 接口 → NULL → false）
        $ptr = $this->table->isInterface($e->obj->type) ? '(' . $obj . ').obj' : $obj;
        $iface = $this->table->ifaces[$e->class] ?? null;
        if ($iface !== null) {
            return 'tphp_instanceof_iface((void *)(' . $ptr . '), ' . $iface->code . ')';
        }
        $cls = $this->table->classes[$e->class] ?? null;
        if ($cls === null) {
            return 'false'; // Checker 已报错（<error> 兜底）
        }
        return 'tphp_instanceof_class((void *)(' . $ptr . '), ' . $cls->code . ')';
    }

    private function genBinary(BinaryExpr $e): string
    {
        $op = $e->op;
        $l = $this->genExpr($e->left);
        $r = $this->genExpr($e->right);
        $lt = $e->left->type;
        $rt = $e->right->type;

        switch ($op) {
            case TokenKind::Pow:
                if ($lt === Type::I_INT && $rt === Type::I_INT) {
                    return 'tphp_int_pow(' . $l . ', ' . $r . ')';
                }
                return 'pow((double)(' . $l . '), (double)(' . $r . '))';

            case TokenKind::Slash:
            case TokenKind::Percent:
                // 整数除法/取模：右值为 0 在 C 下是硬件异常/UB，插 panic 检查
                //（浮点走 IEEE 除零语义，不查）。右值为非零字面量时零开销直出。
                if ($lt === Type::I_INT && $rt === Type::I_INT
                    && !($e->right instanceof IntLit && $e->right->value !== 0)) {
                    $msg = $op === TokenKind::Slash ? 'division by zero' : 'modulo by zero';
                    return '({ int32_t __d = (' . $r . '); if (__d == 0) { tphp_panic("' . $msg
                        . '"); } (' . $l . ') ' . $this->opText($op) . ' __d; })';
                }
                return '((' . $l . ') ' . $this->opText($op) . ' (' . $r . '))';

            case TokenKind::Dot:
                return 'tphp_str_concat(' . $this->toStrExpr($l, $lt) . ', ' . $this->toStrExpr($r, $rt) . ')';

            case TokenKind::EqEq:
                if ($this->table->isInterface($lt) || $this->table->isInterface($rt)) {
                    return $this->ifaceCompare($lt, $rt, $l, $r, $e);
                }
                if ($lt === Type::I_STRING || $rt === Type::I_STRING) {
                    return 'tphp_str_eq(' . $l . ', ' . $r . ')';
                }
                return '((' . $l . ') == (' . $r . '))';

            case TokenKind::NotEq:
                if ($this->table->isInterface($lt) || $this->table->isInterface($rt)) {
                    // ifaceCompare 已按 $e->op 生成 != ，不能再取反（否则双重否定）
                    return $this->ifaceCompare($lt, $rt, $l, $r, $e);
                }
                if ($lt === Type::I_STRING || $rt === Type::I_STRING) {
                    return '(!tphp_str_eq(' . $l . ', ' . $r . '))';
                }
                return '((' . $l . ') != (' . $r . '))';

            case TokenKind::Lt:
            case TokenKind::Gt:
            case TokenKind::LtEq:
            case TokenKind::GtEq:
                if ($lt === Type::I_STRING && $rt === Type::I_STRING) {
                    $cmp = 'tphp_str_cmp(' . $l . ', ' . $r . ')';
                    return match ($op) {
                        TokenKind::Lt => '(' . $cmp . ' < 0)',
                        TokenKind::Gt => '(' . $cmp . ' > 0)',
                        TokenKind::LtEq => '(' . $cmp . ' <= 0)',
                        default => '(' . $cmp . ' >= 0)',
                    };
                }
                return '(' . $l . ' ' . $this->opText($op) . ' ' . $r . ')';

            default:
                // c.* 浮点混入时统一按 f64 运算
                if ($e->type === Type::I_DOUBLE
                    && ($this->isCTypeName($lt) || $this->isCTypeName($rt))) {
                    return '((double)(' . $l . ') ' . $this->opText($op) . ' (double)(' . $r . '))';
                }
                return '((' . $l . ') ' . $this->opText($op) . ' (' . $r . '))';
        }
    }

    /** 接口相等比较：比较 .obj 指针。 */
    private function ifaceCompare(int $lt, int $rt, string $l, string $r, BinaryExpr $e): string
    {
        $op = $e->op === TokenKind::EqEq ? '==' : '!=';
        // 一侧是 null 字面量
        if ($e->left instanceof NullLit) {
            return '((' . $r . ').obj ' . $op . ' NULL)';
        }
        if ($e->right instanceof NullLit) {
            return '((' . $l . ').obj ' . $op . ' NULL)';
        }
        return '((' . $l . ').obj ' . $op . ' (' . $r . ').obj)';
    }

    // ------------------------------------------------------------------ 错误处理（or）

    /** 传播错误：从当前函数返回（void 函数 return;，其余返回零值）；含 C 内存出口清理。 */
    private function propagateReturn(): string
    {
        $ret = $this->table->isVoid($this->curRet) ? 'return;' : 'return ' . $this->zeroValue($this->curRet) . ';';
        $leave = $this->curDepthGuard ? 'tphp_depth_leave(); ' : '';
        return $leave . 'tphp_cmem_free_since(__cmem); ' . $ret;
    }

    /**
     * 包裹可能失败的调用：错误发生时立即从当前函数传播。
     * 语句表达式内的 return 会跳出整个外层函数（GNU 扩展语义）。
     */
    private function wrapFailable(string $callText, int $retType): string
    {
        if ($this->inOrContext) {
            // or 上下文：不传播（不 return），改为"已有挂起错误则跳过本次调用"。
            // 这样链式 `$a->b()->c()` 中 b() 失败后不会继续对零值调用 c()，
            // 整个表达式以零值收尾，由外层 or 块统一处理（见 genOrExpr）。
            if ($this->table->isVoid($retType)) {
                return '({ if (!tphp_err_has()) { ' . $callText . '; } })';
            }
            return '({ ' . $this->cType($retType) . ' __ov = ' . $this->zeroValue($retType)
                . '; if (!tphp_err_has()) { __ov = ' . $callText . '; } __ov; })';
        }
        if ($this->table->isVoid($retType)) {
            return '({ ' . $callText . '; if (tphp_err_has()) { ' . $this->propagateReturn() . ' } })';
        }
        return '({ ' . $this->cType($retType) . ' __r = ' . $callText
            . '; if (tphp_err_has()) { ' . $this->propagateReturn() . ' } __r; })';
    }

    /** f() or { ... } 降级：正常调用 → 错误时取走 err、执行块、按值上下文取块值。 */
    private function genOrExpr(OrExpr $e): string
    {
        $ret = $e->type;
        $valueNeeded = !$this->table->isVoid($ret);
        // 在 or 上下文中生成调用：此时不做错误传播，链中任一环失败都会短路
        // （wrapFailable / emitNullCheck 依据 inOrContext 切换行为）
        $savedOrCtx = $this->inOrContext;
        $this->inOrContext = true;
        $callText = $this->genExpr($e->call);
        $this->inOrContext = $savedOrCtx;

        // 生成块体：err 绑定 + 语句 + （值上下文）最后表达式赋给 __r
        $savedBuf = $this->sections[$this->cur];
        $savedIndent = $this->indent;
        $this->sections[$this->cur] = '';
        $this->indent = 2;
        // or 块独立 RC 作用域 + 独立 hoist 释放表：块内临时值（如链式接收者）
        // 必须在**块内**释放，否则释放语句会落到块外，引用块内声明的变量（C 作用域错误）
        $this->rcScopeBegin('orblock');
        $savedReleases = $this->rcStmtReleases;
        $this->rcStmtReleases = [];
        $this->w('const String err = tphp_err_take();');
        $stmts = $e->block;
        $last = $stmts !== [] ? $stmts[count($stmts) - 1] : null;
        foreach ($stmts as $stmt) {
            if ($valueNeeded && $stmt === $last && $stmt instanceof ExprStmt) {
                $this->w('__r = ' . $this->genExpr($stmt->expr) . ';');
            } else {
                $this->genStmt($stmt);
            }
        }
        // 先释放块内 hoist 临时，再收块内局部变量（两者都在块缓冲区内生成）
        foreach (array_reverse($this->rcStmtReleases) as [$rName, $rType]) {
            $this->rcUnrefStmt($rName, $rType);
        }
        $this->rcScopeEnd();
        $blockText = $this->sections[$this->cur];
        $this->sections[$this->cur] = $savedBuf;
        $this->indent = $savedIndent;
        $this->rcStmtReleases = $savedReleases;

        $lines = ['({'];
        if ($valueNeeded) {
            $lines[] = $this->indentText() . '    ' . $this->cType($ret) . ' __r;';
            $lines[] = $this->indentText() . '    __r = ' . $callText . ';';
        } else {
            $lines[] = $this->indentText() . '    ' . $callText . ';';
        }
        $lines[] = $this->indentText() . '    if (tphp_err_has()) {';
        $lines[] = rtrim($blockText, "\n");
        $lines[] = $this->indentText() . '    }';
        if ($valueNeeded) {
            $lines[] = $this->indentText() . '    __r;';
        }
        $lines[] = $this->indentText() . '})';
        return implode("\n", $lines);
    }

    private function isCTypeName(int $code): bool
    {
        return $this->table->kindOf($code) === \Tphp\Table\TypeKind::Ctype;
    }

    private function opText(TokenKind $op): string
    {
        return match ($op) {
            TokenKind::Plus => '+',
            TokenKind::Minus => '-',
            TokenKind::Star => '*',
            TokenKind::Slash => '/',
            TokenKind::Percent => '%',
            TokenKind::Amp => '&',
            TokenKind::Pipe => '|',
            TokenKind::Caret => '^',
            TokenKind::Shl => '<<',
            TokenKind::Shr => '>>',
            TokenKind::Lt => '<',
            TokenKind::Gt => '>',
            TokenKind::LtEq => '<=',
            TokenKind::GtEq => '>=',
            TokenKind::EqEq => '==',
            TokenKind::NotEq => '!=',
            TokenKind::AndAnd => '&&',
            TokenKind::OrOr => '||',
            TokenKind::Inc => '++',
            TokenKind::Dec => '--',
            TokenKind::PlusEq => '+=',
            TokenKind::MinusEq => '-=',
            TokenKind::StarEq => '*=',
            TokenKind::SlashEq => '/=',
            TokenKind::PercentEq => '%=',
            TokenKind::AmpEq => '&=',
            TokenKind::PipeEq => '|=',
            TokenKind::CaretEq => '^=',
            TokenKind::ShlEq => '<<=',
            TokenKind::ShrEq => '>>=',
            default => '?',
        };
    }

    private function genUnary(UnaryExpr $e): string
    {
        if ($e->op === TokenKind::Inc || $e->op === TokenKind::Dec) {
            return '(' . $this->genIncDec($e->expr, $e->op, true) . ')';
        }
        $inner = $this->genExpr($e->expr);
        return match ($e->op) {
            TokenKind::Minus => '(-(' . $inner . '))',
            TokenKind::Plus => '(+(' . $inner . '))',
            TokenKind::Not => '(!(' . $inner . '))',
            TokenKind::Tilde => '(~(' . $inner . '))',
            default => '(' . $inner . ')',
        };
    }

    /** 自增自减：变量直接生成，数组元素走 get/set 对（带越界检查）。 */
    private function genIncDec(Expr $target, TokenKind $op, bool $prefix): string
    {
        $text = $this->opText($op);
        $delta = $op === TokenKind::Inc ? ' + 1' : ' - 1';

        if ($target instanceof IndexExpr) {
            $base = $this->genExpr($target->base);
            $idx = $this->genExpr($target->index);
            $elem = $this->table->arrayElemOf($target->base->type);
            if ($elem === Type::I_INT) {
                if ($prefix) {
                    return '({ tphp_arr_set_int(' . $base . ', ' . $idx
                        . ', tphp_arr_get_int(' . $base . ', ' . $idx . ')' . $delta . '); '
                        . 'tphp_arr_get_int(' . $base . ', ' . $idx . '); })';
                }
                return '({ int32_t __old = tphp_arr_get_int(' . $base . ', ' . $idx . '); '
                    . 'tphp_arr_set_int(' . $base . ', ' . $idx . ', __old' . $delta . '); __old; })';
            }
            // 其余标量元素：raw 读取 + 写回
            $ctype = $this->elemCType($elem);
            $get = 'tphp_arr_get_raw(' . $base . ', ' . $idx . ', &__v)';
            if ($prefix) {
                return '({ ' . $ctype . ' __v; ' . $get . '; __v = __v' . $delta . '; '
                    . 'tphp_arr_set_raw(' . $base . ', ' . $idx . ', &__v); __v; })';
            }
            return '({ ' . $ctype . ' __v; ' . $get . '; ' . $ctype . ' __old = __v; '
                . '__v = __v' . $delta . '; tphp_arr_set_raw(' . $base . ', ' . $idx . ', &__v); __old; })';
        }

        $lv = $this->genLValue($target);
        return $prefix ? '(' . $text . '(' . $lv . '))' : '((' . $lv . ')' . $text . ')';
    }

    /** 变量读取文本：闭包捕获重映射 → 引用盒子解引用 → 普通 C 局部。 */
    private function varReadText(VarExpr $e): string
    {
        $cap = $this->capLookup($e->name);
        if ($cap !== null) {
            return $cap;
        }
        if ($e->sym !== null && $this->isBoxedSym($e->sym)) {
            return '(*' . Names::localVar($e->name) . '_box)';
        }
        return Names::localVar($e->name);
    }

    /** $this：闭包内重映射为 env->self。 */
    private function genThis(ThisExpr $e): string
    {
        $cap = $this->capLookup('this');
        if ($cap !== null) {
            return $cap;
        }
        return 'self';
    }

    /** 生成可赋值的 C 左值文本。 */
    private function genLValue(Expr $e): string
    {
        if ($e instanceof VarExpr) {
            return $this->varReadText($e);
        }
        if ($e instanceof ThisExpr) {
            return 'self';
        }
        if ($e instanceof PropFetch) {
            return $this->genPropFetch($e);
        }
        if ($e instanceof StaticProp) {
            return $this->genStaticPropName($e);
        }
        if ($e instanceof IndexExpr) {
            return $this->genIndex($e);
        }
        return $this->genExpr($e);
    }

    // ------------------------------------------------------------------ 赋值

    private function genAssign(AssignExpr $e): string
    {
        $target = $e->target;

        // 下标写的值是新鲜堆值 → 先入临时（容器 incref 借用，语句尾释放临时）
        $needsHoist = $target instanceof IndexExpr
            && $this->isFreshProducer($e->value) && $this->isHeapType($e->value->type);
        $value = $needsHoist ? $this->rcHoist($e->value) : $this->genExpr($e->value);

        // $a[] = v：追加写。push 是原地扩容（返回同一 Array*），回写为等价操作，
        // 不产生新引用——无需释放旧值（区别于普通赋值的 R4）
        if ($target instanceof IndexExpr && $target->index === null) {
            $lv = $this->genLValue($target->base);
            $elem = $this->table->arrayElemOf($target->base->type);
            return $lv . ' = ' . $this->pushCall($lv, $elem, $value) . ';';
        }

        // $a[i] = v：普通下标写入（带越界检查；hoist 临时由语句尾统一释放）
        if ($target instanceof IndexExpr) {
            return $this->setElemCall($target, $value) . ';';
        }

        if ($e->op === TokenKind::Eq) {
            $lv = $this->genLValue($target);
            $refSrc = $value; // 借用源的原始文本（转型前）
            // 类类型的向上转型：显式 C 指针转型
            if ($this->table->isClass($e->type) && $this->table->isClass($e->value->type)
                && $e->type !== $e->value->type) {
                $value = '(' . $this->cType($e->type) . ')(' . $value . ')';
            }
            // 类 → 接口：包 itab 胖指针
            if ($this->needsIfaceWrap($e->value, $e->type)) {
                $value = $this->genIfaceWrap($e->value, $value, $e->type);
            }
            // callable / 接口 ← null 需要 zero 结构体而不是 NULL 指针
            if (($this->table->isCallable($e->type) || $this->table->isInterface($e->type))
                && $e->value instanceof NullLit) {
                return $lv . ' = ' . $this->zeroOfRef($e->type);
            }
            if (!$this->isHeapType($e->type)) {
                return $lv . ' = ' . $value;
            }
            $fresh = $this->isFreshProducer($e->value);
            $borrowed = !$fresh && ($e->value instanceof VarExpr || $e->value instanceof PropFetch
                || $e->value instanceof IndexExpr);
            // 简单变量 + 尚未声明（推断声明的降级路径）：语句表达式内声明
            // （捕获/盒子等重映射左值不走声明路径）
            if ($target instanceof VarExpr && $lv === Names::localVar($target->name)
                && $this->rcScopeFind($lv) === null) {
                $seq = '({ ' . $this->cType($e->type) . ' ' . $lv . ' = ' . $value . '; ';
                if ($borrowed) {
                    $seq .= $this->rcRefText($refSrc, $e->value->type) . '; ';
                }
                $seq .= $lv . '; })';
                return $seq;
            }
            // 堆目标（变量重赋值 / 字段 / 静态属性）：
            // 先求值入临时 → incref 借用源 → decref 旧值 → 赋值（self-assign 安全）
            $seq = '{ ' . $this->cType($e->type) . ' __r = ' . $value . '; ';
            if ($borrowed) {
                $seq .= $this->rcRefText($refSrc, $e->value->type) . '; ';
            }
            $seq .= $this->rcUnrefText($lv, $e->type) . '; ' . $lv . ' = __r; }';
            return $seq;
        }

        // 复合赋值
        if ($target instanceof IndexExpr) {
            return $this->genCompoundIndexAssign($e, $value);
        }
        $lv = $this->genLValue($target);
        $vt = $e->value->type;
        $tt = $e->type;
        switch ($e->op) {
            case TokenKind::DotEq:
                return $lv . ' = tphp_str_concat(' . $lv . ', ' . $this->toStrExpr($value, $vt) . ')';
            case TokenKind::PowEq:
                if ($tt === Type::I_INT) {
                    return $lv . ' = tphp_int_pow(' . $lv . ', ' . $value . ')';
                }
                return $lv . ' = pow((double)(' . $lv . '), (double)(' . $value . '))';
            case TokenKind::SlashEq:
            case TokenKind::PercentEq:
                // 复合除/取模与 genBinary 的除零保护对称
                if ($tt === Type::I_INT && $vt === Type::I_INT
                    && !($e->value instanceof IntLit && $e->value->value !== 0)) {
                    $msg = $e->op === TokenKind::SlashEq ? 'division by zero' : 'modulo by zero';
                    $opch = $e->op === TokenKind::SlashEq ? '/' : '%';
                    return '({ int32_t __d = (' . $value . '); if (__d == 0) { tphp_panic("' . $msg
                        . '"); } ' . $lv . ' ' . $opch . '= __d; })';
                }
                return $lv . ' ' . $this->opText($e->op) . ' ' . $value;
            default:
                return $lv . ' ' . $this->opText($e->op) . ' ' . $value;
        }
    }

    /** 下标写入调用文本（带越界检查）。 */
    private function setElemCall(IndexExpr $target, string $value): string
    {
        if ($this->table->isMap($target->base->type)) {
            return $this->mapSetCall($target, $value);
        }
        $base = $this->genExpr($target->base);
        $idx = $this->genExpr($target->index);
        $elem = $this->table->arrayElemOf($target->base->type);
        if ($elem === Type::I_INT) {
            return 'tphp_arr_set_int(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        if ($elem === Type::I_DOUBLE) {
            return 'tphp_arr_set_double(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        if ($elem === Type::I_FLOAT) {
            return 'tphp_arr_set_float(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        if ($elem === Type::I_BOOL) {
            return 'tphp_arr_set_bool(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        if ($elem === Type::I_STRING) {
            return 'tphp_arr_set_str(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        if ($this->table->isArray($elem)) {
            return 'tphp_arr_set_arr(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        if ($this->table->isClass($elem)) {
            return 'tphp_arr_set_obj(' . $base . ', ' . $idx . ', ' . $value . ')';
        }
        // c.* 标量元素
        $ctype = $this->elemCType($elem);
        return '({ ' . $ctype . ' __wv = (' . $value . '); tphp_arr_set_raw(' . $base . ', ' . $idx . ', &__wv); })';
    }

    /** 数组元素的复合赋值：get/set 对（保留越界检查）。 */
    private function genCompoundIndexAssign(AssignExpr $e, string $value): string
    {
        $target = $e->target;
        // map 下标复合赋值：读旧值 → 运算 → 写回（键缓冲复用，一次哈希查找不是必须但保正确）
        if ($this->table->isMap($target->base->type)) {
            $base = $this->genExpr($target->base);
            $idx = $this->genExpr($target->index);
            $kCode = $this->table->mapKeyOf($target->base->type);
            $kis = $this->table->mapKeyIsString($target->base->type) ? 1 : 0;
            $kC = $this->elemCType($kCode);
            $vC = $this->elemCType($this->table->mapValOf($target->base->type));
            $opText = $this->opText($e->op);
            // 通用形态：__nv = __cur <op> value（.= 与 **= 特判）
            $calc = match ($e->op) {
                TokenKind::DotEq => 'tphp_str_concat(__cur, ' . $value . ')',
                TokenKind::PowEq => ($this->table->mapValOf($target->base->type) === Type::I_INT)
                    ? 'tphp_int_pow(__cur, ' . $value . ')'
                    : 'pow((double)(__cur), (double)(' . $value . '))',
                default => '(__cur ' . $opText . ' (' . $value . '))',
            };
            $lines = [
                '({',
                $this->indentText() . '    ' . $kC . ' __mk = ' . $idx . ';',
                $this->indentText() . '    ' . $vC . ' __cur;',
                $this->indentText() . '    tphp_map_get_raw(' . $base . ', &__mk, ' . $kis . ', &__cur);',
                $this->indentText() . '    ' . $vC . ' __nv = ' . $calc . ';',
                $this->indentText() . '    tphp_map_set_raw(' . $base . ', &__mk, ' . $kis . ', &__nv);',
                $this->indentText() . '    __nv;',
                $this->indentText() . '})',
            ];
            return implode("\n", $lines);
        }
        $base = $this->genExpr($target->base);
        $idx = $this->genExpr($target->index);
        $elem = $this->table->arrayElemOf($target->base->type);
        $op = $e->op;

        if ($op === TokenKind::DotEq && $elem === Type::I_STRING) {
            $cur = 'tphp_arr_get_str(' . $base . ', ' . $idx . ')';
            $next = 'tphp_str_concat(' . $cur . ', ' . $this->toStrExpr($value, $e->value->type) . ')';
            return 'tphp_arr_set_str(' . $base . ', ' . $idx . ', ' . $next . ')';
        }

        if ($elem === Type::I_INT) {
            $get = 'tphp_arr_get_int(' . $base . ', ' . $idx . ')';
            if ($op === TokenKind::PowEq) {
                $next = 'tphp_int_pow(' . $get . ', ' . $value . ')';
            } elseif (($op === TokenKind::SlashEq || $op === TokenKind::PercentEq)
                && !($e->value instanceof IntLit && $e->value->value !== 0)) {
                // 复合除/取模零检查（下标元素）
                $msg = $op === TokenKind::SlashEq ? 'division by zero' : 'modulo by zero';
                $opch = $op === TokenKind::SlashEq ? '/' : '%';
                $next = '({ int32_t __d = (' . $value . '); if (__d == 0) { tphp_panic("' . $msg
                    . '"); } (' . $get . ') ' . $opch . ' __d; })';
            } else {
                $next = '(' . $get . ' ' . $this->opText($op) . ' (' . $value . '))';
            }
            return 'tphp_arr_set_int(' . $base . ', ' . $idx . ', ' . $next . ')';
        }

        if ($elem === Type::I_DOUBLE || $elem === Type::I_FLOAT) {
            $fn = $elem === Type::I_DOUBLE ? ['tphp_arr_get_double', 'tphp_arr_set_double'] : ['tphp_arr_get_float', 'tphp_arr_set_float'];
            $get = $fn[0] . '(' . $base . ', ' . $idx . ')';
            if ($op === TokenKind::PowEq) {
                $next = 'pow((double)(' . $get . '), (double)(' . $value . '))';
            } else {
                $next = '((' . $get . ') ' . $this->opText($op) . ' (' . $value . '))';
            }
            return $fn[1] . '(' . $base . ', ' . $idx . ', ' . $next . ')';
        }

        // c.* 标量元素
        $ctype = $this->elemCType($elem);
        $get = '({ ' . $ctype . ' __v; tphp_arr_get_raw(' . $base . ', ' . $idx . ', &__v); __v; })';
        if ($op === TokenKind::PowEq) {
            $next = '((' . $ctype . ')pow((double)(' . $get . '), (double)(' . $value . ')))';
        } else {
            $next = '((' . $get . ') ' . $this->opText($op) . ' (' . $value . '))';
        }
        return '({ ' . $ctype . ' __nv = ' . $next . '; tphp_arr_set_raw(' . $base . ', ' . $idx . ', &__nv); })';
    }

    // ------------------------------------------------------------------ 调用

    private function genCall(CallExpr $e): string
    {
        // 内置函数
        if ($e->name === 'len') {
            // 新鲜堆值实参先入临时（len 不持有，语句尾释放）
            $arg = $this->isFreshProducer($e->args[0]) && $this->isHeapType($e->args[0]->type)
                ? $this->rcHoist($e->args[0])
                : $this->genExpr($e->args[0]);
            if ($this->table->isMap($e->args[0]->type)) {
                return 'tphp_map_len(' . $arg . ')';
            }
            return $e->args[0]->type === Type::I_STRING
                ? '((' . $arg . ').length)'
                : 'tphp_len_arr(' . $arg . ')';
        }
        if ($e->name === 'array_keys') {
            return 'tphp_map_keys(' . $this->genExpr($e->args[0]) . ')';
        }
        if ($e->name === 'var_dump') {
            return $this->genDumpCall($e->args[0]);
        }
        // phpc 桥接：string → char*（借用）
        if ($e->name === 'c_str') {
            $arg = $e->args[0];
            // 变量（含引用捕获的盒子）：走 cref 取调用方对象地址——SSO 短串的数据
            // 内联在宿主结构体里，按值传参会指向已销毁的参数副本（悬垂）。
            if ($arg instanceof VarExpr) {
                return 'tphp_str_cref(&' . $this->varReadText($arg) . ')';
            }
            // 字面量：tphp_str_lit 的 u.data 指向 .rodata，按值版安全
            return 'tphp_str_c(' . $this->genExpr($arg) . ')';
        }
        // C 内存所有权：c_own 登记（函数出口自动 free）；cbuf 分配 + 登记
        if ($e->name === 'c_own') {
            return '({ void* __r = (void*)(' . $this->genExpr($e->args[0]) . '); tphp_cmem_own(__r); __r; })';
        }
        if ($e->name === 'cbuf') {
            return 'tphp_cbuf(' . $this->genExpr($e->args[0]) . ')';
        }
        // c_fn($closure)：生成 C 回调 trampoline（约定 C 回调尾参 void* userdata）
        if ($e->name === 'c_fn') {
            return $this->genCfn($e);
        }

        $fn = $this->table->fns[$e->name] ?? null;
        // 桥接函数不会失败，不包错误检查
        if ($e->name === 'php_str' || $e->name === 'php_str_ref') {
            $fn = null;
            return ($e->name === 'php_str' ? 'tphp_php_str(' : 'tphp_php_str_ref(')
                . $this->genExpr($e->args[0]) . ')';
        }
        $paramTypes = $fn !== null && !$fn->isBuiltin
            ? array_map(fn ($p) => $p->type, $fn->params)
            : null;
        $args = $this->genArgs($e->args, $paramTypes);
        return $this->fnName($e->name) . '(' . $args . ')';
    }

    private function genDumpCall(Expr $arg): string
    {
        $text = $this->genExpr($arg);
        $t = $arg->type;
        if ($t === Type::I_INT) {
            return 'tphp_dump_int(' . $text . ')';
        }
        if ($t === Type::I_DOUBLE) {
            return 'tphp_dump_double(' . $text . ')';
        }
        if ($t === Type::I_FLOAT) {
            return 'tphp_dump_float(' . $text . ')';
        }
        if ($t === Type::I_BOOL) {
            return 'tphp_dump_bool(' . $text . ')';
        }
        if ($t === Type::I_STRING) {
            return 'tphp_dump_str(' . $text . ')';
        }
        if ($this->table->isArray($t)) {
            // 数组 dump 本身不带换行（嵌套打印共用），顶层在此补换行
            return '({ ' . $this->dumpFnFor($this->table->arrayElemOf($t))
                . '(' . $text . "); putchar('\\n'); })";
        }
        if ($this->table->isClass($t)) {
            return 'printf("object(' . $this->table->className($t) . ")\\n\")";
        }
        if ($this->table->isInterface($t)) {
            return 'printf("interface\\n")';
        }
        return 'printf("callable\\n")';
    }

    /** 按需生成数组 dump 组合函数（原型进 protos，函数体排队后在 helpers 生成）。 */
    private function dumpFnFor(int $elem): string
    {
        $key = str_replace(['<', '>', '\\'], ['_', '_', '_'], $this->table->displayName($elem));
        $name = 'tphp_dump_arr_' . $key;
        if (isset($this->dumpFns[$key])) {
            return $name;
        }
        $this->dumpFns[$key] = $name;

        $saved = $this->cur;
        $this->cur = 'protos';
        $this->w('static void ' . $name . '(Array *a);');
        $this->cur = $saved;

        // 函数体延后生成：嵌套引用不能插进当前正在生成的函数体中间
        $this->pendingDumps[] = [$elem, $name];
        return $name;
    }

    /** 生成全部待生成的 dump 函数体（emitBodies 前调用）。 */
    private function drainDumps(): void
    {
        if ($this->pendingDumps === []) {
            return;
        }
        $saved = $this->cur;
        $savedIndent = $this->indent;
        $this->cur = 'helpers';
        $this->indent = 0;
        while ($this->pendingDumps !== []) {
            [$elem, $name] = array_shift($this->pendingDumps);
            $this->genDumpFnBody($elem, $name);
        }
        $this->indent = $savedIndent;
        $this->cur = $saved;
    }

    private function genDumpFnBody(int $elem, string $name): void
    {
        $this->w('static void ' . $name . '(Array *a)');
        $this->w('{');
        $this->indent = 1;
        $this->w('if (!a) ' . $this->panicCall('dump 作用于 null 数组') . ';');
        $this->w('printf("array(%d) [", a->length);');
        $this->w('for (int32_t i = 0; i < a->length; i++) {');
        $this->indent = 2;
        $this->w('if (i) fputs(", ", stdout);');
        $this->w($this->dumpElemPrint($elem) . ';');
        $this->indent = 1;
        $this->w('}');
        $this->w('printf("]");');
        $this->indent = 0;
        $this->w('}');
        $this->w('');
    }

    /** dump 数组时单个元素的打印语句（不带换行）。 */
    private function dumpElemPrint(int $elem): string
    {
        if ($elem === Type::I_INT) {
            return 'printf("%d", tphp_arr_get_int(a, i))';
        }
        if ($elem === Type::I_DOUBLE) {
            return 'printf("%.14g", tphp_arr_get_double(a, i))';
        }
        if ($elem === Type::I_FLOAT) {
            return 'printf("%g", (double)tphp_arr_get_float(a, i))';
        }
        if ($elem === Type::I_BOOL) {
            return 'fputs(tphp_arr_get_bool(a, i) ? "true" : "false", stdout)';
        }
        if ($elem === Type::I_STRING) {
            return '{ String __sv = tphp_arr_get_str(a, i); printf("\\"%.*s\\"", __sv.length, tphp_str_c(__sv)); }';
        }
        if ($this->table->isArray($elem)) {
            return $this->dumpFnFor($this->table->arrayElemOf($elem)) . '(tphp_arr_get_arr(a, i))';
        }
        if ($this->table->isClass($elem)) {
            return 'printf("object(' . Names::mangle($this->table->className($elem)) . ')")';
        }
        $ctype = $this->elemCType($elem);
        return '{ ' . $ctype . ' __ev; tphp_arr_get_raw(a, i, &__ev); printf("%lld", (long long)__ev); }';
    }

    /** @param list<Expr> $args @param list<int>|null $paramTypes 形参类型（接口形参需要 itab 包装） */
    private function genArgs(array $args, ?array $paramTypes = null): string
    {
        $texts = [];
        foreach ($args as $i => $arg) {
            // 新鲜堆值实参：先入临时（形参由被调方持有，语句尾释放临时）
            $text = $this->isFreshProducer($arg) && $this->isHeapType($arg->type)
                ? $this->rcHoist($arg)
                : $this->genExpr($arg);
            if ($paramTypes !== null && isset($paramTypes[$i]) && $this->needsIfaceWrap($arg, $paramTypes[$i])) {
                $text = $this->genIfaceWrap($arg, $text, $paramTypes[$i]);
            }
            $texts[] = $text;
        }
        return implode(', ', $texts);
    }

    private function genNew(NewExpr $e): string
    {
        return Names::newHelper($e->class) . '(' . $this->genArgs($e->args) . ')';
    }

    // ------------------------------------------------------------------ 成员访问

    /** 属性访问：cstruct 点访问 / cstruct 指针箭头访问（带空指针检查）/ 类属性。 */
    private function genPropFetch(PropFetch $e): string
    {
        $objText = $this->genExpr($e->obj);
        $ot = $e->obj->type;

        // cstruct 值：C 点访问，无空指针检查
        if ($this->table->isCStruct($ot)) {
            return $objText . '.' . Names::localVar($e->name);
        }
        // cstruct 指针：C 箭头访问 + 空指针 panic
        if ($this->table->isCPointer($ot)) {
            if ($e->obj instanceof VarExpr || $e->obj instanceof PropFetch) {
                $this->w('if (!(' . $objText . ')) ' . $this->panicCall('对空指针访问字段') . ';');
            } else {
                $objText = '({ ' . $this->cType($ot) . ' __p = ' . $objText . '; if (!__p) '
                    . $this->panicCall('对空指针访问字段') . '; __p; })';
            }
            return $objText . '->' . Names::localVar($e->name);
        }

        $this->emitNullCheck($e->obj, $objText);
        return $objText . '->' . Names::localVar($e->name);
    }

    /** 基础表达式是简单变量/this 时，提前生成空指针检查行。 */
    private function emitNullCheck(Expr $base, string $baseText): void
    {
        if ($base instanceof VarExpr || $base instanceof ThisExpr
            || $base instanceof PropFetch || $base instanceof MethodCall) {
            // or 上下文：链中间调用失败会使接收者为零值（NULL），此时不应 panic，
            // 而应让整个表达式短路、交由外层 or 块处理
            $cond = $this->inOrContext
                ? '!tphp_err_has() && !(' . $baseText . ')'
                : '!(' . $baseText . ')';
            $this->w('if (' . $cond . ') ' . $this->panicCall('对 null 对象访问成员') . ';');
        }
    }

    private function genMethodCall(MethodCall $e): string
    {
        // 接收者是新鲜堆值产生式（如链式 $c->a()->b() 的中间结果）：hoist 入临时，语句尾释放
        $objText = $this->isFreshProducer($e->obj) && $this->isHeapType($e->obj->type)
            ? $this->rcHoist($e->obj)
            : $this->genExpr($e->obj);
        $ot = $e->obj->type;

        // 接口接收者：itab 分发
        if ($this->table->isInterface($ot)) {
            $iface = $this->table->interfaceByCode($ot);
            $sig = $iface->findMethod($e->name);
            $args = $this->genArgsSuffix($e->args, $sig !== null ? array_map(fn ($p) => $p->type, $sig->params) : null);
            if ($e->obj instanceof VarExpr || $e->obj instanceof ThisExpr) {
                $this->w('if (!(' . $objText . '.obj)) ' . $this->panicCall('对 null 接口调用方法') . ';');
                $call = '((const ' . Names::itabType($iface->name) . ' *)(' . $objText . '.itab))->'
                    . $e->name . '((' . $objText . '.obj)' . $args . ')';
            } else {
                $call = '({ TphpIface __iv = ' . $objText . '; if (!__iv.obj) '
                    . $this->panicCall('对 null 接口调用方法') . '; ((const '
                    . Names::itabType($iface->name) . ' *)__iv.itab)->' . $e->name . '(__iv.obj' . $args . '); })';
            }
            return $this->wrapFailable($call, $e->type);
        }

        $this->emitNullCheck($e->obj, $objText);

        $recv = $this->table->classByCode($e->obj->type);
        $fn = $recv->findMethod($e->name);
        $owner = $fn->ownerClass;
        $args = $this->castedSelf($objText, $recv, $owner) . $this->genArgsSuffix($e->args,
            array_map(fn ($p) => $p->type, $fn->params));

        if ($this->isLeaf($recv)) {
            // 无子类：直接调用
            $call = Names::method($owner->name, $e->name) . '(' . $args . ')';
            return $this->wrapFailable($call, $e->type);
        }
        // 经 vtable 分发
        $call = 'TPHP_VT(' . $objText . ', ' . Names::vtableType($recv->name) . ')->'
            . $e->name . '(' . $args . ')';
        return $this->wrapFailable($call, $e->type);
    }

    private function castedSelf(string $objText, ClassSymbol $recv, ClassSymbol $owner): string
    {
        if ($recv->name === $owner->name) {
            return $objText;
        }
        return '(' . $this->cType($owner->code) . ')(' . $objText . ')';
    }

    private function genArgsSuffix(array $args, ?array $paramTypes = null): string
    {
        $texts = $this->genArgs($args, $paramTypes);
        return $texts !== '' ? ', ' . $texts : '';
    }

    private function isLeaf(ClassSymbol $class): bool
    {
        $key = $class->name;
        if (isset($this->leafCache[$key])) {
            return $this->leafCache[$key];
        }
        foreach ($this->table->classes as $c) {
            if ($c !== $class && $c->isSubclassOf($class)) {
                return $this->leafCache[$key] = false;
            }
        }
        return $this->leafCache[$key] = true;
    }

    /** self::/parent::/类名 → 具体类。 */
    private function resolveClass(string $name): ?ClassSymbol
    {
        if ($name === 'self') {
            return $this->curClassSym;
        }
        if ($name === 'parent') {
            return $this->curClassSym?->parent;
        }
        return $this->table->classes[$name] ?? null;
    }

    private function genStaticPropName(StaticProp $e): string
    {
        $class = $this->resolveClass($e->class);
        return Names::staticProp($class->name ?? $e->class, $e->name);
    }

    /** 类常量宏名（按声明类解析，self/parent 归属到声明处）。 */
    private function genStaticConstName(StaticConst $e): string
    {
        // 枚举 case：惰性初始化后返回单例指针（自持有，进程期存活）
        if ($e->isEnumCase) {
            $class = $this->resolveClass($e->class);
            $init = Names::method($class->name, '__enum_init');
            $ptr = 'tphp_e_' . Names::mangle($class->name) . '_' . $e->name;
            return '({ ' . $init . '(); ' . $ptr . '; })';
        }
        $recv = $this->resolveClass($e->class);
        for ($c = $recv; $c !== null; $c = $c->parent) {
            if (isset($c->consts[$e->name])) {
                return Names::classConstMacro($c->name, $e->name);
            }
        }
        return Names::classConstMacro($recv->name ?? $e->class, $e->name);
    }

    private function genStaticCall(StaticCall $e): string
    {
        $class = $this->resolveClass($e->class);
        if ($class === null) {
            return '0 /* unreachable */';
        }
        $fn = $class->findMethod($e->method);
        if ($fn === null) {
            return '0 /* unreachable */';
        }
        $owner = $fn->ownerClass;

        if ($fn->isCtor) {
            // parent::__construct(...) / self::__construct(...)
            $call = Names::method($owner->name, '__construct')
                . '((' . $this->cType($owner->code) . ')self' . $this->genArgsSuffix($e->args) . ')';
            return $this->wrapFailable($call, Type::I_VOID);
        }
        $call = Names::method($owner->name, $e->method) . '(' . $this->genArgs($e->args) . ')';
        return $this->wrapFailable($call, $fn->ret);
    }

    // ------------------------------------------------------------------ 强转

    private function genIfaceWrap(Expr $value, string $text, int $ifaceType): string
    {
        $class = $this->table->classByCode($value->type);
        $iface = $this->table->interfaceByCode($ifaceType);
        return '(TphpIface){ (void *)(' . $text . '), (const void *)&'
            . Names::itabInstance($class->name, $iface->name) . ' }';
    }

    /** 类 → 接口赋值需要包 itab 胖指针。 */
    private function needsIfaceWrap(Expr $value, int $targetType): bool
    {
        return $this->table->isInterface($targetType) && $this->table->isClass($value->type);
    }

    private function genCast(CastExpr $e): string
    {
        $src = $this->genExpr($e->expr);
        $st = $e->expr->type;
        $tt = $e->type;

        if ($tt === $st) {
            return '(' . $src . ')';
        }
        if ($tt === Type::I_STRING) {
            return $this->toStrExpr('(' . $src . ')', $st);
        }
        if ($tt === Type::I_BOOL) {
            if ($st === Type::I_STRING) {
                return '((' . $src . ').length != 0)';
            }
            return '((' . $src . ') != 0)';
        }
        if ($tt === Type::I_ARRAY) {
            return 'NULL';
        }
        if ($this->table->isCallable($tt)) {
            return '(Callable){0}';
        }
        if ($this->table->isCPointer($tt)) {
            // c.ptr / CVAL → 指针
            return '((' . $this->cType($tt) . ')(' . $src . '))';
        }
        if ($this->table->isIntLike($tt) || $this->table->isFloatLike($tt)) {
            $ctype = $this->cType($tt);
            if ($st === Type::I_STRING) {
                // 字符串 → 数值：整型走 strtol，浮点走 strtod
                $conv = $this->table->isIntLike($tt) ? 'tphp_str_to_int' : 'tphp_str_to_double';
                return '((' . $ctype . ')' . $conv . '(' . $src . '))';
            }
            return '((' . $ctype . ')(' . $src . '))';
        }
        // C 指针 / cstruct 强转（来自 c.ptr / CVAL / 其他指针）
        if ($this->table->isCPointer($tt) || $this->table->isCStruct($tt)) {
            return '((' . $this->cType($tt) . ')(' . $src . '))';
        }
        // CVAL 落入强转之外的场景（不可达：Checker 保证）
        return '(' . $src . ')';
    }
}
