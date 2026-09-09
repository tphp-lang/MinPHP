<?php

// B4 多态分发：12000 元素多态数组 × 300 轮遍历 = 360 万次虚调用
class Animal
{
    public function speak(): int
    {
        return 1;
    }
}

class Dog extends Animal
{
    public function speak(): int
    {
        return 2;
    }
}

class Cat extends Animal
{
    public function speak(): int
    {
        return 3;
    }
}

class Main
{
    public function main(): void
    {
        array<Animal> $zoo = [];
        int $i = 0;
        while ($i < 12000) {
            if ($i % 2 == 0) {
                $zoo[] = new Dog();
            } else {
                $zoo[] = new Cat();
            }
            $i = $i + 1;
        }
        int $sum = 0;
        int $round = 0;
        while ($round < 300) {
            foreach ($zoo as $a) {
                $sum = $sum + $a->speak();
            }
            $round = $round + 1;
        }
        echo $sum, "\n";
    }
}
