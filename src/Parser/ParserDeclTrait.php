<?php

declare(strict_types=1);

namespace Tphp\Parser;

use Tphp\Ast\TypeRef;
use Tphp\Ast\decl\ClassConstDecl;
use Tphp\Ast\decl\ClassDecl;
use Tphp\Ast\decl\ClassMethod;
use Tphp\Ast\decl\ClassProp;
use Tphp\Ast\decl\ConstDecl;
use Tphp\Ast\decl\CStructDecl;
use Tphp\Ast\decl\EnumDecl;
use Tphp\Ast\decl\FunctionDecl;
use Tphp\Ast\decl\InterfaceDecl;
use Tphp\Ast\decl\InterfaceMethod;
use Tphp\Ast\decl\Param;
use Tphp\Ast\expr\IntLit;
use Tphp\Token\Token;
use Tphp\Token\TokenKind;

/** 顶层声明解析：函数与类。 */
trait ParserDeclTrait
{
    /** @return list<FunctionDecl|ClassDecl|ConstDecl> */
    private function parseTopLevel(): array
    {
        $decls = [];
        $declared = false; // use 必须出现在全部声明之前
        while (!$this->is(TokenKind::Eof)) {
            if ($this->is(TokenKind::DirInclude)) {
                $lit = $this->next()->lit;
                $path = substr($lit, strlen('include '));
                $this->validateInclude($path);
                $this->fileIncludes[] = $path;
                continue;
            }
            if ($this->is(TokenKind::DirFlag)) {
                $lit = $this->next()->lit;
                $this->validateFlag($lit);
                $this->fileCflags[] = $lit;
                continue;
            }
            if ($this->is(TokenKind::DirStruct)) {
                $declared = true;
                $decls[] = $this->parseCStructRest();
                continue;
            }
            if ($this->is(TokenKind::DirEnum)) {
                $declared = true;
                array_push($decls, ...$this->parseEnumRest());
                continue;
            }
            if ($this->is(TokenKind::DirExport)) {
                $exportTok = $this->next();
                if ($this->is(TokenKind::DirExport)) {
                    $this->errHere('重复的 #[export] 注解');
                    continue;
                }
                if (!$this->match(TokenKind::KwFunction)) {
                    $this->errHere('#[export] 仅全局函数有效：必须紧跟顶层 function 声明');
                    continue;
                }
                $declared = true;
                $decls[] = $this->parseFunctionRest($exportTok->lit !== '' ? $exportTok->lit : null);
                continue;
            }
            if ($this->match(TokenKind::KwUse)) {
                if ($declared) {
                    $this->errHere('use 必须出现在所有类/函数/常量声明之前');
                }
                $this->parseUseDecl();
                continue;
            }
            if ($this->match(TokenKind::KwFunction)) {
                $declared = true;
                $decls[] = $this->parseFunctionRest();
                continue;
            }
            if ($this->match(TokenKind::KwEnum)) {
                $declared = true;
                $decls[] = $this->parseEnumDecl();
                continue;
            }
            if ($this->match(TokenKind::KwClass)) {
                $declared = true;
                $decls[] = $this->parseClassRest();
                continue;
            }
            if ($this->match(TokenKind::KwConst)) {
                $declared = true;
                $decls[] = $this->parseConstRest();
                continue;
            }
            if ($this->match(TokenKind::KwInterface)) {
                $declared = true;
                $decls[] = $this->parseInterfaceRest();
                continue;
            }
            if ($this->is(TokenKind::KwNamespace)) {
                $this->errHere('namespace 必须是文件第一条声明，且每文件只能有一个');
                $this->next();
                continue;
            }
            $this->errHere('顶层只允许 namespace / use / function / class / interface / const 声明');
            $this->next();
        }
        return $decls;
    }

