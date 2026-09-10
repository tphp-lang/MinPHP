<?php

// instanceof：类层级 / 接口 / 枚举 / 编译期折叠
// expect:
// dog is Animal: true
// dog is Dog: true
// dog is Cat: false
// shape is Circle: true
// circle is Shape: true
// suit is Suit: true
// notAnimal: false

interface Shape
{
}

abstract class Animal
{
    abstract public function sound(): string;
}

class Dog extends Animal
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

class Circle implements Shape
{
    public double $r = 1.0;
}

enum Suit: string
{
    case Hearts = 'H';
}

class Main
{
    public function main(): void
    {
        Animal $a = new Dog();
        echo "dog is Animal: ", $a instanceof Animal ? "true" : "false", "\n";
        echo "dog is Dog: ", $a instanceof Dog ? "true" : "false", "\n";
        echo "dog is Cat: ", $a instanceof Cat ? "true" : "false", "\n";

        Shape $s = new Circle();
        echo "shape is Circle: ", $s instanceof Circle ? "true" : "false", "\n";
        echo "circle is Shape: ", $s instanceof Shape ? "true" : "false", "\n";

        echo "suit is Suit: ", Suit::Hearts instanceof Suit ? "true" : "false", "\n";

        // 编译期折叠：final 类与无继承关系 → 常量 false
        $a = new Cat();
        echo "notAnimal: ", $a instanceof Circle ? "true" : "false", "\n";
    }
}
