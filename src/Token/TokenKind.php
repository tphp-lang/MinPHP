<?php

declare(strict_types=1);

namespace Tphp\Token;

/**
 * 词法单元种类（单一枚举）。
 *
 * 关键字与类型名都是独立 case，杜绝旧版把内置函数名硬编码进词法层的做法。
 */
enum TokenKind
{
    case Eof;
    case Unknown;

    // phpc 指令（# 行首指令）
    case DirInclude;  // #include <...> / #include "..."（原文含尖括号或引号）
    case DirFlag;     // #flag -l...（行内参数原文）
    case DirImport;   // #import <包名>（自研标准库扩展）
    case DirStruct;   // #struct（结构体体走正常 token 流）
    case DirIf;       // #if <条件>（条件原文：os/arch/cc 名，可 ! 取反）
    case DirElif;     // #elif <条件>
    case DirElse;     // #else
    case DirEndif;    // #endif
    case DirExport;   // #[export("c_name")]（lit 为 C 符号名，仅全局函数）
    case DirEnum;     // #enum Name { A = 1, B }（C 枚举常量集，成员注册为 c.i32 常量）

    // 字面量与名字
    case IntLit;
    case FloatLit;
    case StrLit;      // '...' 单引号，原始内容
    case DStrLit;     // "..." 双引号，原始内容（可能含插值）
    case NowdocLit;   // <<<'EOT' nowdoc，原文内容（无转义、无插值）
    case Ident;       // 名字（函数/类/类型别名 c.*）
    case Var;         // $name

    // 关键字 — 控制流
    case KwIf;
    case KwElseIf;
    case KwElse;
    case KwWhile;
    case KwDo;
    case KwFor;
    case KwForeach;
    case KwAs;
    case KwSwitch;
    case KwCase;
    case KwDefault;
    case KwBreak;
    case KwContinue;
    case KwReturn;

    // 关键字 — 声明
    case KwFunction;
    case KwFn;      // fn(...) => expr 箭头闭包
    case KwClass;
    case KwConst;
    case KwExtends;
    case KwInterface;
    case KwEnum;      // enum Name [: int|string] { case X = v; ... } 枚举类（KwCase 复用 switch 的 case）
    case KwImplements;
    case KwNamespace;
    case KwUse;
    case KwNew;
    case KwThis;
    case KwSelf;
    case KwParent;
    case KwPublic;
    case KwPrivate;
    case KwProtected;
    case KwStatic;

    // 关键字 — 字面量 / 语句
    case KwTrue;
    case KwFalse;
    case KwNull;
    case KwEcho;
    case KwThrow;
    case KwOr;

    // 类型关键字
    case KwInt;
    case KwFloat;
    case KwDouble;
    case KwBool;
    case KwString;
    case KwArray;
    case KwFinal;
    case KwAbstract;
    case KwInstanceof;
    case KwTrait;
    case KwInsteadof;
    case KwMap;
    case KwCallable;
    case KwVoid;

    // 标点
    case Lparen;
    case Rparen;
    case Lbracket;
    case Rbracket;
    case Lbrace;
    case Rbrace;
    case Comma;
    case Semicolon;
    case Backslash;   // \ 命名空间分隔符
    case Dot;
    case Arrow;        // ->
    case DoubleColon;  // ::
    case Question;
    case Colon;
    case FatArrow;     // =>

    // 运算符
    case Plus;
    case Minus;
    case Star;
    case Slash;
    case Percent;
    case Pow;          // **
    case Eq;
    case EqEq;
    case NotEq;
    case Lt;
    case Gt;
    case LtEq;
    case GtEq;
    case AndAnd;
    case OrOr;
    case Not;
    case Amp;
    case Pipe;
    case Caret;
    case Tilde;
    case Shl;
    case Shr;
    case Inc;
    case Dec;
    case PlusEq;
    case MinusEq;
    case StarEq;
    case SlashEq;
    case PercentEq;
    case PowEq;
    case DotEq;
    case AmpEq;
    case PipeEq;
    case PipeRight;  // |> 管道：左值插入右侧调用首参
    case Ellipsis;   // ... 管道占位符（仅管道右侧调用参数中合法）
    case CaretEq;
    case ShlEq;
    case ShrEq;

    /** 是否可作为类型声明的起始 Token。 */
    public function isTypeStart(): bool
    {
        return match ($this) {
            self::KwInt, self::KwFloat, self::KwDouble, self::KwBool, self::KwString,
            self::KwArray, self::KwMap, self::KwCallable, self::KwVoid, self::KwNull, self::Ident => true,
            default => false,
        };
    }
}
