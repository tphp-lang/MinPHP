<?php

declare(strict_types=1);

namespace Tphp\Ast\decl;

use Tphp\Ast\Node;

/**
 * 类体内的 use 语句：use A, B; 或带冲突解决的
 * use A, B { A::m insteadof B; B::m as alias; B::m as private; }
 */
final class UseTraitDecl extends Node
{
    /**
     * @param list<string> $traits 使用的 trait 名（已解析为 FQ）
     * @param list<array{trait: string, method: string, instead: string}> $insteadofs
     * @param list<array{trait: string, method: string, alias: ?string, vis: ?string}> $aliases
     */
    public function __construct(
        public readonly array $traits,
        public readonly array $insteadofs = [],
        public readonly array $aliases = [],
    ) {}
}
