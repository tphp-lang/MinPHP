<?php

declare(strict_types=1);

namespace Tphp\Table;

/**
 * 词法作用域：父链式查找，同层禁止重复声明。
 * 仅供 Checker 构建并校验类型；Gen 不复用此树——其 C 局部变量声明由 AST 节点驱动，
 * 引用计数的存活/清理另用 Gen 自身的 rcScopes（见 src/Gen/GenRcTrait.php）。
 */
final class Scope
{
    /** @var array<string, VarSymbol> */
    public array $vars = [];

    /** @var array<string, ConstSymbol> 函数内常量 */
    public array $consts = [];

    public function __construct(
        public readonly ?Scope $parent = null,
        /** 所在函数/方法，用于 return 校验 */
        public readonly ?FnSymbol $fn = null,
    ) {}

    public function find(string $name): ?VarSymbol
    {
        for ($s = $this; $s !== null; $s = $s->parent) {
            if (isset($s->vars[$name])) {
                return $s->vars[$name];
            }
        }
        return null;
    }

    public function findLocal(string $name): ?VarSymbol
    {
        return $this->vars[$name] ?? null;
    }

    /** 查找函数内常量（沿作用域链向上）。 */
    public function findConst(string $name): ?ConstSymbol
    {
        for ($s = $this; $s !== null; $s = $s->parent) {
            if (isset($s->consts[$name])) {
                return $s->consts[$name];
            }
        }
        return null;
    }
}
