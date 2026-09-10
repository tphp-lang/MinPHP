<?php

declare(strict_types=1);

namespace Tphp\Ast\expr;

use Tphp\Ast\Expr;

/**
 * instanceof：$x instanceof ClassName（右侧为已解析的类/接口全限定名）。
 * 动态类名（$x instanceof $var）不支持——AOT 无运行期类型表查找。
 */
final class InstanceOfExpr extends Expr
{
    /** 编译期折叠结果：true/false 时为常量，null 为需运行时判定（Checker 标注）。 */
    public ?bool $folded = null;

    public function __construct(
        public readonly Expr $obj,
        public readonly string $class,
    ) {
        parent::__construct();
    }
}
