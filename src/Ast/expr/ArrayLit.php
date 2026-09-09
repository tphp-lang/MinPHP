<?php

declare(strict_types=1);

namespace Tphp\Ast\expr;

use Tphp\Ast\Expr;

/**
 * 数组字面量 [a, b, c]（纯列表）与 map 字面量 [k => v, ...]（全键形式）。
 * keys 与 items 平行等长；keys[i] = null 表示该元素无键（纯列表形式）。
 */
final class ArrayLit extends Expr
{
    /** @param list<Expr> $items @param list<?Expr> $keys */
    public function __construct(
        public readonly array $items,
        public readonly array $keys = [],
    ) {
        parent::__construct();
    }
}
