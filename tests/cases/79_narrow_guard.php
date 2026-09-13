<?php

// 守卫子句收窄 + 接口变量收窄为具体类（.obj 解包路径）
// expect:
// wang
// 6

interface Shape
{
    public function area(): int;
}

class Rect implements Shape
{
    public int $w;
    public int $h;

    public function __construct(int $w, int $h)
    {
        $this->w = $w;
        $this->h = $h;
    }

    public function area(): int
    {
        return $this->w * $this->h;
    }
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

// 守卫子句：父类形参 → 子类
function speak(Animal $a): string
{
    if (!($a instanceof Dog)) {
        return "none";
    }
    return $a->sound();
}

// 守卫子句：接口形参 → 具体类（读取需 .obj 解包）
function rectArea(Shape $s): int
{
    if (!($s instanceof Rect)) {
        return 0;
    }
    return $s->area();
}

class Main
{
    public function main(): void
    {
        echo speak(new Dog()), "\n";          // wang
        echo rectArea(new Rect(2, 3)), "\n";  // 6
    }
}
