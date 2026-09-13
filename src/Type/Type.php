<?php

declare(strict_types=1);

namespace Tphp\Type;

/**
 * 编译期类型编码（u32 编码设计）。
 *
 * 一个类型就是一个 int：低段是类型索引，索引的具体信息（种类、
 * C 类型名、数组元素）全部由 Table 统一持有——这是整个编译器
 * 关于"类型是什么"的唯一事实来源，不设第二处平行定义。
 */
final class Type
{
    /** 未解析 / 无效 */
    public const NONE = 0;

    // 内置类型的固定索引
    public const I_VOID = 1;
    public const I_INT = 2;       // int32_t
    public const I_FLOAT = 3;     // c.f32（32 位；C 类型 float）
    public const I_DOUBLE = 4;    // float（64 位，PHP 语义；'double' 为别名；C 类型 double）
    public const I_BOOL = 5;
    public const I_STRING = 6;    // String 值类型（SSO）
    public const I_ARRAY = 7;     // Array*（真正的 array<T> 码由 Table 分配）
    public const I_CALLABLE = 8;
    public const I_NULL = 9;      // null 字面量的类型，只能赋给引用类型
    public const I_CVAL = 10;     // c-> 调用/常量的返回：C 侧值（类型由程序员保证）
    public const I_OBJECT = 11;   // object：裸对象指针 TphpObjHead*（未收窄处不可成员访问）

    /** 类类型索引从这里开始分配 */
    public const I_CLASS_BASE = 16;

    /** c.* 别名索引从这里开始分配 */
    public const I_CTYPE_BASE = 256;

    /**
     * doc/type.md 的 c.* 定宽类型 → C 类型名单一映射。
     * 键即语言中的类型名。
     */
    public const C_ALIASES = [
        'c.i8' => 'int8_t',
        'c.u8' => 'uint8_t',
        'c.i16' => 'int16_t',
        'c.u16' => 'uint16_t',
        'c.i32' => 'int32_t',
        'c.u32' => 'uint32_t',
        'c.i64' => 'int64_t',
        'c.u64' => 'uint64_t',
        'c.i128' => '__int128',
        'c.u128' => 'unsigned __int128',
        'c.char' => 'char',
        'c.short' => 'short',
        'c.ushort' => 'unsigned short',
        'c.uint' => 'unsigned int',
        'c.long' => 'long',
        'c.ulong' => 'unsigned long',
        'c.longlong' => 'long long',
        'c.ulonglong' => 'unsigned long long',
        'c.longdouble' => 'long double',
        'c.f16' => '_Float16',
        'c.f80' => 'long double',
        'c.f128' => '_Float128',
        'c.f64' => 'double',
        'c.ptr' => 'void*',
    ];

    /** 整数族 c.* 别名（参与整数运算规则）。 */
    public const C_INT_ALIASES = [
        'c.i8', 'c.u8', 'c.i16', 'c.u16', 'c.i32', 'c.u32', 'c.i64', 'c.u64',
        'c.i128', 'c.u128', 'c.char', 'c.short', 'c.ushort', 'c.uint',
        'c.long', 'c.ulong', 'c.longlong', 'c.ulonglong',
    ];

    /** 浮点族 c.* 别名。 */
    public const C_FLOAT_ALIASES = ['c.f16', 'c.f32', 'c.f64', 'c.f80', 'c.f128', 'c.longdouble'];

    /**
     * 代码 → 语言显示名（displayName 的单一来源）。
     *
     * 浮点分层对齐 PHP 语义：float = 64 位（f64），'double' 为其别名；
     * 32 位浮点是 C 侧存储类型，语言名归入 c.* 位宽命名（c.f32）。
     */
    public const BUILTIN_NAMES = [
        self::I_VOID => 'void',
        self::I_INT => 'int',
        self::I_FLOAT => 'c.f32',
        self::I_DOUBLE => 'float',
        self::I_BOOL => 'bool',
        self::I_STRING => 'string',
        self::I_ARRAY => 'array',
        self::I_CALLABLE => 'callable',
        self::I_NULL => 'null',
        self::I_OBJECT => 'object',
    ];

    /** 命名空间分隔符内联进 C 符号：Lib\Calc → Lib_Calc。 */
    public static function mangleName(string $name): string
    {
        return str_replace('\\', '_', $name);
    }
}
