<?php

declare(strict_types=1);

namespace Tphp\Checker;

use Tphp\Ast\decl\ClassDecl;
use Tphp\Ast\decl\ConstDecl;
use Tphp\Ast\decl\EnumDecl;
use Tphp\Ast\decl\FunctionDecl;
use Tphp\Ast\decl\InterfaceDecl;
use Tphp\Ast\Expr;
use Tphp\Ast\File;
use Tphp\Ast\expr\ArrayLit;
use Tphp\Ast\expr\AssignExpr;
use Tphp\Ast\expr\BinaryExpr;
use Tphp\Ast\expr\BoolLit;
use Tphp\Ast\expr\CallExpr;
use Tphp\Ast\expr\CastExpr;
use Tphp\Ast\expr\CCallExpr;
use Tphp\Ast\expr\ClosureExpr;
use Tphp\Ast\expr\FloatLit;
use Tphp\Ast\expr\IndexExpr;
use Tphp\Ast\expr\InterpStr;
use Tphp\Ast\expr\InvokeExpr;
use Tphp\Ast\expr\IntLit;
use Tphp\Ast\expr\MethodCall;
use Tphp\Ast\expr\NewExpr;
use Tphp\Ast\expr\NullLit;
use Tphp\Ast\expr\OrExpr;
use Tphp\Ast\expr\PropFetch;
use Tphp\Ast\expr\StaticCall;
use Tphp\Ast\expr\StrLit;
use Tphp\Ast\expr\TernaryExpr;
use Tphp\Ast\expr\UnaryExpr;
use Tphp\Ast\stmt\BlockStmt;
use Tphp\Ast\stmt\DoWhileStmt;
use Tphp\Ast\stmt\EchoStmt;
use Tphp\Ast\stmt\ExprStmt;
use Tphp\Ast\stmt\ForeachStmt;
use Tphp\Ast\stmt\ForStmt;
use Tphp\Ast\stmt\IfStmt;
use Tphp\Ast\stmt\LocalConstStmt;
use Tphp\Ast\stmt\LocalDecl;
use Tphp\Ast\stmt\ReturnStmt;
use Tphp\Ast\stmt\Stmt;
use Tphp\Ast\stmt\SwitchStmt;
use Tphp\Ast\stmt\ThrowStmt;
use Tphp\Ast\stmt\WhileStmt;
use Tphp\Table\ClassSymbol;
use Tphp\Table\ConstSymbol;
use Tphp\Table\FnSymbol;
use Tphp\Table\InterfaceSymbol;
use Tphp\Table\ParamSymbol;
use Tphp\Table\Scope;
use Tphp\Table\VarSymbol;
use Tphp\Token\Pos;
use Tphp\Token\TokenKind;
use Tphp\Type\Type;
use Tphp\Gen\Names;

/** 第一遍：收集符号（类 → 成员 → 函数），第二遍：检查函数体。 */
trait CheckDeclTrait
{
    /** 文件命名空间前缀：'' 表示全局。 */
    private function fqPrefix(File $file): string
    {
        return $file->namespace !== '' ? $file->namespace . '\\' : '';
    }

    /** 注册 C 符号名（跨命名空间查重）。 */
    private function registerCSymbol(string $cName, string $phpName, ?Pos $pos): void
    {
        if (!$this->table->registerCSymbol($cName, $phpName)) {
            $other = $this->table->cNames[$cName];
            $this->error(
                "C 符号名冲突：'{$cName}' 已被 {$other} 占用（{$phpName} 与之同名，请调整命名）",
                $pos,
            );
        }
    }

