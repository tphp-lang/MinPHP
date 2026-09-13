<?php

declare(strict_types=1);

namespace Tphp\Ast\expr;

use Tphp\Ast\Expr;

/** 变量引用（$name）。sym 由 Checker 回填（盒子变量的读写文本生成用）。 */
final class VarExpr extends Expr
{
    public ?object $sym = null;

    /** 该引用对应的 C 变量是接口胖指针，但静态类型已收窄为具体类：Gen 读取时需 .obj 解包 */
    public bool $ifaceUnwrap = false;

    /** 该引用对应的 C 变量的实际存储类型比节点类型更宽（类/object 收窄为子类）：Gen 读取时需零成本转型 */
    public bool $narrowCast = false;

    public function __construct(public readonly string $name)
    {
        parent::__construct();
    }
}
