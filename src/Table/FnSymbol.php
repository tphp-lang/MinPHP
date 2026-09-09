<?php

declare(strict_types=1);

namespace Tphp\Table;

use Tphp\Token\Pos;

/**
 * 函数/方法符号。
 *
 * 全局函数与类方法共用一个符号；isMethod 为 true 时 ownerClass 指向所属类。
 * 内置函数（len/dump）用 isBuiltin 标记，签名由 Checker 按调用点校验。
 */
final class FnSymbol
{
    /** @var list<ParamSymbol> */
    public array $params = [];

    /** #[export("c_name")] 注解的 C 符号名（仅全局函数；null = 默认 tphp_<name>）。 */
    public ?string $exportName = null;

    /** 返回类型为 callable 且 return 闭包字面量时记录其签名（sig 预流动遍回填）。 */
    public ?array $retClosureSig = null;

    public function __construct(
        public readonly string $name,
        public readonly ?Pos $pos = null,
        public bool $isMethod = false,
        public ?ClassSymbol $ownerClass = null,
        public bool $isStatic = false,
        public bool $isCtor = false,
        public string $vis = 'public',
        public int $ret = 0,
        public bool $isBuiltin = false,
        public bool $isDefined = true,
        public bool $isDtor = false, // __destruct 用户析构（对象引用归零时先于字段释放调用）
        public bool $needsDepthGuard = true, // 在调用环上（或未分析）→ 插深度保护；Checker 环检测后收敛
        public bool $forceDepthGuard = false, // 经闭包/C 回调等不可静态分析的调用 → 强制保护
    ) {}
}
