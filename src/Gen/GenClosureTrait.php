<?php

declare(strict_types=1);

namespace Tphp\Gen;

use Tphp\Ast\Expr;
use Tphp\Ast\expr\CallExpr;
use Tphp\Ast\expr\ClosureExpr;
use Tphp\Ast\expr\InvokeExpr;
use Tphp\Ast\expr\VarExpr;
use Tphp\Table\VarSymbol;
use Tphp\Type\Type;

/**
 * 闭包生成（doc/closure.md）：
 *
 *   捕获环境 struct  → typedefs 节
 *   thunk / env dtor → closures 节（原型进 protos）
 *   创建点           → 语句表达式：tphp_env_alloc + 填充 + (Callable){fn, env}
 *   引用捕获         → 堆盒子：变量存储提升，内外读写都解引用
 *
 * 捕获上下文栈（capCtx）负责闭包体内的变量重映射：
 *   按值捕获名   → ((ENV*)__env)-><f>
 *   引用捕获名   → (*((T*)((ENV*)__env)-><f>_box))
 *   $this        → ((ENV*)__env)->self
 */
trait GenClosureTrait
{
    /** @var list<array{env: string, map: array<string, array{c: string}>, autoThis: bool}> */
    private array $capCtx = [];

    /** 当前闭包的 C 变量名（thunk 的 env 参数名；与用户名隔离用双下划线前缀）。 */
    private const ENV_PARAM = '__env';

    // ------------------------------------------------------------------ 变量重映射

    /** 闭包捕获 / 盒子变量的 C 读取文本；null = 普通变量。 */
    private function capLookup(string $phpName): ?string
    {
        for ($i = count($this->capCtx) - 1; $i >= 0; $i--) {
            if (isset($this->capCtx[$i]['map'][$phpName])) {
                return $this->capCtx[$i]['map'][$phpName]['c'];
            }
        }
        return null;
    }

    /** 变量是否是引用盒子（外层函数内的重映射）。 */
    private static function isBoxedSym(?object $sym): bool
    {
        return $sym instanceof VarSymbol && $sym->boxed;
    }

    // ------------------------------------------------------------------ 创建点

