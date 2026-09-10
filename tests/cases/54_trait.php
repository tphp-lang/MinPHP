<?php

// trait：编译期展开（方法/属性/抽象方法/嵌套 use/类优先）
// expect:
// hi rob
// bye rob
// unit:r2
// HELLO alice
// alice

trait Greet
{
    public string $who = "";

    public function hi(): string
    {
        return "hi " . $this->who;
    }
}

trait Unit
{
    abstract public function unit(): string;

    public function describe(): string
    {
        return "unit:" . $this->unit();
    }
}

trait Extra
{
    use Greet; // 嵌套 use

    public function bye(): string
    {
        return "bye " . $this->who;
    }
}

class Robot
{
    use Extra, Unit;

    public function __construct()
    {
        $this->who = "rob";
    }

    public function unit(): string
    {
        return "r2";
    }
}

class Human
{
    use Greet;

    public function __construct()
    {
        $this->who = "alice";
    }

    // 类自身方法覆盖 trait 方法
    public function hi(): string
    {
        return "HELLO " . $this->who;
    }
}

class Main
{
    public function main(): void
    {
        Robot $r = new Robot();
        echo $r->hi(), "\n";
        echo $r->bye(), "\n";
        echo $r->describe(), "\n";

        Human $h = new Human();
        echo $h->hi(), "\n";
        echo $h->who, "\n";
    }
}
