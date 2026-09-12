<?php

declare(strict_types=1);

namespace Tphp\Table;

use Tphp\Ast\Expr;
use Tphp\Token\Pos;
/**
 * 变量符号：局部变量 / 参数 / 类属性共用。
 *
 * vis / isStatic / default 只对类属性有意义。
 */
final class VarSymbol
{
    public ?ClassSymbol $owner = null;

    /** callable 变量的闭包签名（Checker 从闭包字面量回填）：[ret, list<paramType>] */
    public ?array $closureSig = null;

    /** 变量承接的闭包 FnSymbol（Checker 内部：调用点回填 callable 形参签名的入口） */
    public ?FnSymbol $closureFn = null;

    /** 被 use (&$var) 引用捕获：存储提升为堆盒子（doc/closure.md §3.5） */
    public bool $boxed = false;

    /** 闭包捕获进入的变量（非本作用域声明，禁止再被引用捕获） */
    public bool $isCapture = false;

    public function __construct(
        public readonly string $name,
        public int $type = 0,
        public readonly ?Pos $pos = null,
        public string $vis = 'public',
        public bool $isStatic = false,
        public bool $hasDefault = false,
        public ?Expr $default = null,
    ) {}
}
