<?php

declare(strict_types=1);

namespace Tphp\Checker;

use Tphp\Ast\Expr;
use Tphp\Ast\expr\FloatLit;
use Tphp\Ast\File;
use Tphp\Ast\expr\PropFetch;
use Tphp\Ast\expr\TernaryExpr;
use Tphp\Ast\expr\ThisExpr;
use Tphp\Ast\expr\VarExpr;
use Tphp\Ast\TypeRef;
use Tphp\Errors\Errors;
use Tphp\Table\ClassSymbol;
use Tphp\Table\FnSymbol;
use Tphp\Table\InterfaceSymbol;
use Tphp\Table\Scope;
use Tphp\Table\Table;
use Tphp\Token\Pos;
use Tphp\Type\Type;

/**
 * 语义检查与类型标注。
 *
 * 两遍式：第一遍收集全部顶层符号（类/方法/函数），第二遍检查函数体，
 * 把类型编码回填到每个表达式节点。Gen 只消费标注结果，不再推断。
 */
final class Checker
{
    use CheckDeclTrait;
    use CheckStmtTrait;
    use CheckExprTrait;

    private ?ClassSymbol $curClass = null;
    private ?FnSymbol $curFn = null;
    private Scope $scope;

    private int $loopDepth = 0;
    private int $switchDepth = 0;

    /** @var array<string, true> 当前函数体内全部 use (&$var) 捕获名（预扫描，盒子声明判定用） */
    private array $boxedNames = [];

    /** 闭包嵌套深度：>0 表示正在检查闭包体（引用捕获禁止）。 */
    private int $closureDepth = 0;

    /** @var list<array{auto: bool, scope: Scope, node: object, containerFn: ?FnSymbol}> 闭包体检查上下文栈 */
    private array $closureCtx = [];

    /** 闭包签名预流动遍：只传播签名数据（错误丢弃），正式检查在其后。 */
    public bool $sigOnly = false;

    public function __construct(
        private readonly Table $table,
        private readonly Errors $errors,
    ) {
        $this->scope = new Scope();
    }

    /** @param list<File> $files */
    public function check(array $files, string $entryPath, bool $noMain = false): void
    {
        $this->collectCStructs($files);
        $this->collectClasses($files);
        $this->collectInterfaces($files);
        $this->collectEnums($files);
        $this->collectConsts($files);
        $this->collectMembers($files);
        $this->collectFunctions($files);
        if (!$noMain) {
            // 库模式（--no-main / --shared）不生成 main()，不要求入口
            $this->validateEntry($entryPath);
        }
        $this->checkBodies($files);
        // 调用图环检测：收敛深度保护标记（Gen 据此决定 enter/leave 插桩）
        $this->table->markDepthGuards();
    }

    /** @param list<File> $files */
    private function collectCStructs(array $files): void
    {
        foreach ($files as $file) {
            foreach ($file->decls as $decl) {
                if (!$decl instanceof \Tphp\Ast\decl\CStructDecl) {
                    continue;
                }
                if (isset($this->table->cstructs[$decl->name])) {
                    $this->error("#struct '{$decl->name}' 重复定义", $decl->pos);
                    continue;
                }
                $sym = new \Tphp\Table\CStructSymbol($decl->name, $this->table->allocClassCode(), $decl->pos);
                $this->table->addCStruct($sym);
                $this->registerCSymbol($decl->name, $decl->name, $decl->pos);
            }
        }

        // 第二遍：解析字段类型（可能引用其他 cstruct）
        foreach ($files as $file) {
            foreach ($file->decls as $decl) {
                if (!$decl instanceof \Tphp\Ast\decl\CStructDecl) {
                    continue;
                }
                $sym = $this->table->cstructs[$decl->name];
                foreach ($decl->fields as $field) {
                    $ft = $this->resolveTypeRef($field['type']);
                    if (!$this->table->isCStruct($ft) && !$this->table->isIntLike($ft)
                        && !$this->table->isFloatLike($ft) && !$this->table->isCPointer($ft)
                        && $ft !== Type::I_BOOL) {
                        $this->error(
                            "#struct 字段类型必须是 c.* 标量 / cstruct / 指针（得到 "
                            . $this->table->displayName($ft) . '）',
                            $field['type']->pos,
                        );
                    }
                    $sym->resolvedFields[] = ['name' => $field['name'], 'type' => $ft];
                }
            }
        }
    }

