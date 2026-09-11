<?php

declare(strict_types=1);

namespace Tphp\Ast;

/**
 * 单个源文件的解析结果：命名空间 + 顶层声明列表 + phpc 指令 + 包/能力声明。
 *
 * @param list<string> $includes #include 头文件（原文含 <> 或 ""）
 * @param list<string> $cflags #flag 编译参数
 * @param list<string> $imports #import 参数（裸包名，或以 . 开头的项目内相对路径）
 * @param list<string> $phpModules #php 模块名（PHP 原生能力，需 libphp）
 */
final class File extends Node
{
    /**
     * @param list<FunctionDecl|ClassDecl|InterfaceDecl|ConstDecl|decl\CStructDecl> $decls
     */
    public function __construct(
        public readonly string $path,
        public readonly array $decls,
        public readonly string $namespace = '',
        public readonly array $includes = [],
        public readonly array $cflags = [],
        public readonly array $imports = [],
        public readonly array $phpModules = [],
    ) {
        parent::__construct();
    }
}
