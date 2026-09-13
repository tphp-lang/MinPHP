<?php

// unset($obj->prop)：释放并置空类实例的引用类型字段（类/数组/接口）
// expect:
// b-set: false
// ~Child:7
// b-null: true
// xs-len: 3
// xs-null: true
// it-name: c1
// ~Circle:c1
// it-null: true
// x-null: true y-null: true
// keep: 5
// ~Holder

interface Shape
{
    public function name(): string;
}

class Circle implements Shape
{
    public string $label;
    public function __construct(string $label)
    {
        $this->label = $label;
    }
    public function name(): string
    {
        return $this->label;
    }
    public function __destruct(): void
    {
        echo "~Circle:", $this->label, "\n";
    }
}

class Child
{
    public int $id;
    public function __construct(int $id)
    {
        $this->id = $id;
    }
    public function __destruct(): void
    {
        echo "~Child:", $this->id, "\n";
    }
}

class Holder
{
    public Child $b;
    public array<int> $xs;
    public Shape $it;
    public array<int> $x;
    public array<int> $y;
    public array<int> $keep;

    public function __destruct(): void
    {
        echo "~Holder\n";
    }
}

class Main
{
    public function main(): void
    {
        Holder $a = new Holder();

        // 类类型字段：赋子对象后 unset，字段置空且子对象引用归零触发 __destruct
        $a->b = new Child(7);
        echo "b-set: ", $a->b == null, "\n";
        unset($a->b);
        echo "b-null: ", $a->b == null, "\n";

        // array 字段
        $a->xs = [1, 2, 3];
        echo "xs-len: ", len($a->xs), "\n";
        unset($a->xs);
        echo "xs-null: ", $a->xs == null, "\n";

        // 接口类型字段
        $a->it = new Circle("c1");
        echo "it-name: ", $a->it->name(), "\n";
        unset($a->it);
        echo "it-null: ", $a->it == null, "\n";

        // 一次删除多个字段
        $a->x = [10];
        $a->y = [20, 30];
        unset($a->x, $a->y);
        echo "x-null: ", $a->x == null, " y-null: ", $a->y == null, "\n";

        // 未 unset 的字段保持原值
        $a->keep = [4, 5];
        echo "keep: ", $a->keep[1], "\n";
    }
}