    // ------------------------------------------------------------------ phpc 指令安全校验
    /**
     * #include 安全校验：`<...>` 系统头放行；`"..."` 相对路径必须停留在项目内。
     * （对齐 vlang：源文件不可通过指令触达构建机上的任意文件。）
     */
    private function validateInclude(string $path): void
    {
        if (str_starts_with($path, '<')) {
            return; // 系统头
        }
        if (!str_starts_with($path, '"') || !str_ends_with($path, '"') || strlen($path) < 3) {
            $this->errHere('#include 语法应为 <系统头> 或 "相对路径"');
            return;
        }
        $p = substr($path, 1, -1);
        if (!$this->isSafeRelativePath($p)) {
            $this->errHere("#include 路径必须是不含 \"..\" 的项目内相对路径，得到 \"{$p}\"");
            return;
        }
        if (!str_ends_with($p, '.h')) {
            $this->errHere("#include 只允许 .h 头文件，得到 \"{$p}\"");
        }
    }

    /**
     * #flag 安全校验（白名单，对齐 vlang ast.parse_cflag 并更严格）：
     *   -I/-L 路径、-l 库名、-D 宏、-U/-std/-f/-m/-O/-g/-W 编译选项、.c/.h/.o/.a 链接文件。
     * 拒绝：反引号与 shell 元字符、`..` 路径、盘符绝对路径、一切其他编译器标志
     * （-B/-specs/-wrapper/--include 等可让构建机执行任意文件的标志被白名单天然挡下）。
     * 构建者自身的可信参数走 CLI --cflag=（不经此校验）。
     */
    private function validateFlag(string $text): void
    {
        if (preg_match('/[`;$&|]/', $text) === 1 || str_contains($text, '$(')) {
            $this->errHere("#flag 含非法字符（shell 元字符）：{$text}");
            return;
        }
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            if ($tok[0] === '-') {
                if (!$this->isAllowedCompilerFlag($tok)) {
                    $this->errHere("#flag 不允许编译器选项 \"{$tok}\"（允许 -I -L -l -D -U -std -f -m -O -g -W 及 .c/.h/.o/.a 文件）；构建者参数请用 CLI --cflag=");
                    return;
                }
                continue;
            }
            // 裸 token = 附加源文件 / 归档
            if (!str_ends_with($tok, '.c') && !str_ends_with($tok, '.h')
                && !str_ends_with($tok, '.o') && !str_ends_with($tok, '.a')) {
                $this->errHere("#flag 裸参数只允许 .c/.h/.o/.a 文件，得到 \"{$tok}\"");
                return;
            }
            if (!$this->isSafeRelativePath($tok)) {
                $this->errHere("#flag 文件路径必须是不含 \"..\" 的项目内相对路径：\"{$tok}\"");
                return;
            }
        }
    }

    /** 编译器标志白名单；返回 false = 拒绝。 */
    private function isAllowedCompilerFlag(string $tok): bool
    {
        // 危险特例（会被宽松前缀误放行）
        if (str_starts_with($tok, '-wrapper') || str_starts_with($tok, '-B')
            || str_starts_with($tok, '-specs') || str_starts_with($tok, '--include')
            || str_starts_with($tok, '-imacros') || str_starts_with($tok, '-Xlinker')) {
            return false;
        }
        foreach (['-I', '-L', '-l', '-D', '-U', '-std=', '-f', '-m', '-O', '-g', '-W'] as $prefix) {
            if (str_starts_with($tok, $prefix) && strlen($tok) > strlen($prefix)) {
                $value = substr($tok, strlen($prefix));
                return match ($prefix) {
                    '-I', '-L' => $this->isSafeRelativePath($value),
                    '-l' => preg_match('/^[A-Za-z0-9_.+-]+$/', $value) === 1,
                    '-D' => preg_match('/^[A-Za-z_][A-Za-z0-9_]*(=[^"\'`\\\\;]*)?$/', $value) === 1,
                    '-U' => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1,
                    '-std=' => preg_match('/^[a-z0-9+]+$/', $value) === 1,
                    default => preg_match('/^[A-Za-z0-9_.=,:+\/-]*$/', $value) === 1, // -f/-m/-O/-g/-W 值字符白名单
                };
            }
        }
        return false;
    }

    /** 相对路径安全：非绝对、无 ..、无反引号。 */
    private function isSafeRelativePath(string $p): bool
    {
        if ($p === '' || str_contains($p, "\0")) {
            return false;
        }
        $norm = str_replace('\\', '/', $p);
        if (str_starts_with($norm, '/') || preg_match('/^[A-Za-z]:/', $norm) === 1) {
            return false; // 绝对路径
        }
        foreach (explode('/', $norm) as $seg) {
            if ($seg === '..') {
                return false;
            }
        }
        return !str_contains($p, '`');
    }

    /**
     * 枚举类：enum Name [: int|string] [implements A, B] { case X [= lit]; ... 方法/常量 }。
     * case = 单例对象（无自动赋值；backed 值必须显式且唯一）。
     */
    private function parseEnumDecl(): EnumDecl
    {
        $nameTok = $this->peek(); // 枚举名（'enum' 已由 parseTopLevel 消费）
        $name = $this->expect(TokenKind::Ident, '枚举名')->lit;

        $backing = null;
        if ($this->match(TokenKind::Colon)) {
            $backing = $this->parseTypeRef();
        }

        $implements = [];
        if ($this->match(TokenKind::KwImplements)) {
            do {
                $implements[] = $this->resolveClassName($this->parseQualifiedName());
            } while ($this->match(TokenKind::Comma));
        }
        $this->expect(TokenKind::Lbrace, "'{'");

        $cases = [];
        $methods = [];
        $consts = [];
        $seenCase = false;
        while (!$this->is(TokenKind::Rbrace) && !$this->is(TokenKind::Eof)) {
            // case X [= literal];
            if ($this->match(TokenKind::KwCase)) {
                $seenCase = true;
                $cTok = $this->expect(TokenKind::Ident, 'case 名');
                $value = null;
                if ($this->match(TokenKind::Eq)) {
                    $value = $this->parseUnary(); // 整数/字符串字面量（Checker 校验类型）
                }
                $this->expect(TokenKind::Semicolon, "';'");
                $cases[] = ['name' => $cTok->lit, 'value' => $value, 'pos' => $cTok->pos];
                continue;
            }
            if (!$seenCase && $this->is(TokenKind::KwPublic)) {
                $this->errHere('case 必须出现在枚举体最前（PHP 语义）');
                $this->next();
                continue;
            }
            // 方法 / 常量：复用类成员解析
            $vis = 'public';
            if ($this->match(TokenKind::KwPublic)) {
                $vis = 'public';
            } elseif ($this->match(TokenKind::KwPrivate) || $this->match(TokenKind::KwProtected)) {
                $this->errHere('枚举成员只能是 public');
            }
            $isStatic = $this->match(TokenKind::KwStatic);

            if ($this->match(TokenKind::KwConst)) {
                $typeRef = $this->parseTypeRef();
                $cnameTok = $this->expect(TokenKind::Ident, '常量名');
                $this->expect(TokenKind::Eq, "'='");
                $value = $this->parseExpr();
                $this->expect(TokenKind::Semicolon, "';'");
                $cc = new ClassConstDecl($vis, $typeRef, $cnameTok->lit, $value);
                $cc->pos = $typeRef->pos;
                $consts[] = $cc;
                continue;
            }
            $this->expect(TokenKind::KwFunction, "'function' 或 'case'");
            $mnameTok = $this->expect(TokenKind::Ident, '方法名');
            $params = $this->parseParamList();
            $ret = $this->match(TokenKind::Colon) ? $this->parseTypeRef() : null;
            $body = $this->parseBracedBlock();
            $method = new ClassMethod($vis, $isStatic, $mnameTok->lit, $params, $ret, $body);
            $method->pos = $mnameTok->pos;
            $methods[] = $method;
        }
        $this->expect(TokenKind::Rbrace, "'}'");
        $decl = new EnumDecl($name, $backing, $cases, $methods, $consts, $implements);
        $decl->pos = $nameTok->pos;
        return $decl;
    }

    /**
     * #enum Name { A = 1, B }：C 枚举常量集。
     * 解析期展开为合成的 c.i32 ConstDecl 列表（成员名 = 枚举名_成员名），
     * Checker/Gen 走常量通道零改动；可显式赋值，缺省 = 前值 +1（C 语义）。
     * @return list<ConstDecl>
     */
    private function parseEnumRest(): array
    {
        $this->expect(TokenKind::DirEnum, "'#enum'");
        $nameTok = $this->expect(TokenKind::Ident, '枚举名');
        $this->expect(TokenKind::Lbrace, "'{'");

        $decls = [];
        $next = 0;
        while (!$this->is(TokenKind::Rbrace) && !$this->is(TokenKind::Eof)) {
            $memberTok = $this->expect(TokenKind::Ident, '枚举成员名');
            if ($this->match(TokenKind::Eq)) {
                $valTok = $this->expect(TokenKind::IntLit, '枚举成员值');
                $next = (int) $valTok->lit;
            }
            $lit = new IntLit((string) $next, $next);
            $lit->pos = $memberTok->pos;
            $cd = new ConstDecl($nameTok->lit . '_' . $memberTok->lit, null, $lit);
            $cd->pos = $memberTok->pos;
            $decls[] = $cd;
            $next++;
            if (!$this->match(TokenKind::Comma)) {
                break;
            }
        }
        $this->expect(TokenKind::Rbrace, "'}'");
        return $decls;
    }

    /** #struct 体：#struct Name { c.u8 r; c.char* p; ... }（类型本体由头文件提供）。 */
    private function parseCStructRest(): CStructDecl
    {
        $this->expect(TokenKind::DirStruct, "'#struct'");
        $nameTok = $this->expect(TokenKind::Ident, '结构体名');
        $this->expect(TokenKind::Lbrace, "'{'");

        $fields = [];
        while (!$this->is(TokenKind::Rbrace) && !$this->is(TokenKind::Eof)) {
            $typeRef = $this->parseTypeRef();
            $fname = $this->expect(TokenKind::Ident, '字段名')->lit;
            $this->expect(TokenKind::Semicolon, "';'");
            $fields[] = ['type' => $typeRef, 'name' => $fname];
        }
        $this->expect(TokenKind::Rbrace, "'}'");
        $decl = new CStructDecl($nameTok->lit, $fields);
        $decl->pos = $nameTok->pos;
        return $decl;
    }

    /**
     * use 导入（每文件独立，PHP 全形式）：
     * use A\B; / use A\B as C; / use function A\f as g; / use const A\K;
     * use A\{B, C as D, function f, const K}; / use function A\{f1, f2 as g};
     */
    private function parseUseDecl(): void
    {
        $kind = 'class'; // class | function | const
        if ($this->match(TokenKind::KwFunction)) {
            $kind = 'function';
        } elseif ($this->match(TokenKind::KwConst)) {
            $kind = 'const';
        }

        // use 前缀：遇到 "\ {" 停止（分组导入），保留反斜杠给下方判断
        $prefix = $this->parseQualifiedName(true);

        // 分组导入：use 前缀\{ A, B as C, function f, const K };
        if ($this->match(TokenKind::Backslash) && $this->peekKind() === TokenKind::Lbrace) {
            $this->next(); // {
            do {
                $itemKind = $kind;
                if ($kind === 'class') {
                    if ($this->match(TokenKind::KwFunction)) {
                        $itemKind = 'function';
                    } elseif ($this->match(TokenKind::KwConst)) {
                        $itemKind = 'const';
                    }
                }
                $name = $this->parseQualifiedName();
                $alias = $this->match(TokenKind::KwAs)
                    ? $this->expect(TokenKind::Ident, '别名')->lit
                    : (($pos = strrpos($name, '\\')) !== false ? substr($name, $pos + 1) : $name);
                $this->registerUse($itemKind, $prefix . '\\' . $name, $alias);
            } while ($this->match(TokenKind::Comma));
            $this->expect(TokenKind::Rbrace, "'}'");
        } else {
            // 普通导入：use 前缀 [as 别名];
            $alias = $this->match(TokenKind::KwAs)
                ? $this->expect(TokenKind::Ident, '别名')->lit
                : (($pos = strrpos($prefix, '\\')) !== false ? substr($prefix, $pos + 1) : $prefix);
            $this->registerUse($kind, $prefix, $alias);
        }
        $this->expect(TokenKind::Semicolon, "';'");
    }

    private function registerUse(string $kind, string $fq, string $alias): void
    {
        if ($kind === 'function') {
            $this->functionImports[$alias] = $fq;
        } elseif ($kind === 'const') {
            $this->constImports[$alias] = $fq;
        } else {
            $this->classImports[$alias] = $fq;
        }
    }

    /** 接口声明：interface I [extends A, B] { 方法签名; } */
    private function parseInterfaceRest(): InterfaceDecl
    {
        $nameTok = $this->expect(TokenKind::Ident, '接口名');
        $extends = [];
        if ($this->match(TokenKind::KwExtends)) {
            do {
                $extends[] = $this->resolveClassName($this->parseQualifiedName());
            } while ($this->match(TokenKind::Comma));
        }
        $this->expect(TokenKind::Lbrace, "'{'");

        $methods = [];
        while (!$this->is(TokenKind::Rbrace) && !$this->is(TokenKind::Eof)) {
            if ($this->is(TokenKind::DirExport)) {
                $this->errHere('#[export] 仅全局函数有效，不能标注接口成员');
                $this->next();
                continue;
            }
            if ($this->match(TokenKind::KwPrivate) || $this->match(TokenKind::KwProtected)) {
                $this->errHere('接口方法只能是 public');
            } else {
                $this->match(TokenKind::KwPublic);
            }
            if ($this->match(TokenKind::KwStatic)) {
                $this->errHere('接口不支持静态方法');
            }
            $this->expect(TokenKind::KwFunction, "'function'");
            $mname = $this->expect(TokenKind::Ident, '方法名')->lit;
            $params = $this->parseParamList();
            $ret = $this->match(TokenKind::Colon) ? $this->parseTypeRef() : null;
            $this->expect(TokenKind::Semicolon, "';'（接口方法只有签名，没有方法体）");
            $methods[] = new InterfaceMethod($mname, $params, $ret);
        }
        $this->expect(TokenKind::Rbrace, "'}'");
        $decl = new InterfaceDecl($nameTok->lit, $extends, $methods);
        $decl->pos = $nameTok->pos;
        return $decl;
    }

    /** 顶层常量：const [TYPE] NAME = 字面量;（类型注解可选，与旧版一致）。 */
    private function parseConstRest(): ConstDecl
    {
        $kwTok = $this->peek();
        $typeRef = $this->peekIsConstTypeStart() ? $this->parseTypeRef() : null;
        $nameTok = $this->expect(TokenKind::Ident, '常量名');
        $this->expect(TokenKind::Eq, "'='");
        $value = $this->parseUnary(); // 字面量 + 一元（Checker 校验）
        $this->expect(TokenKind::Semicolon, "';'");
        $decl = new ConstDecl($nameTok->lit, $typeRef, $value);
        $decl->pos = $kwTok->pos;
        return $decl;
    }

    /** const 声明处是否有类型注解：内置类型关键字或 c.* 别名（Ident . Ident）。 */
    private function peekIsConstTypeStart(): bool
    {
        $kind = $this->peekKind();
        if (in_array($kind, [TokenKind::KwInt, TokenKind::KwFloat, TokenKind::KwDouble,
            TokenKind::KwBool, TokenKind::KwString], true)) {
            return true;
        }
        return $kind === TokenKind::Ident && $this->peekKindAt(1) === TokenKind::Dot;
    }

    private function parseFunctionRest(?string $exportName = null): object
    {
        $nameTok = $this->expect(TokenKind::Ident, '函数名');
        $params = $this->parseParamList();
        $ret = $this->match(TokenKind::Colon) ? $this->parseTypeRef() : null;
        $body = $this->parseBracedBlock();
        $decl = new FunctionDecl($nameTok->lit, $params, $ret, $body, $exportName);
        $decl->pos = $nameTok->pos;
        return $decl;
    }

    /** @return list<Param> */
    private function parseParamList(): array
    {
        $this->expect(TokenKind::Lparen, "'('");
        $params = [];
        if (!$this->is(TokenKind::Rparen)) {
            while (true) {
                $typeRef = $this->parseTypeRef();
                $name = substr($this->expect(TokenKind::Var, '参数名')->lit, 1);
                $hasDefault = false;
                $default = null;
                if ($this->match(TokenKind::Eq)) {
                    $hasDefault = true;
                    $default = $this->parseExpr();
                }
                $params[] = new Param($typeRef, $name, $hasDefault, $default);
                if (!$this->match(TokenKind::Comma)) {
                    break;
                }
            }
        }
        $this->expect(TokenKind::Rparen, "')'");
        return $params;
    }

    private function parseClassRest(): object
    {
        $nameTok = $this->expect(TokenKind::Ident, '类名');
        $name = $nameTok->lit;
        $extends = $this->match(TokenKind::KwExtends)
            ? $this->resolveClassName($this->parseQualifiedName())
            : null;
        $implements = [];
        if ($this->match(TokenKind::KwImplements)) {
            do {
                $implements[] = $this->resolveClassName($this->parseQualifiedName());
            } while ($this->match(TokenKind::Comma));
        }
        $this->expect(TokenKind::Lbrace, "'{'");

        $props = [];
        $methods = [];
        $classConsts = [];
        while (!$this->is(TokenKind::Rbrace) && !$this->is(TokenKind::Eof)) {
            if ($this->is(TokenKind::DirExport)) {
                $this->errHere('#[export] 仅全局函数有效，不能标注类成员');
                $this->next();
                continue;
            }
            $vis = 'public';
            if ($this->match(TokenKind::KwPublic)) {
                $vis = 'public';
            } elseif ($this->match(TokenKind::KwPrivate)) {
                $vis = 'private';
            } elseif ($this->match(TokenKind::KwProtected)) {
                $vis = 'protected';
            }
            $isStatic = $this->match(TokenKind::KwStatic);

            if ($this->match(TokenKind::KwConst)) {
                // 类常量：类型必填（与旧版一致）
                $typeRef = $this->parseTypeRef();
                $cnameTok = $this->expect(TokenKind::Ident, '常量名');
                $this->expect(TokenKind::Eq, "'='");
                $value = $this->parseExpr();
                $this->expect(TokenKind::Semicolon, "';'");
                $cc = new ClassConstDecl($vis, $typeRef, $cnameTok->lit, $value);
                $cc->pos = $typeRef->pos;
                $classConsts[] = $cc;
                continue;
            }

            if ($this->match(TokenKind::KwFunction)) {
                $mnameTok = $this->expect(TokenKind::Ident, '方法名');
                $params = $this->parseParamList();
                $ret = $this->match(TokenKind::Colon) ? $this->parseTypeRef() : null;
                $body = $this->parseBracedBlock();
                $method = new ClassMethod($vis, $isStatic, $mnameTok->lit, $params, $ret, $body);
                $method->pos = $mnameTok->pos;
                $methods[] = $method;
                continue;
            }

            $typeRef = $this->parseTypeRef();
            $pname = substr($this->expect(TokenKind::Var, '属性名')->lit, 1);
            $hasDefault = false;
            $default = null;
            if ($this->match(TokenKind::Eq)) {
                $hasDefault = true;
                $default = $this->parseExpr();
            }
            $this->expect(TokenKind::Semicolon, "';'");
            $prop = new ClassProp($vis, $isStatic, $typeRef, $pname, $hasDefault, $default);
            $prop->pos = $typeRef->pos;
            $props[] = $prop;
        }
        $this->expect(TokenKind::Rbrace, "'}'");
        $decl = new ClassDecl($name, $extends, $props, $methods, $classConsts, $implements);
        $decl->pos = $nameTok->pos;
        return $decl;
    }

    /** 类型闭合格：array<array<int>> 的 >> 需要拆成两个 >。 */
    private function expectTypeGt(): void
    {
        if ($this->is(TokenKind::Gt)) {
            $this->next();
            return;
        }
        if ($this->is(TokenKind::Shr)) {
            // 把 >> 拆开：替换为单个 >，不前进——下一层闭合适用它
            $t = $this->peek();
            $this->toks[$this->i] = new Token(TokenKind::Gt, '>', $t->pos);
            return;
        }
        $this->expect(TokenKind::Gt, "'>'");
    }

    /** 类型引用：内置类型 / array<T> / callable / void / c.* 别名 / 类名。 */
    private function parseTypeRef(): TypeRef
    {
        $t = $this->peek();
        $kind = $t->kind;
        $simple = match ($kind) {
            TokenKind::KwInt => 'int',
            TokenKind::KwFloat => 'float',
            TokenKind::KwDouble => 'double',
            TokenKind::KwBool => 'bool',
            TokenKind::KwString => 'string',
            TokenKind::KwCallable => 'callable',
            TokenKind::KwVoid => 'void',
            TokenKind::KwSelf => 'self', // : self 链式返回（Checker 解析为声明类）
            TokenKind::KwNull => 'null', // null 类型 = C 的 void*
            default => null,
        };
        if ($simple !== null) {
            $this->next();
            return new TypeRef($simple)->withPos($t->pos);
        }

        if ($kind === TokenKind::KwArray) {
            $this->next();
            $this->expect(TokenKind::Lt, "'<'（array<T> 必须指定元素类型）");
            $elem = $this->parseTypeRef();
            $this->expectTypeGt();
            return new TypeRef('array', $elem)->withPos($t->pos);
        }

        if ($kind === TokenKind::KwMap) {
            $this->next();
            $this->expect(TokenKind::Lt, "'<'（map<K,V> 必须指定键与值类型）");
            $key = $this->parseTypeRef();
            $this->expect(TokenKind::Comma, "','（map<K,V> 的键与值类型以逗号分隔）");
            $val = $this->parseTypeRef();
            $this->expectTypeGt();
            return new TypeRef('map', $val, false, $key)->withPos($t->pos);
        }

        if ($kind === TokenKind::Ident) {
            $this->next();
            $name = $t->lit;
            // self 返回类型：保留原名，Checker 解析为当前类（: self 链式返回）
            if ($name === 'self') {
                return new TypeRef('self')->withPos($t->pos);
            }
            $hasBackslash = false;
            while ($this->is(TokenKind::Backslash)) {
                $this->next();
                $name .= '\\' . $this->expect(TokenKind::Ident, '名字')->lit;
                $hasBackslash = true;
            }
            while ($this->is(TokenKind::Dot)) {
                $save = $this->i;
                $this->next();
                if (!$this->is(TokenKind::Ident)) {
                    $this->i = $save;
                    break;
                }
                $name .= '.' . $this->next()->lit;
            }
            // c.* 别名（c.i8）不做命名空间解析
            $hasDot = str_contains($name, '.');
            $ref = new TypeRef($hasBackslash || $hasDot ? $name : $this->resolveClassName($name));
            // 指针后缀：c.char* / Color*（仅类型上下文）
            while ($this->match(TokenKind::Star)) {
                $ref = new TypeRef($ref->name, $ref->elem, true);
            }
            return $ref->withPos($t->pos);
        }

        $this->errHere('期望类型');
        $this->next();
        return new TypeRef('<error>')->withPos($t->pos);
    }
}
