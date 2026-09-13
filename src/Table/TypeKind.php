<?php

declare(strict_types=1);

namespace Tphp\Table;

/** 类型条目的种类。 */
enum TypeKind
{
    case Builtin;
    case Ctype;       // c.* 别名，直接映射 C 原生标量
    case ArrayOf;     // array<T> 实例
    case ClassType;
    case InterfaceType;
    case CStruct;     // #struct 登记的 C 结构体（值语义）
    case CPointer;    // c.T* / X* 派生指针类型
    case MapType;     // map<K,V> 关联数组实例
    case Object;      // object：裸对象指针 TphpObjHead*
}
