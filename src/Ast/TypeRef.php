<?php

declare(strict_types=1);

namespace Tphp\Ast;

use Tphp\Token\Pos;

/**
 * 语法层类型引用：'int' / 'array<...>' / 'map<K,V>' / 'c.i8' / 'c.char*' / 类名 / cstruct 名。
 * Parser 只记录名字结构，解析成 Type 编码由 Checker 完成。
 */
final class TypeRef extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?self $elem = null,
        public readonly bool $pointer = false,
        public readonly ?self $key = null, // map<K,V> 的 K（elem = V）
    ) {
        $this->pos = null;
    }

    public function withPos(Pos $pos): self
    {
        $this->pos = $pos;
        if ($this->elem !== null) {
            $this->elem->pos = $pos;
        }
        if ($this->key !== null) {
            $this->key->pos = $pos;
        }
        return $this;
    }
}
