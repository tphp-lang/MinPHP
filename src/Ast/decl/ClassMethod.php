<?php

declare(strict_types=1);

namespace Tphp\Ast\decl;

use Tphp\Ast\Node;

use Tphp\Ast\TypeRef;

/** 类方法（含 __construct）。isAbstract 时 $body 为空（声明以 ';' 结束）。 */
final class ClassMethod extends Node
{
    /** @param list<Param> $params @param list<Stmt> $body */
    public function __construct(
        public readonly string $vis,
        public readonly bool $isStatic,
        public readonly string $name,
        public readonly array $params,
        public readonly ?TypeRef $ret,
        public readonly array $body,
        public readonly bool $isAbstract = false,
        public readonly bool $isFinal = false,
    ) {}
}