    // ------------------------------------------------------------------ 辅助

    private function error(string $msg, ?Pos $pos): void
    {
        if ($this->sigOnly) {
            return; // 签名预流动遍：诊断丢弃，正式检查会重现
        }
        $this->errors->add($msg, $pos ?? new Pos('<unknown>', 1, 1));
    }

    /** 解析类型引用为类型码。 */
    private function resolveTypeRef(TypeRef $ref): int
    {
        // self 返回类型：方法声明的返回类型 = 声明类（: self 链式返回）
        if ($ref->name === 'self') {
            if ($this->curClass === null) {
                $this->error('self 类型只能在类方法声明中使用', $ref->pos);
                return Type::NONE;
            }
            return $this->curClass->code;
        }
        if ($ref->name === 'array') {
            if ($ref->elem === null) {
                return Type::I_ARRAY;
            }
            $elem = $this->resolveTypeRef($ref->elem);
            if ($elem === Type::NONE || $elem === Type::I_VOID) {
                $this->error('array<T> 的元素类型无效', $ref->pos);
                return Type::NONE;
            }
            if ($this->table->isMap($elem)) {
                $this->error('数组元素暂不支持 map（后续版本开放）', $ref->elem->pos);
                return Type::NONE;
            }
            return $this->table->arrayOf($elem);
        }
        if ($ref->name === 'map') {
            if ($ref->key === null || $ref->elem === null) {
                $this->error('map<K,V> 必须指定键与值类型', $ref->pos);
                return Type::NONE;
            }
            $k = $this->resolveTypeRef($ref->key);
            $v = $this->resolveTypeRef($ref->elem);
            if (!$this->table->isString($k) && !$this->table->isIntLike($k)) {
                $this->error('map 的键类型只支持 int 与 string', $ref->key->pos);
                return Type::NONE;
            }
            if ($v === Type::NONE || $v === Type::I_VOID) {
                $this->error('map<K,V> 的值类型无效', $ref->pos);
                return Type::NONE;
            }
            return $this->table->mapOf($k, $v);
        }
        // C 指针类型：T*（c.char* / CStruct*）
        if ($ref->pointer) {
            $baseCode = $this->table->findNamed($ref->name)
                ?? ($this->table->cstructs[$ref->name]->code ?? null);
            if ($baseCode !== null || str_contains($ref->name, '.')) {
                return $this->table->pointerOf($ref->name, $baseCode);
            }
            $this->error("未知指针类型 '{$ref->name}*'", $ref->pos);
            return Type::NONE;
        }
        $code = $this->table->findNamed($ref->name);
        if ($code !== null) {
            return $code;
        }
        $class = $this->table->classes[$ref->name] ?? null;
        if ($class !== null) {
            return $class->code;
        }
        $cstruct = $this->table->cstructs[$ref->name] ?? null;
        if ($cstruct !== null) {
            return $cstruct->code;
        }
        $iface = $this->table->ifaces[$ref->name] ?? null;
        if ($iface !== null) {
            return $iface->code;
        }
        if ($ref->name !== '<error>') {
            $this->error("未知类型 '{$ref->name}'", $ref->pos);
        }
        return Type::NONE;
    }

