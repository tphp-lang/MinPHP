<?php

// abstract 类与抽象方法：不可实例化，子类必须实现；多态经抽象类类型分发
// 覆盖：抽象构造器（PHP 允许）、中间抽象类、具体方法继承、final 常量与属性
// expect:
// area=78.5
// area=12
// 合计=90.5
// sound=wang
// base:dog
// max=99
// v=1

abstract class Shape
{
    protected string $name;

    // 抽象构造器：子类必须给出实现（PHP 语义）
    abstract public function __construct(string $name);

    // 抽象方法：无函数体，子类必须实现
    abstract public function area(): double;

    // 抽象类可含具体方法
    public function describe(): string
    {
        return $this->name;
    }
}

class Circle extends Shape
{
    private double $r;

    public function __construct(string $name, double $r)
    {
        $this->name = $name;
        $this->r = $r;
    }

    public function area(): double
    {
        return 3.14 * $this->r * $this->r;
    }
}

class Rect extends Shape
{
    private double $w;
    private double $h;

    public function __construct(double $w, double $h)
    {
        $this->name = "rect";
        $this->w = $w;
        $this->h = $h;
    }

    public function area(): double
    {
        return $this->w * $this->h;
    }
}

// 中间抽象类：仍不实现抽象方法，合法
abstract class Base extends Shape
{
    public function label(): string
    {
        return "base:" . $this->describe();
    }
}

class Dog extends Base
{
    public function __construct()
    {
        $this->name = "dog";
    }

    public function area(): double
    {
        return 0;
    }

    public function sound(): string
    {
        return "wang";
    }
}

// final 类常量（PHP 8.1+）与 final 属性（PHP 8.4+）：
// 本语言常量不可被子类重定义、属性禁止遮蔽，语义天然满足
final class Cfg
{
    final const int MAX = 99;
    final public int $v = 1;
}

class Main
{
    public function main(): void
    {
        // 抽象类类型变量持有子类实例：vtable 分发到各实现
        array<Shape> $shapes = [];
        $shapes[] = new Circle("c", 5.0);
        $shapes[] = new Rect(3.0, 4.0);
        double $total = 0.0;
        foreach ($shapes as $s) {
            echo "area=", $s->area(), "\n";
            $total = $total + $s->area();
        }
        echo "合计=", $total, "\n";

        Dog $d = new Dog();
        echo "sound=", $d->sound(), "\n";
        echo $d->label(), "\n";

        echo "max=", Cfg::MAX, "\n";
        Cfg $c = new Cfg();
        echo "v=", $c->v, "\n";
    }
}
