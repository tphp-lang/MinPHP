<?php

// trait 用在抽象类上（trait 提供字段与具体方法，抽象方法由子类实现）
// expect:
// impl#7


trait HasId
{
    protected int $id = 0;

    public function id(): int
    {
        return $this->id;
    }
}

abstract class Base
{
    use HasId;

    abstract public function kind(): string;

    public function label(): string
    {
        return $this->kind() . "#" . $this->id();
    }
}

class Impl extends Base
{
    public function __construct()
    {
        $this->id = 7;
    }

    public function kind(): string
    {
        return "impl";
    }
}

class Main
{
    public function main(): void
    {
        Impl $x = new Impl();
        echo $x->label(), "\n";
    }
}