    /** 赋值/传参/返回的兼容性判定。 */
    private function assignable(int $dst, int $src): bool
    {
        if ($dst === $src) {
            return true;
        }
        if ($src === Type::I_NULL) {
            return $this->table->isRefType($dst);
        }
        // 空数组字面量可赋给任何 array<T>
        if ($src === Type::I_ARRAY && $this->table->isArray($dst)) {
            return true;
        }
        // 空数组字面量可赋给任何 map<K,V>（借目标类型，Gen 生成 map_new）
        if ($src === Type::I_ARRAY && $this->table->isMap($dst)) {
            return true;
        }
        if ($this->table->isIntLike($dst) && $this->table->isIntLike($src)) {
            return $src === Type::I_INT; // int 可宽化到任何整型别名；别名之间需显式强转
        }
        if ($this->table->isFloatLike($dst) && $this->table->isIntLike($src)) {
            return true; // 整数 → 浮点宽化
        }
        if ($dst === Type::I_DOUBLE && $src === Type::I_FLOAT) {
            return true;
        }
        // c.f32 → float(f64) 是宽化；反方向收窄会损失精度，仅字面量豁免（见 assignableExpr）
        if ($this->table->isClass($dst) && $this->table->isClass($src)) {
            $sc = $this->table->classByCode($src);
            return $sc !== null && $sc->isSubclassOf($this->table->classByCode($dst));
        }
        // 类 → 其实现的接口；接口 → 其父接口
        if ($this->table->isInterface($dst)) {
            $di = $this->table->interfaceByCode($dst);
            if ($this->table->isClass($src)) {
                $sc = $this->table->classByCode($src);
                return $sc !== null && $di !== null && $this->classImplements($sc, $di);
            }
            if ($this->table->isInterface($src)) {
                $si = $this->table->interfaceByCode($src);
                return $si !== null && $di !== null && $si->isSubinterfaceOf($di);
            }
        }
        // CVAL（c-> 返回值）：可赋给 C 侧类型与数值/bool；不可赋 string/array/类
        if ($src === Type::I_CVAL) {
            return $dst !== Type::I_STRING && !$this->table->isArray($dst)
                && !$this->table->isClass($dst) && !$this->table->isInterface($dst)
                && !$this->table->isCallable($dst);
        }
        if ($dst === Type::I_CVAL) {
            return false; // CVAL 不落变量（C 类型必须显式声明）
        }
        return false;
    }

    /** 表达式侧的兼容性判定：float → c.f32 收窄仅浮点字面量豁免（编译期取值，无计算误差传播）。 */
    private function assignableExpr(int $dst, Expr $src): bool
    {
        if ($this->assignable($dst, $src->type)) {
            return true;
        }
        return $dst === Type::I_FLOAT && $src->type === Type::I_DOUBLE && $this->isFloatLiteral($src);
    }

    /** 浮点字面量（含两个分支均为浮点字面量的三元表达式）。 */
    private function isFloatLiteral(Expr $e): bool
    {
        if ($e instanceof FloatLit) {
            return true;
        }
        return $e instanceof TernaryExpr && $e->then instanceof FloatLit && $e->else instanceof FloatLit;
    }

    /** float → c.f32 收窄被拒绝时的提示。 */
    private function narrowHint(int $dst, int $src): string
    {
        return $dst === Type::I_FLOAT && $src === Type::I_DOUBLE
            ? '（float → c.f32 会损失精度，需显式 (c.f32) 强转）'
            : '';
    }

    /** 直接自引用（$o->f = $o / $this->f = $this）必然形成引用循环，给出告警。 */
    private function warnSelfCycle(Expr $target, Expr $value): void
    {
        if (!$target instanceof PropFetch) {
            return;
        }
        $obj = $target->obj;
        if ($obj instanceof VarExpr && $value instanceof VarExpr && $obj->name === $value->name) {
            $this->errors->warn(
                "字段赋值形成自引用循环（\${$value->name}->{$target->name} = \${$value->name}）："
                . '引用计数无法回收循环引用，对象将泄漏（见 doc/memory.md）',
                $value->pos,
            );
        } elseif ($obj instanceof ThisExpr && $value instanceof ThisExpr) {
            $this->errors->warn(
                "字段赋值形成自引用循环（\$this->{$target->name} = \$this）："
                . '引用计数无法回收循环引用，对象将泄漏（见 doc/memory.md）',
                $value->pos,
            );
        }
    }

