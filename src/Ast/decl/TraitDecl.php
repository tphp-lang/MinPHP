<?php

declare(strict_types=1);

namespace Tphp\Ast\decl;

use Tphp\Ast\Node;

/**
 * trait 声明：编译期展开为使用类的方法/属性（PHP 语义同构，零运行时痕迹）。
 * $useTraits 为 trait 自身的嵌套 use；$methods 中 isAbstract 者要求使用类实现。
 */
final class TraitDecl extends Node
{
    /** @param list<ClassProp> $props @param list<ClassMethod> $methods @param list<ClassConstDecl> $classConsts @param list<UseTraitDecl> $useTraits */
    public function __construct(
        public readonly string $name,
        public readonly array $props,
        public readonly array $methods,
        public readonly array $classConsts = [],
        public readonly array $useTraits = [],
    ) {}
}