    private function genClosureExpr(ClosureExpr $e): string
    {
        $id = $e->closureId ??= $this->closureSeq++;
        $envName = '_env_' . $id;
        $thunk = 'tphp_closure_' . $id;
        $dtor = '_env_dtor_' . $id;

        $retC = $this->cType($e->sig['ret']);
        $paramCs = [];
        foreach ($e->sig['params'] as $pt) {
            $paramCs[] = $this->cType($pt);
        }

        // 1) env struct → typedefs
        $fields = '';
        foreach ($e->resolvedCaptures as $i => $c) {
            $ct = $this->cType($c['type']);
            $fname = $c['name'] === 'this' ? 'self' : 'c' . $i;
            $fields .= '    ' . $ct . ($c['byRef'] ? '* ' : ' ') . $fname . ";\n";
        }
        $this->sections['typedefs'] .= "typedef struct {\n" . $fields . '} ' . $envName . ";\n\n";

        // 2) dtor（堆类型按值捕获才需要）→ closures 节；原型 → protos
        $heapCaps = array_filter($e->resolvedCaptures, fn (array $c) => !$c['byRef'] && $this->isHeapType($c['type']));
        $dtorName = $heapCaps === [] ? 'NULL' : $dtor;
        if ($heapCaps !== []) {
            $body = '    ' . $envName . '* e = (' . $envName . '*)p;' . "\n";
            foreach ($heapCaps as $i => $c) {
                $fname = $c['name'] === 'this' ? 'self' : 'c' . $i;
                $body .= '    ' . $this->rcUnrefText('e->' . $fname, $c['type']) . ";\n";
            }
            $this->sections['protos'] .= 'static void ' . $dtor . '(void *p);' . "\n";
            $this->sections['closures'] .= "static void " . $dtor . "(void *p)\n{\n" . $body . "}\n\n";
        }

        // 3) thunk 原型 → protos；定义在遍历到闭包体时写入 closures 节
        // 约定：环境指针是最后一个参数（与 invoke 的函数指针强转类型一致）
        $plist = '';
        foreach ($e->sig['params'] as $i => $pt) {
            $plist .= ($i > 0 ? ', ' : '') . $this->cType($pt) . ' ' . Names::localVar($e->params[$i]->name);
        }
        $plist .= ($plist !== '' ? ', ' : '') . $envName . '* ' . self::ENV_PARAM;
        $this->sections['protos'] .= 'static ' . $retC . ' ' . $thunk . '(' . $plist . ");\n";

        // 4) thunk 定义（嵌套在外层函数生成中：全部生成状态先存后还）
        $savedCtx = $this->capCtx;
        $savedScopes = $this->rcScopes;
        $savedReleases = $this->rcStmtReleases;
        $savedDepthGuard = $this->curDepthGuard;
        $savedIndent = $this->indent;
        $savedRet = $this->curRet;
        $savedClass = $this->curClassSym;
        $savedCur = $this->cur;
        $savedFile = $this->curFile;
        $map = [];
        foreach ($e->resolvedCaptures as $i => $c) {
            $fname = $c['name'] === 'this' ? 'self' : 'c' . $i;
            $map[$c['name']] = ['c' => $c['byRef']
                ? '(*(' . $this->cType($c['type']) . '*)(' . self::ENV_PARAM . '->' . $fname . '))'
                : '(' . self::ENV_PARAM . '->' . $fname . ')'];
        }
        $this->capCtx[] = ['env' => $envName, 'map' => $map];

        $this->sections['closures'] .= 'static ' . $retC . ' ' . $thunk . '(' . $plist . ")\n{\n";
        $this->cur = 'closures'; // thunk 体写入 closures 节（w() 按当前节落笔）
        $this->curFile = $e->pos?->file ?? '';
        $this->indent = 1;
        $this->curRet = $e->sig['ret'];
        $this->curClassSym = null;
        $this->sourceLine($e->pos);
        $this->curDepthGuard = true; // thunk 静态不可分析，恒插深度保护
        $this->rcScopes = []; // thunk 的 RC 栈独立（否则 rcCleanupReturn 会清理外层函数的变量）
        $this->rcScopeBegin('closure');
        if ($this->curDepthGuard) {
            $this->w('tphp_depth_enter();');
        }
        $this->w('size_t __cmem = tphp_cmem_mark();');
        foreach ($e->sig['params'] as $i => $pt) {
            $cName = Names::localVar($e->params[$i]->name);
            $this->rcDeclareLocal($cName, $pt);
            if ($this->isHeapType($pt)) {
                $this->rcRefStmt($cName, $pt);
            }
        }
        foreach ($e->resolvedCaptures as $c) {
            if ($c['name'] === 'this') {
                continue;
            }
            $this->rcDeclareLocal(Names::localVar($c['name']), $c['type'], true);
        }
        $this->genBodyStmts($e->body);
        $this->rcCleanupReturn();
        $this->emitFallbackReturn($e->sig['ret']);
        $this->sections['closures'] .= "}\n\n";
        $this->capCtx = $savedCtx;
        $this->rcScopes = $savedScopes;
        $this->rcStmtReleases = $savedReleases;
        $this->indent = $savedIndent;
        $this->curRet = $savedRet;
        $this->curClassSym = $savedClass;
        $this->cur = $savedCur;
        $this->curFile = $savedFile;
        $this->curDepthGuard = $savedDepthGuard;

        // 5) 创建表达式
        $init = '';
        foreach ($e->resolvedCaptures as $i => $c) {
            $fname = $c['name'] === 'this' ? 'self' : 'c' . $i;
            if ($c['name'] === 'this') {
                $src = $this->genThis();
            } else {
                $src = Names::localVar($c['name']);
            }
            if ($c['byRef']) {
                $init .= '    ' . self::ENV_PARAM . '->' . $fname . ' = ' . $src . "_box;\n";
            } else {
                // 捕获源恒为借用（按名捕获）：堆类型需要自己的引用
                $init .= $this->isHeapType($c['type'])
                    ? '    ' . self::ENV_PARAM . '->' . $fname . ' = (' . $this->rcRefText($src, $c['type']) . ', ' . $src . ");\n"
                    : '    ' . self::ENV_PARAM . '->' . $fname . ' = ' . $src . ";\n";
            }
        }
        return '({ ' . $envName . '* ' . self::ENV_PARAM . ' = (' . $envName
            . '*)tphp_env_alloc(sizeof(' . $envName . '), ' . $dtorName . ");\n"
            . $init
            . '    (Callable){ .fn = (void*)' . $thunk . ', .env = ' . self::ENV_PARAM . " };\n})";
    }

    // ------------------------------------------------------------------ C 回调桥