    /** 类沿继承链是否实现某接口。 */
    private function classImplements(ClassSymbol $class, InterfaceSymbol $iface): bool
    {
        foreach ($this->requiredInterfaces($class) as $name => $required) {
            if ($name === $iface->name) {
                return true;
            }
        }
        return false;
    }

    /** == != 的可比较性。 */
    private function comparable(int $a, int $b): bool
    {
        if ($this->table->isNumeric($a) && $this->table->isNumeric($b)) {
            return true;
        }
        if ($a === $b) {
            return true;
        }
        if ($a === Type::I_NULL || $b === Type::I_NULL) {
            $other = $a === Type::I_NULL ? $b : $a;
            if ($this->table->isRefType($other)) {
                return true;
            }
            // 引用类型之外（如 C 指针）：继续向下判断
        }
        if ($this->table->isClass($a) && $this->table->isClass($b)) {
            $ca = $this->table->classByCode($a);
            $cb = $this->table->classByCode($b);
            return $ca !== null && $cb !== null
                && ($ca->isSubclassOf($cb) || $cb->isSubclassOf($ca));
        }
        if ($this->table->isInterface($a) && $this->table->isInterface($b)) {
            if ($a === $b) {
                return true;
            }
            // 子接口/父接口变量可比较：同一对象可经两种接口类型持有（生成代码按 .obj 指针比较）
            $ia = $this->table->interfaceByCode($a);
            $ib = $this->table->interfaceByCode($b);
            return ($ib !== null && $ib->isSubinterfaceOf($ia))
                || ($ia !== null && $ia->isSubinterfaceOf($ib));
        }
        // CVAL 与数值/CVAL 可比；C 指针（c.ptr / T*）与 null 互比
        $cptr = $this->table->findNamed('c.ptr');
        if ($a === Type::I_CVAL || $b === Type::I_CVAL) {
            return $this->table->isNumeric($a) || $this->table->isNumeric($b)
                || $a === Type::I_CVAL || $b === Type::I_CVAL;
        }
        if ($this->table->isCPointer($a) || $this->table->isCPointer($b)) {
            return $a === Type::I_NULL || $b === Type::I_NULL
                || ($this->table->isCPointer($a) && $this->table->isCPointer($b));
        }
        if ($cptr !== null && ($a === $cptr || $b === $cptr)) {
            return $a === Type::I_NULL || $b === Type::I_NULL
                || $a === Type::I_CVAL || $b === Type::I_CVAL
                || $this->table->isCPointer($a) || $this->table->isCPointer($b);
        }
        return false;
    }

    /** < > <= >= 的可比较性（比 == 更严格）。 */
    private function orderable(int $a, int $b): bool
    {
        if ($a === Type::I_CVAL || $b === Type::I_CVAL) {
            return true; // CVAL 参与关系比较：信任程序员
        }
        if ($this->table->isNumeric($a) && $this->table->isNumeric($b)) {
            return true;
        }
        return $a === Type::I_STRING && $b === Type::I_STRING;
    }

    /** 数值运算的结果类型；不可组合返回 NONE。 */
    private function numericPromote(int $a, int $b): int
    {
        if ($a === $b) {
            return $a;
        }
        // CVAL 混入运算：结果仍为 CVAL（生成 C 原样）
        if ($a === Type::I_CVAL || $b === Type::I_CVAL) {
            return Type::I_CVAL;
        }
        $intA = $this->table->isIntLike($a);
        $intB = $this->table->isIntLike($b);
        if ($intA && $intB) {
            if ($a === Type::I_INT) {
                return $b;
            }
            if ($b === Type::I_INT) {
                return $a;
            }
            return Type::NONE; // 两个不同的 c.* 整型需显式强转
        }
        if ($intA !== $intB) {
            $floatSide = $intA ? $b : $a;
            return $this->floatResultOf($floatSide);
        }
        if ($this->table->isFloatLike($a) && $this->table->isFloatLike($b)) {
            if ($a === Type::I_DOUBLE || $b === Type::I_DOUBLE) {
                return Type::I_DOUBLE;
            }
            if ($a === Type::I_FLOAT && $b === Type::I_FLOAT) {
                return Type::I_FLOAT;
            }
            return $this->floatResultOf($a); // 混入 c.* 浮点按 f64 处理
        }
        return Type::NONE;
    }

