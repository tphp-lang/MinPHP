<?php

declare(strict_types=1);

namespace Tphp\Ast\decl;

use Tphp\Ast\Node;

/** 类声明。$extends 为父类名（单继承）；$implements 为接口名列表；$classConsts 为类常量。 */
final class ClassDecl extends Node
{
    /** @param list<ClassProp> $props @param list<ClassMethod> $methods @param list<ClassConstDecl> $classConsts @param list<string> $implements */
    public function __construct(
        public readonly string $name,
        public readonly ?string $extends,
        public readonly array $props,
        public readonly array $methods,
        public readonly array $classConsts = [],
        public readonly array $implements = [],
        public readonly bool $isFinal = false,
        public readonly bool $isAbstract = false,
        /** @var list<UseTraitDecl> */
        public readonly array $useTraits = [],
    ) {}
}