    /**
     * c_fn($closure)：生成 C 可调用的 trampoline + 静态槽。
     * 约定：C 回调签名为 RET cb(PARAMS..., void* userdata)——userdata 形参存在但被
     * trampoline 忽略（捕获数据经静态槽传递，一个 c_fn 调用点一个槽，进程期存活）。
     */
    private function genCfn(CallExpr $e): string
    {
        $sig = $e->closureSig;
        if ($sig === null) {
            return '(void*)0';
        }
        $id = $this->closureSeq++;
        $tramp = 'tphp_cfn_' . $id;
        $slot = 'tphp_cfn_slot_' . $id;
        $retC = $this->cType($sig['ret']);
        $paramCs = [];
        foreach ($sig['params'] as $pt) {
            $paramCs[] = $this->cType($pt);
        }
        $plist = '';
        foreach ($paramCs as $i => $ct) {
            $plist .= ($i > 0 ? ', ' : '') . $ct . ' p' . $i;
        }
        $plist .= ($plist !== '' ? ', ' : '') . 'void* ud';
        $this->sections['protos'] .= 'static ' . $retC . ' ' . $tramp . '(' . $plist . ");\n";
        $this->sections['globals'] .= 'static Callable* ' . $slot . " = NULL;\n";

        $fptr = $retC . ' (*)(' . implode(', ', $paramCs)
            . (count($paramCs) > 0 ? ', ' : '') . 'void*)';
        $fwdArgs = implode(', ', array_map(fn ($i) => 'p' . $i, array_keys($paramCs)))
            . (count($paramCs) > 0 ? ', ' : '') . $slot . '->env';
        $fwd = $sig['ret'] === Type::I_VOID
            ? '((' . $fptr . ')' . $slot . '->fn)(' . $fwdArgs . ');'
            : 'return ((' . $fptr . ')' . $slot . '->fn)(' . $fwdArgs . ');';
        $this->sections['closures'] .= "static " . $retC . " " . $tramp . "(" . $plist . ")\n{\n"
            . "    " . $fwd . "\n}\n\n";

        return '({ ' . $slot . ' = (Callable*)tphp_env_alloc(sizeof(Callable), NULL);'
            . ' *' . $slot . ' = ' . $this->genExpr($e->args[0]) . '; (void*)' . $tramp . '; })';
    }

    // ------------------------------------------------------------------ 调用点

    private function genInvoke(InvokeExpr $e): string
    {
        $sig = $e->sig;
        $callee = $e->callee instanceof VarExpr
            ? $this->varReadText($e->callee)
            : $this->genExpr($e->callee);
        $args = $this->genArgs($e->args, $sig['params']);
        $retC = $this->cType($sig['ret']);
        $fptr = $retC . ' (*)(' . implode(', ', array_map(fn (int $t) => $this->cType($t), $sig['params']))
            . (count($sig['params']) > 0 ? ', ' : '') . 'void*)';
        if ($sig['ret'] === Type::I_VOID) {
            return '({ Callable __iv = ' . $callee . '; ((' . $fptr . ')__iv.fn)(' . $args . (count($sig['params']) > 0 ? ', ' : '') . '__iv.env); })';
        }
        return '({ Callable __iv = ' . $callee . '; ' . $retC . ' __cr = ((' . $fptr . ')__iv.fn)('
            . $args . (count($sig['params']) > 0 ? ', ' : '') . '__iv.env); __cr; })';
    }

    // ------------------------------------------------------------------ 盒子

    /** 函数入口：被引用捕获的参数落地为堆盒子（读写经解引用，所有权不归本作用域）。 */
    private function genBoxedParams(\Tphp\Table\FnSymbol $fn): void
    {
        foreach ($fn->params as $param) {
            if (!$param->boxed) {
                continue;
            }
            foreach ($this->genBoxDecl($param->name, $param->type) as $line) {
                $this->w($line);
            }
            $this->rcDeclareLocal(Names::localVar($param->name), $param->type, true);
        }
    }

    /**
     * 引用捕获变量的盒子声明行（LocalDecl / 参数入口用）。
     * @return list<string>
     */
    private function genBoxDecl(string $phpName, int $type): array
    {
        $lines = [];
        $box = Names::localVar($phpName) . '_box';
        $ct = $this->cType($type);
        $dtor = 'NULL';
        if ($this->isHeapType($type)) {
            $id = $this->closureSeq++;
            $dtor = '_box_dtor_' . $id;
            $unref = str_replace(
                '__X__',
                '*(' . $ct . '*)p',
                $this->rcUnrefText('__X__', $type),
            );
            $this->sections['protos'] .= 'static void ' . $dtor . '(void *p);' . "\n";
            $this->sections['closures'] .= "static void " . $dtor . "(void *p)\n{\n    " . $unref . ";\n}\n\n";
        }
        $lines[] = $ct . '* ' . $box . ' = (' . $ct . '*)tphp_env_alloc(sizeof(' . $ct . '), ' . $dtor . ');';
        return $lines;
    }
}
