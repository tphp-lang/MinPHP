<?php

declare(strict_types=1);

namespace Tphp\Gen;

use Tphp\Type\Type;

/**
 * C 符号命名规则（固定、可预测）：
 *
 *   函数        tphp_<name>
 *   类 struct   tphp_class_<Name>
 *   vtable      tphp_vt_<Name> / 实例 tphp_vti_<Name>
 *   方法        tphp_class_<Name>_<method>
 *   静态属性    tphp_static_<Name>_<prop>
 *   构造辅助    tphp_new_<Name>
 *   常量宏      TPHP_CONST_<NAME> / TPHP_CONST_<CLASS>_<NAME>
 *   接口 itab   tphp_itab_<Iface> / 实例 tphp_itab_<Class>_<Iface>
 *
 * 命名空间分隔符 \ 内联为 _（Lib\Calc → Lib_Calc），编译器注册时查重。
 */
final class Names
{
    private const C_KEYWORDS = [
        'auto', 'break', 'case', 'char', 'const', 'continue', 'default', 'do',
        'double', 'else', 'enum', 'extern', 'float', 'for', 'goto', 'if',
        'inline', 'int', 'long', 'register', 'restrict', 'return', 'short',
        'signed', 'sizeof', 'static', 'struct', 'switch', 'typedef', 'union',
        'unsigned', 'void', 'volatile', 'while', '_Bool', '_Complex',
        '_Imaginary',
    ];

    /** 命名空间分隔符内联：Lib\Calc → Lib_Calc。 */
    public static function mangle(string $name): string
    {
        return Type::mangleName($name);
    }

    public static function fn(string $name): string
    {
        return 'tphp_' . self::mangle($name);
    }

    public static function classStruct(string $name): string
    {
        return 'tphp_class_' . self::mangle($name);
    }

    public static function vtableType(string $name): string
    {
        return 'tphp_vt_' . self::mangle($name);
    }

    public static function vtableInstance(string $name): string
    {
        return 'tphp_vti_' . self::mangle($name);
    }

    /** 祖先类 id 链数组（instanceof 元信息）。 */
    public static function typeChain(string $name): string
    {
        return 'tphp_tchain_' . self::mangle($name);
    }

    /** 实现接口 id 集数组（instanceof 元信息）。 */
    public static function typeIfaces(string $name): string
    {
        return 'tphp_tifaces_' . self::mangle($name);
    }

    public static function method(string $class, string $method): string
    {
        return 'tphp_class_' . self::mangle($class) . '_' . $method;
    }

    public static function staticProp(string $class, string $prop): string
    {
        return 'tphp_static_' . self::mangle($class) . '_' . $prop;
    }

    public static function newHelper(string $class): string
    {
        return 'tphp_new_' . self::mangle($class);
    }

    public static function itabType(string $iface): string
    {
        return 'tphp_itab_' . self::mangle($iface);
    }

    public static function itabInstance(string $class, string $iface): string
    {
        return 'tphp_itab_' . self::mangle($class) . '_' . self::mangle($iface);
    }

    /** 顶层常量宏名（带命名空间前缀）。 */
    public static function constMacro(string $name): string
    {
        return 'TPHP_CONST_' . strtoupper(self::mangle($name));
    }

    /** 类常量宏名。 */
    public static function classConstMacro(string $class, string $name): string
    {
        return 'TPHP_CONST_' . strtoupper(self::mangle($class)) . '_' . strtoupper($name);
    }

    /** PHP 变量名 → C 局部变量名（避开 C 关键字与 self）。 */
    public static function localVar(string $name): string
    {
        if (in_array($name, self::C_KEYWORDS, true) || $name === 'self') {
            return $name . '_v';
        }
        return $name;
    }

    /** 是否可直接作为 C 符号的标识符（#[export] 名称校验）。 */
    public static function validCIdentifier(string $name): bool
    {
        if ($name === '' || in_array($name, self::C_KEYWORDS, true)) {
            return false;
        }
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }
}
