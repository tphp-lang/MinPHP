<?php

// object：顶层对象引用（裸对象指针 TphpObjHead *）
// expect:
// dog
// false
// true
// true

interface Tagged
{
}

abstract class Animal
{
    abstract public function sound(): string;
}

class Dog extends Animal implements Tagged
{
    public function sound(): string
    {
        return "wang";
    }
}

class Cat extends Animal
{
    public function sound(): string
    {
        return "miao";
    }
}

class Main
{
    public function main(): void
    {
        // 类实例 → object（零成本上转）
        object $o = new Dog();
        echo $o instanceof Dog ? "dog\n" : "no\n";       // 运行时 instanceof
        echo $o instanceof Cat ? "true\n" : "false\n";   // false

        // 接口值 → object（取胖指针的 .obj）
        Tagged $t = new Dog();
        object $o2 = $t;
        echo $o2 instanceof Dog ? "true\n" : "false\n";  // true

        // object 可空，且可与 null 比较
        object $nil = null;
        echo $nil == null ? "true\n" : "false\n";
    }
}