    /** @param list<File> $files */
    private function collectClasses(array $files): void
    {
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof ClassDecl) {
                    continue;
                }
                $fq = $prefix . $decl->name;
                if (isset($this->table->classes[$fq]) || isset($this->table->ifaces[$fq])) {
                    $this->error("类 '{$fq}' 重复定义", $decl->pos);
                    continue;
                }
                $sym = new ClassSymbol($fq, $this->table->allocClassCode(), null, $decl->pos);
                $this->table->addClass($sym);
                $this->registerCSymbol('tphp_class_' . Type::mangleName($fq), $fq, $decl->pos);
            }
        }
    }

    /** @param list<File> $files */
    private function collectInterfaces(array $files): void
    {
        // pass 1：注册接口名（FQ）
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof InterfaceDecl) {
                    continue;
                }
                $fq = $prefix . $decl->name;
                if (isset($this->table->ifaces[$fq]) || isset($this->table->classes[$fq])) {
                    $this->error("接口 '{$fq}' 重复定义或与类名冲突", $decl->pos);
                    continue;
                }
                $sym = new InterfaceSymbol($fq, $this->table->allocClassCode(), $decl->pos);
                $this->table->addInterface($sym);
                $this->registerCSymbol('tphp_itab_' . Type::mangleName($fq), $fq, $decl->pos);
            }
        }

        // pass 2：解析 extends 链（检测循环）
        foreach ($files as $file) {
            foreach ($file->decls as $decl) {
                if (!$decl instanceof InterfaceDecl) {
                    continue;
                }
                $fq = $this->fqPrefix($file) . $decl->name;
                $sym = $this->table->ifaces[$fq];
                foreach ($decl->extends as $parentName) {
                    $parent = $this->table->ifaces[$parentName] ?? null;
                    if ($parent === null) {
                        $this->error("父接口 '{$parentName}' 不存在", $decl->pos);
                    } elseif ($parent === $sym || $parent->isSubinterfaceOf($sym)) {
                        $this->error("接口 '{$fq}' 存在循环继承", $decl->pos);
                    } else {
                        $sym->extends[] = $parent;
                    }
                }
            }
        }

        // pass 3：方法签名
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof InterfaceDecl) {
                    continue;
                }
                $sym = $this->table->ifaces[$prefix . $decl->name];
                foreach ($decl->methods as $method) {
                    if (isset($sym->methods[$method->name])) {
                        $this->error("接口方法 '{$method->name}' 重复定义", $sym->pos);
                        continue;
                    }
                    $fn = new FnSymbol($method->name, $method->ret?->pos);
                    $fn->ret = $method->ret !== null ? $this->resolveTypeRef($method->ret) : Type::I_VOID;
                    $this->registerParams($fn, $method->params);
                    $sym->methods[$method->name] = $fn;
                }
            }
        }
    }

    /** @param list<File> $files */
    private function collectConsts(array $files): void
    {
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof ConstDecl) {
                    continue;
                }
                $fq = $prefix . $decl->name;
                if (isset($this->table->consts[$fq])) {
                    $this->error("常量 '{$fq}' 重复定义", $decl->pos);
                    continue;
                }
                $type = $decl->typeRef !== null
                    ? $this->resolveTypeRef($decl->typeRef)
                    : $this->inferLiteralType($decl->value);
                if (!$this->validConstType($type, $decl->typeRef !== null, $decl->pos)) {
                    continue;
                }
                if (!$this->literalMatchesType($decl->value, $type)) {
                    $this->error(
                        "常量值类型与 {$this->table->displayName($type)} 不匹配",
                        $decl->value->pos,
                    );
                }
                $this->table->consts[$fq] = new ConstSymbol($fq, $type, $decl->value, pos: $decl->pos);
                $this->registerCSymbol('TPHP_CONST_' . strtoupper(Type::mangleName($fq)), $fq, $decl->pos);
            }
        }
    }

    /** 常量类型合法性：标量（int/float/bool/string 及 c.* 标量别名）。 */
    private function validConstType(int $type, bool $explicit, ?Pos $pos): bool
    {
        if ($type === Type::NONE) {
            return false; // 类型解析已报错
        }
        if ($explicit && $this->table->isScalar($type)) {
            return true;
        }
        if (!$explicit) {
            return $this->table->isScalar($type);
        }
        $this->error('常量类型必须是标量（int/float/double/bool/string 或 c.* 标量）', $pos);
        return false;
    }

    /** 从字面量推断常量类型。 */
    private function inferLiteralType(Expr $e): int
    {
        if ($e instanceof IntLit) {
            return Type::I_INT;
        }
        if ($e instanceof FloatLit) {
            return Type::I_DOUBLE;
        }
        if ($e instanceof StrLit) {
            return Type::I_STRING;
        }
        if ($e instanceof BoolLit) {
            return Type::I_BOOL;
        }
        if ($e instanceof UnaryExpr && in_array($e->op, [TokenKind::Minus, TokenKind::Plus, TokenKind::Tilde], true)) {
            return $this->inferLiteralType($e->expr);
        }
        return Type::NONE;
    }

    /** @param list<File> $files */
    private function collectMembers(array $files): void
    {
        // 先解析继承关系（全部类名已注册）
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof ClassDecl) {
                    continue;
                }
                $sym = $this->table->classes[$prefix . $decl->name];
                if ($decl->extends !== null) {
                    $parent = $this->table->classes[$decl->extends] ?? null;
                    if ($parent === null) {
                        $this->error("父类 '{$decl->extends}' 不存在", $decl->pos);
                    } elseif ($parent === $sym || $parent->isSubclassOf($sym)) {
                        $this->error("类 '{$decl->name}' 存在循环继承", $decl->pos);
                    } else {
                        $sym->parent = $parent;
                    }
                }
            }
        }

        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof ClassDecl) {
                    continue;
                }
                $sym = $this->table->classes[$prefix . $decl->name];
                // 解析 implements（接口已在 collectInterfaces 注册）
                foreach ($decl->implements as $ifaceName) {
                    $iface = $this->table->ifaces[$ifaceName] ?? null;
                    if ($iface === null) {
                        $this->error("接口 '{$ifaceName}' 不存在", $decl->pos);
                        continue;
                    }
                    $sym->implements[] = $iface;
                }
                foreach ($decl->classConsts as $cc) {
                    $this->registerClassConst($sym, $cc);
                }
                foreach ($decl->props as $prop) {
                    $this->registerProp($sym, $prop);
                }
                foreach ($decl->methods as $method) {
                    $this->curClass = $sym; // : self 等类型解析需要类上下文
                    $this->registerMethod($sym, $method);
                    $this->curClass = null;
                }
                $this->buildVtable($sym);
            }
        }

        // implements 校验：所需接口方法必须存在且签名一致
        foreach ($files as $file) {
            foreach ($file->decls as $decl) {
                if ($decl instanceof ClassDecl) {
                    $sym = $this->table->classes[$this->fqPrefix($file) . $decl->name] ?? null;
                    if ($sym !== null) {
                        $this->validateImplements($sym);
                    }
                }
            }
        }
    }

    /** 类沿继承链实现的全部接口（含接口 extends 闭包）。 @return array<string, InterfaceSymbol> */
    private function requiredInterfaces(ClassSymbol $class): array
    {
        $out = [];
        for ($c = $class; $c !== null; $c = $c->parent) {
            foreach ($c->implements as $iface) {
                foreach ($iface->extendsClosure() as $name => $ancestor) {
                    $out[$name] = $ancestor;
                }
            }
        }
        return $out;
    }

    private function validateImplements(ClassSymbol $sym): void
    {
        foreach ($this->requiredInterfaces($sym) as $iface) {
            foreach ($iface->orderedMethods() as $name => $sig) {
                $fn = $sym->findMethod($name);
                if ($fn === null) {
                    $this->error(
                        "类 {$sym->name} 实现接口 {$iface->name} 缺少方法 {$name}()",
                        $sig->pos,
                    );
                    continue;
                }
                if (!$this->signaturesMatch($fn, $sig)) {
                    $this->error(
                        "类 {$sym->name} 的 {$name}() 签名与接口 {$iface->name} 不一致",
                        $fn->pos,
                    );
                }
            }
        }
    }

    private function signaturesMatch(FnSymbol $impl, FnSymbol $sig): bool
    {
        if (count($impl->params) !== count($sig->params) || $impl->ret !== $sig->ret) {
            return false;
        }
        foreach ($impl->params as $i => $param) {
            if ($param->type !== $sig->params[$i]->type) {
                return false;
            }
        }
        return true;
    }

    private function registerClassConst(ClassSymbol $sym, object $cc): void
    {
        if (isset($sym->consts[$cc->name])) {
            $this->error("类常量 '{$cc->name}' 在类 {$sym->name} 中重复定义", $cc->typeRef->pos);
            return;
        }
        if ($sym->findConst($cc->name) !== null) {
            $this->error("类常量 '{$cc->name}' 与父类继承的常量冲突", $cc->typeRef->pos);
            return;
        }
        $type = $this->resolveTypeRef($cc->typeRef);
        if (!$this->validConstType($type, true, $cc->typeRef->pos)) {
            return;
        }
        if (!$this->literalMatchesType($cc->value, $type)) {
            $this->error(
                "类常量值类型与 {$this->table->displayName($type)} 不匹配",
                $cc->value->pos,
            );
        }
        $sym->consts[$cc->name] = new ConstSymbol($cc->name, $type, $cc->value, $cc->vis, $sym, $cc->typeRef->pos);
    }

    private function registerProp(ClassSymbol $sym, object $prop): void
    {
        if (isset($sym->props[$prop->name])) {
            $this->error("属性 '{$prop->name}' 在类 {$sym->name} 中重复定义", $prop->typeRef->pos);
            return;
        }
        if ($sym->findProp($prop->name) !== null) {
            $this->error(
                "属性 '\${$prop->name}' 与父类中继承的属性冲突（本语言不允许属性遮蔽）",
                $prop->typeRef->pos,
            );
            return;
        }
        $type = $this->resolveTypeRef($prop->typeRef);
        if ($this->table->isMap($type)) {
            $this->error('map 暂不支持作为类字段（仅局部变量与参数）', $prop->typeRef->pos);
            return;
        }
        if ($prop->hasDefault) {
            if ($prop->default === null || !$this->isLiteralScalar($prop->default)) {
                $this->error('属性默认值必须是标量或 null 字面量', $prop->typeRef->pos);
            } elseif (!$this->literalMatchesType($prop->default, $type)) {
                $this->error(
                    "属性默认值类型与 {$this->table->displayName($type)} 不匹配",
                    $prop->typeRef->pos,
                );
            }
        }
        $var = new VarSymbol($prop->name, $type, $prop->typeRef->pos, $prop->vis, $prop->isStatic);
        $var->hasDefault = $prop->hasDefault;
        $var->default = $prop->default;
        $var->owner = $sym;
        $sym->props[$prop->name] = $var;
    }

    private function registerMethod(ClassSymbol $sym, object $method): void
    {
        if (isset($sym->methods[$method->name])) {
            $this->error("方法 '{$method->name}' 在类 {$sym->name} 中重复定义", $method->ret?->pos);
            return;
        }
        $fn = new FnSymbol(
            $method->name,
            $method->ret?->pos,
            isMethod: true,
            ownerClass: $sym,
            isStatic: $method->isStatic,
            isCtor: $method->name === '__construct',
            isDtor: $method->name === '__destruct',
            vis: $method->vis,
        );
        $fn->ret = $method->ret !== null ? $this->resolveTypeRef($method->ret) : Type::I_VOID;
        $this->registerParams($fn, $method->params);
        $sym->methods[$method->name] = $fn;
    }

    /** @param list<object> $params */
    private function registerParams(FnSymbol $fn, array $params): void
    {
        $seen = [];
        foreach ($params as $param) {
            if (isset($seen[$param->name])) {
                $this->error("参数 '\${$param->name}' 重复", $param->typeRef->pos);
                continue;
            }
            $seen[$param->name] = true;
            $type = $this->resolveTypeRef($param->typeRef);
            if ($type === Type::I_VOID) {
                $this->error('参数类型不能是 void', $param->typeRef->pos);
            }
            if ($param->hasDefault) {
                if ($param->default === null || !$this->isLiteralScalar($param->default)) {
                    $this->error('参数默认值必须是标量或 null 字面量', $param->typeRef->pos);
                } elseif (!$this->literalMatchesType($param->default, $type)) {
                    $this->error(
                        "参数默认值类型与 {$this->table->displayName($type)} 不匹配",
                        $param->typeRef->pos,
                    );
                }
            }
            $sym = new ParamSymbol($type, $param->name, $param->hasDefault, $param->default, $param->typeRef->pos);
            $fn->params[] = $sym;
        }
    }

    /** 枚举类：注册符号、校验 case（无自动赋值、值唯一）、注册方法/常量/接口与合成方法。 */
    private function collectEnums(array $files): void
    {
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof EnumDecl) {
                    continue;
                }
                $fq = $prefix . $decl->name;
                if (isset($this->table->classes[$fq])) {
                    $this->error("类/枚举 '{$decl->name}' 重复定义", $decl->pos);
                    continue;
                }
                $sym = new ClassSymbol($fq, $this->table->allocClassCode(), pos: $decl->pos);
                $sym->isEnum = true;
                if ($decl->backing !== null) {
                    $backing = $this->resolveTypeRef($decl->backing);
                    if ($backing !== Type::I_INT && $backing !== Type::I_STRING) {
                        $this->error('枚举 backing 类型只能是 int 或 string', $decl->backing->pos);
                        $backing = Type::I_INT;
                    }
                    $sym->enumBacking = $backing;
                }
                $this->table->addClass($sym);
                $this->registerCSymbol(Names::classStruct($fq), $fq, $decl->pos);

                $seen = [];
                foreach ($decl->cases as $c) {
                    if (isset($seen[$c['name']])) {
                        $this->error("case '{$c['name']}' 重复定义", $c['pos']);
                        continue;
                    }
                    $seen[$c['name']] = true;
                    $value = $c['value'];
                    if ($sym->enumBacking !== null) {
                        if ($value === null) {
                            $this->error("backed 枚举 case '{$c['name']}' 必须显式赋值（无自动赋值）", $c['pos']);
                            continue;
                        }
                        $inner = $value instanceof UnaryExpr ? $value->expr : $value;
                        $ok = $sym->enumBacking === Type::I_INT
                            ? $inner instanceof IntLit
                            : $inner instanceof StrLit;
                        if (!$ok) {
                            $this->error('case 值必须是与 backing 匹配的字面量（int → 整数，string → 单引号字符串）', $c['pos']);
                            continue;
                        }
                        // 值唯一性（按字面量文本归一）
                        $vt = $sym->enumBacking === Type::I_INT ? $inner->value : $inner->value;
                        if (in_array($vt, array_column($sym->enumCases, 'v'), true)) {
                            $this->error("case 值 {$vt} 与既有 case 重复", $c['pos']);
                            continue;
                        }
                        $sym->enumCases[] = ['name' => $c['name'], 'value' => $value, 'v' => $vt];
                    } else {
                        if ($value !== null) {
                            $this->error('纯枚举 case 不能赋值', $c['pos']);
                            continue;
                        }
                        $sym->enumCases[] = ['name' => $c['name'], 'value' => null];
                    }
                }
                if ($sym->enumBacking !== null && $sym->enumCases === []) {
                    $this->error("backed 枚举 '{$decl->name}' 至少需要一个 case", $decl->pos);
                }

                // 方法 / 常量（复用类通道；: self 返回等特性自动可用）
                $this->curClass = $sym;
                foreach ($decl->consts as $cc) {
                    $this->registerClassConst($sym, $cc);
                }
                foreach ($decl->methods as $method) {
                    if ($method->name === '__construct' || $method->name === '__destruct') {
                        $this->error("枚举不能声明 {$method->name}", $method->pos);
                        continue;
                    }
                    $this->registerMethod($sym, $method);
                }
                // 合成静态方法：cases()（全部枚举）；from/tryFrom（backed）
                $casesRet = $this->table->arrayOf($sym->code);
                $casesFn = new FnSymbol('cases', null, isMethod: true, ownerClass: $sym, isStatic: true);
                $casesFn->ret = $casesRet;
                $sym->methods['cases'] = $casesFn;
                if ($sym->enumBacking !== null) {
                    $from = new FnSymbol('from', null, isMethod: true, ownerClass: $sym, isStatic: true);
                    $from->ret = $sym->code;
                    $from->params[] = new ParamSymbol($sym->enumBacking, 'value');
                    $sym->methods['from'] = $from;
                    $tryFrom = new FnSymbol('tryFrom', null, isMethod: true, ownerClass: $sym, isStatic: true);
                    $tryFrom->ret = $sym->code;
                    $tryFrom->params[] = new ParamSymbol($sym->enumBacking, 'value');
                    $sym->methods['tryFrom'] = $tryFrom;
                }
                foreach ($decl->implements as $ifaceName) {
                    $iface = $this->table->ifaces[$ifaceName] ?? null;
                    if ($iface === null) {
                        $this->error("接口 '{$ifaceName}' 不存在", $decl->pos);
                        continue;
                    }
                    $sym->implements[] = $iface;
                }
                $this->buildVtable($sym);
                $this->curClass = null;
            }
        }

        // 枚举的接口校验（与类同一规则）
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof EnumDecl) {
                    continue;
                }
                $sym = $this->table->classes[$prefix . $decl->name];
                $this->validateImplements($sym);
            }
        }
    }

    /** vtable 顺序：父类方法在前（保持前缀布局），本类新增方法追加在后。 */
    private function buildVtable(ClassSymbol $sym): void
    {
        $order = $sym->parent !== null ? $sym->parent->vtableOrder : [];
        foreach ($sym->methods as $name => $fn) {
            if ($fn->isDtor) {
                if ($fn->isStatic) {
                    $this->error('__destruct 不能是 static', $fn->pos);
                }
                if ($fn->params !== []) {
                    $this->error('__destruct 不能有参数', $fn->pos);
                }
                if (!$this->table->isVoid($fn->ret)) {
                    $this->error('__destruct 返回类型必须是 void', $fn->pos);
                }
                continue; // 不进 vtable（仅由运行时析构路径调用）
            }
            if (!$fn->isStatic && !$fn->isCtor && !in_array($name, $order, true)) {
                $order[] = $name;
            }
        }
        $sym->vtableOrder = $order;
    }

    /** @param list<File> $files */
    private function collectFunctions(array $files): void
    {
        foreach ($files as $file) {
            $prefix = $this->fqPrefix($file);
            foreach ($file->decls as $decl) {
                if (!$decl instanceof FunctionDecl) {
                    continue;
                }
                $fq = $prefix . $decl->name;
                if (isset($this->table->fns[$fq])) {
                    $this->error("函数 '{$decl->name}' 重复定义（或与内置函数冲突）", $decl->pos);
                    continue;
                }
                $fn = new FnSymbol($fq, $decl->pos);
                $fn->ret = $decl->ret !== null ? $this->resolveTypeRef($decl->ret) : Type::I_VOID;
                if ($decl->exportName !== null) {
                    if (!\Tphp\Gen\Names::validCIdentifier($decl->exportName)) {
                        $this->error(
                            "#[export] 名称 '{$decl->exportName}' 不是合法的 C 标识符（或与 C 关键字冲突）",
                            $decl->pos,
                        );
                    } else {
                        $fn->exportName = $decl->exportName;
                    }
                }
                $this->registerParams($fn, $decl->params);
                $this->table->fns[$fq] = $fn;
                $this->registerCSymbol($fn->exportName ?? 'tphp_' . Type::mangleName($fq), $fq, $decl->pos);
            }
        }
    }

    private function validateEntry(string $entryPath): void
    {
        $main = $this->table->classes['Main'] ?? null;
        if ($main === null) {
            $this->error('程序入口必须包含全局命名空间的 class Main', new Pos($entryPath, 1, 1));
            return;
        }
        // 构造器可声明为无参或 (int $argc, array<string> $argv)——命令行参数入口（旧版 tphp 惯例）
        $ctor = $main->methods['__construct'] ?? null;
        if ($ctor !== null && $ctor->params !== []) {
            $ok = count($ctor->params) === 2
                && $ctor->params[0]->type === Type::I_INT
                && $this->table->isArray($ctor->params[1]->type)
                && $this->table->arrayElemOf($ctor->params[1]->type) === Type::I_STRING;
            if (!$ok) {
                $this->error(
                    'Main::__construct 的参数签名必须是 (int $argc, array<string> $argv) 或无参',
                    $ctor->pos,
                );
            }
        }
        $fn = $main->methods['main'] ?? null;
        if ($fn === null) {
            $this->error('class Main 必须定义 main() 方法', $main->pos);
            return;
        }
        if ($fn->params !== []) {
            $this->error('main() 不能有参数', $fn->pos);
        }
        if ($fn->ret !== Type::I_VOID) {
            $this->error('main() 的返回类型必须是 void', $fn->pos);
        }
    }

    /** @param list<File> $files */
    private function checkBodies(array $files): void
    {
        // 第一遍：闭包签名流动（实参→形参、return→函数、赋值→变量）。
        // 跨函数传播依赖"调用点先于被调体检查"的顺序不可保证，故先静默跑一遍。
        $this->sigOnly = true;
        foreach ($files as $file) {
            $this->checkBodiesOnce($file);
        }
        $this->sigOnly = false;
        foreach ($files as $file) {
            $this->checkBodiesOnce($file);
        }
    }

    private function checkBodiesOnce(File $file): void
    {
        {
            foreach ($file->decls as $decl) {
                if ($decl instanceof FunctionDecl) {
                    $fn = $this->table->fns[$this->fqPrefix($file) . $decl->name] ?? null;
                    if ($fn === null) {
                        continue; // 注册阶段报过错
                    }
                    $this->checkFnBody($fn, $decl->body);
                    continue;
                }
                if ($decl instanceof ClassDecl) {
                    $sym = $this->table->classes[$this->fqPrefix($file) . $decl->name];
                    $this->curClass = $sym;
                    foreach ($decl->methods as $method) {
                        $fn = $sym->methods[$method->name] ?? null;
                        if ($fn === null) {
                            continue;
                        }
                        $this->checkFnBody($fn, $method->body);
                    }
                    $this->curClass = null;
                }
                if ($decl instanceof EnumDecl) {
                    $sym = $this->table->classes[$this->fqPrefix($file) . $decl->name];
                    $this->curClass = $sym;
                    foreach ($decl->methods as $method) {
                        $fn = $sym->methods[$method->name] ?? null;
                        if ($fn === null) {
                            continue;
                        }
                        $this->checkFnBody($fn, $method->body);
                    }
                    $this->curClass = null;
                }
            }
        }
    }

    /** @param list<object> $body */
    private function checkFnBody(FnSymbol $fn, array $body): void
    {
        $this->curFn = $fn;
        $this->scope = new Scope(null, $fn);
        $this->boxedNames = [];
        $this->scanClosureRefCaptures($body); // 预扫描：本函数体内 use (&$var) 名单

        foreach ($fn->params as $param) {
            $sym = new VarSymbol($param->name, $param->type, $param->pos);
            $sym->closureSig = $param->closureSig;
            if (isset($this->boxedNames[$param->name])) {
                $sym->boxed = true;
                $param->boxed = true;
            }
            $this->scope->vars[$param->name] = $sym;
        }
        if ($fn->isMethod && !$fn->isStatic) {
            $this->scope->vars['this'] = new VarSymbol('this', $fn->ownerClass->code, $fn->pos);
        }

        $this->checkStmts($body);

        $this->scope = new Scope();
        $this->curFn = null;
    }

    // ------------------------------------------------------------------ 闭包引用捕获预扫描

    /** 预扫描函数体：收集全部 use (&$var) 捕获名（声明落地为堆盒子用，doc/closure.md §3.5）。 */
    private function scanClosureRefCaptures(array $body): void
    {
        $this->scanStmts($body);
    }

    /** @param list<object> $stmts */
    private function scanStmts(array $stmts): void
    {
        foreach ($stmts as $s) {
            $this->scanStmt($s);
        }
    }

    private function scanStmt(object $s): void
    {
        if ($s instanceof BlockStmt) {
            $this->scanStmts($s->stmts);
        } elseif ($s instanceof IfStmt) {
            $this->scanExpr($s->cond);
            $this->scanStmts($s->then);
            if ($s->else !== null) {
                $this->scanStmts($s->else);
            }
        } elseif ($s instanceof WhileStmt) {
            $this->scanExpr($s->cond);
            $this->scanStmts($s->body);
        } elseif ($s instanceof DoWhileStmt) {
            $this->scanStmts($s->body);
            $this->scanExpr($s->cond);
        } elseif ($s instanceof ForStmt) {
            if ($s->init !== null) {
                $this->scanStmt($s->init);
            }
            if ($s->cond !== null) {
                $this->scanExpr($s->cond);
            }
            if ($s->post !== null) {
                $this->scanExpr($s->post);
            }
            $this->scanStmts($s->body);
        } elseif ($s instanceof ForeachStmt) {
            $this->scanExpr($s->arr);
            $this->scanStmts($s->body);
        } elseif ($s instanceof SwitchStmt) {
            $this->scanExpr($s->cond);
            foreach ($s->cases as $c) {
                if ($c->cond !== null) {
                    $this->scanExpr($c->cond);
                }
                $this->scanStmts($c->stmts);
            }
        } elseif ($s instanceof ReturnStmt) {
            if ($s->expr !== null) {
                $this->scanExpr($s->expr);
            }
        } elseif ($s instanceof EchoStmt) {
            foreach ($s->parts as $p) {
                $this->scanExpr($p);
            }
        } elseif ($s instanceof ExprStmt) {
            $this->scanExpr($s->expr);
        } elseif ($s instanceof ThrowStmt) {
            $this->scanExpr($s->expr);
        } elseif ($s instanceof LocalConstStmt) {
            $this->scanExpr($s->value);
        } elseif ($s instanceof LocalDecl) {
            if ($s->init !== null) {
                $this->scanExpr($s->init);
            }
        }
        // Break / Continue 无子节点
    }

    private function scanExpr(Expr $e): void
    {
        if ($e instanceof ClosureExpr) {
            // 闭包体不进入，但其 use (&$var) 引用捕获名要登记（外层变量提升为盒子）
            foreach ($e->captures as $c) {
                if ($c['byRef']) {
                    $this->boxedNames[$c['name']] = true;
                }
            }
            return;
        }
        if ($e instanceof AssignExpr) {
            $this->scanExpr($e->target);
            $this->scanExpr($e->value);
        } elseif ($e instanceof BinaryExpr) {
            $this->scanExpr($e->left);
            $this->scanExpr($e->right);
        } elseif ($e instanceof TernaryExpr) {
            $this->scanExpr($e->cond);
            $this->scanExpr($e->then);
            $this->scanExpr($e->else);
        } elseif ($e instanceof UnaryExpr) {
            $this->scanExpr($e->expr);
        } elseif ($e instanceof PostfixExpr) {
            $this->scanExpr($e->expr);
        } elseif ($e instanceof CastExpr) {
            $this->scanExpr($e->expr);
        } elseif ($e instanceof IndexExpr) {
            $this->scanExpr($e->base);
            if ($e->index !== null) {
                $this->scanExpr($e->index);
            }
        } elseif ($e instanceof PropFetch) {
            $this->scanExpr($e->obj);
        } elseif ($e instanceof MethodCall) {
            $this->scanExpr($e->obj);
            $this->scanExprs($e->args);
        } elseif ($e instanceof InvokeExpr) {
            $this->scanExpr($e->callee);
            $this->scanExprs($e->args);
        } elseif ($e instanceof OrExpr) {
            $this->scanExpr($e->call);
            $this->scanStmts($e->block);
        } elseif ($e instanceof InterpStr) {
            foreach ($e->parts as $p) {
                if (!is_string($p)) {
                    $this->scanExpr($p);
                }
            }
        } elseif ($e instanceof ArrayLit) {
            $this->scanExprs($e->items);
        } elseif ($e instanceof CallExpr
            || $e instanceof NewExpr || $e instanceof StaticCall || $e instanceof CCallExpr) {
            $this->scanExprs($e->args);
        }
        // 字面量 / Var / This / Name / StaticProp / StaticConst / CConst 无需下钻
    }

    /** @param list<Expr> $exprs */
    private function scanExprs(array $exprs): void
    {
        foreach ($exprs as $e) {
            $this->scanExpr($e);
        }
    }

    /** 属性/参数默认值必须是标量或 null 字面量。 */
    private function isLiteralScalar(Expr $e): bool
    {        if ($e instanceof IntLit || $e instanceof FloatLit || $e instanceof StrLit || $e instanceof BoolLit || $e instanceof NullLit) {
            return true;
        }
        if ($e instanceof UnaryExpr
            && in_array($e->op, [\Tphp\Token\TokenKind::Minus, \Tphp\Token\TokenKind::Plus], true)) {
            return $this->isLiteralScalar($e->expr);
        }
        return false;
    }

    /** 字面量默认值与声明类型是否同族。 */
    private function literalMatchesType(Expr $e, int $type): bool
    {
        if ($type === Type::NONE) {
            return true; // 类型解析已报错
        }
        $unwrapUnary = static function (Expr $x) use (&$unwrapUnary): ?Expr {
            if ($x instanceof UnaryExpr && in_array($x->op, [TokenKind::Minus, TokenKind::Plus, TokenKind::Tilde], true)) {
                return $unwrapUnary($x->expr);
            }
            return $x;
        };
        $inner = $unwrapUnary($e);
        if ($inner instanceof IntLit) {
            return $this->table->isIntLike($type);
        }
        if ($inner instanceof FloatLit) {
            return $this->table->isFloatLike($type);
        }
        if ($inner instanceof StrLit) {
            return $type === Type::I_STRING;
        }
        if ($inner instanceof BoolLit) {
            return $type === Type::I_BOOL;
        }
        if ($inner instanceof NullLit) {
            return $this->table->isRefType($type);
        }
        return false;
    }
}
