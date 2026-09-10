<?php

declare(strict_types=1);

namespace Tphp\Table;

use Tphp\Token\Pos;

/**
 * 类符号。编译期单态化为 C struct（tphp_class_<Name>）。
 *
 * vtableOrder 是虚方法名列表：先父类方法（保持父类 vtable 布局前缀），
 * 再本类新增方法——保证父类 vtable 指针可以安全地按前缀布局复用。
 */
final class ClassSymbol
{
    /** @var array<string, VarSymbol> 属性名 → 符号 */
    public array $props = [];

    /** @var array<string, ConstSymbol> 类常量名 → 符号 */
    public array $consts = [];

    /** @var list<InterfaceSymbol> 直接实现的接口（不含继承来的） */
    public array $implements = [];

    /** @var array<string, FnSymbol> 方法名 → 符号（含继承来的） */
    public array $methods = [];

    /** @var list<string> vtable 中的方法名顺序 */
    public array $vtableOrder = [];

    /** 枚举类：case 单例集（对象恒等、->name/->value 只读、from/tryFrom/cases 合成方法） */
    public bool $isEnum = false;

    /** 枚举 backing 类型码（I_INT / I_STRING）或 null（纯枚举） */
    public ?int $enumBacking = null;

    /** final 类：不可被继承 */
    public bool $isFinal = false;

    /** abstract 类：不可实例化，可含抽象方法 */
    public bool $isAbstract = false;

    /** trait 符号：参与 use 展开，不生成结构体/new/vtable，不可实例化与实例判定 */
    public bool $isTrait = false;

    /** trait 嵌套 use 展开防重入标记 */
    public bool $traitsExpanded = false;

    /** @var list<array{name: string, value: ?\Tphp\Ast\Expr}> 枚举 case */
    public array $enumCases = [];

    public function findEnumCase(string $name): ?array
    {
        foreach ($this->enumCases as $c) {
            if ($c['name'] === $name) {
                return $c;
            }
        }
        return null;
    }

    public function __construct(
        public readonly string $name,
        public readonly int $code,
        public ?ClassSymbol $parent = null,
        public readonly ?Pos $pos = null,
    ) {}

    public function isSubclassOf(self $other): bool
    {
        for ($c = $this; $c !== null; $c = $c->parent) {
            if ($c === $other) {
                return true;
            }
        }
        return false;
    }

    /** 查找属性（沿继承链向上）。 */
    public function findProp(string $name): ?VarSymbol
    {
        for ($c = $this; $c !== null; $c = $c->parent) {
            if (isset($c->props[$name])) {
                return $c->props[$name];
            }
        }
        return null;
    }

    /** 查找方法（沿继承链向上）。 */
    public function findMethod(string $name): ?FnSymbol
    {
        for ($c = $this; $c !== null; $c = $c->parent) {
            if (isset($c->methods[$name])) {
                return $c->methods[$name];
            }
        }
        return null;
    }

    /** 查找类常量（沿继承链向上）。 */
    public function findConst(string $name): ?ConstSymbol
    {
        for ($c = $this; $c !== null; $c = $c->parent) {
            if (isset($c->consts[$name])) {
                return $c->consts[$name];
            }
        }
        return null;
    }
}