    private function floatResultOf(int $t): int
    {
        if ($t === Type::I_FLOAT) {
            return Type::I_FLOAT;
        }
        return Type::I_DOUBLE; // c.* 浮点与 float(f64) 混算统一提升为 f64
    }

    /** 三元/数组元素统合的公共类型。 */
    private function commonType(int $a, int $b): int
    {
        if ($a === $b) {
            return $a;
        }
        if ($this->table->isNumeric($a) && $this->table->isNumeric($b)) {
            return $this->numericPromote($a, $b);
        }
        if ($a === Type::I_NULL || $b === Type::I_NULL) {
            $other = $a === Type::I_NULL ? $b : $a;
            return $this->table->isRefType($other) ? $other : Type::NONE;
        }
        if ($this->table->isClass($a) && $this->table->isClass($b)) {
            $ca = $this->table->classByCode($a);
            $cb = $this->table->classByCode($b);
            if ($ca !== null && $cb !== null && $cb->isSubclassOf($ca)) {
                return $a;
            }
            if ($ca !== null && $cb !== null && $ca->isSubclassOf($cb)) {
                return $b;
            }
        }
        // 类与接口统合为接口；子接口统合为父接口
        if ($this->table->isInterface($a) || $this->table->isInterface($b)) {
            if ($this->table->isClass($a) && $this->table->isInterface($b)
                && $this->classImplements($this->table->classByCode($a), $this->table->interfaceByCode($b))) {
                return $b;
            }
            if ($this->table->isInterface($a) && $this->table->isClass($b)
                && $this->classImplements($this->table->classByCode($b), $this->table->interfaceByCode($a))) {
                return $a;
            }
            if ($this->table->isInterface($a) && $this->table->isInterface($b)) {
                $ia = $this->table->interfaceByCode($a);
                $ib = $this->table->interfaceByCode($b);
                if ($ib !== null && $ib->isSubinterfaceOf($ia)) {
                    return $a;
                }
                if ($ia !== null && $ia->isSubinterfaceOf($ib)) {
                    return $b;
                }
            }
        }
        return Type::NONE;
    }

    /** 显式强转允许矩阵。 */
    private function allowedCast(int $dst, int $src): bool
    {
        if ($dst === $src) {
            return true;
        }
        // CVAL → 任何 C 侧类型 / 数值 / bool / string（string 走 php_str 转换路径）
        if ($src === Type::I_CVAL) {
            return true;
        }
        // 任何 C 侧指针 / c.ptr / CVAL → CPointer（unsafe 显式转型）
        if ($this->table->isCPointer($dst)) {
            return $this->table->isCPointer($src) || $this->table->isCStruct($src)
                || $src === Type::I_CVAL;
        }
        if ($this->table->isCPointer($src)) {
            return false; // 指针 → 标量不允许
        }
        if ($this->table->isIntLike($dst)) {
            return $this->table->isNumeric($src) || $src === Type::I_BOOL || $src === Type::I_STRING;
        }
        if ($this->table->isFloatLike($dst)) {
            return $this->table->isNumeric($src) || $src === Type::I_BOOL || $src === Type::I_STRING;
        }
        if ($dst === Type::I_STRING) {
            return $this->table->isScalar($src);
        }
        if ($dst === Type::I_BOOL) {
            return $this->table->isScalar($src);
        }
        if ($dst === Type::I_ARRAY || $dst === Type::I_CALLABLE) {
            return $src === Type::I_NULL;
        }
        return false;
    }

    private function canAccess(ClassSymbol $owner, string $vis): bool
    {
        return match ($vis) {
            'public' => true,
            'private' => $this->curClass === $owner,
            default => $this->curClass !== null
                && ($this->curClass === $owner || $this->curClass->isSubclassOf($owner)),
        };
    }
}
