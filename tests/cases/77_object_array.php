<?php

// array<object>：异构对象容器，元素槽为对象指针，引用计数随数组释放
// expect:
// 2
// dog
// cat
// close dog
// close cat
// done

abstract class Animal
{
    abstract public function name(): string;
}

class Dog extends Animal
{
    public function name(): string
    {
        return "dog";
    }

    public function __destruct(): void
    {
        echo "close dog\n";
    }
}

class Cat extends Animal
{
    public function name(): string
    {
        return "cat";
    }

    public function __destruct(): void
    {
        echo "close cat\n";
    }
}

class Bag
{
    public array<object> $items;
}

class Main
{
    public function main(): void
    {
        Bag $b = new Bag();
        $b->items = [new Dog(), new Cat()];

        echo len($b->items), "\n";

        // 取出后 instanceof 收窄：元素读取是借用，绑定到局部变量需持有自己的引用
        {
            object $first = $b->items[0];
            echo $first instanceof Dog ? "dog\n" : "?\n";
        }
        {
            object $second = $b->items[1];
            echo $second instanceof Cat ? "cat\n" : "?\n";
        }

        // 释放数组字段：逐元素 unref，对象引用归零触发 __destruct（下标顺序）
        unset($b->items);
        echo "done\n";
    }
}
