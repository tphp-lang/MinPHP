<?php

// 流敏感收窄：if 正分支 / && 右侧 / 三元 then / while 条件体 / 嵌套收窄
// expect:
// wang
// miao
// dog-kind
// yes
// wang
// wang
// wang
// wang
// yip

abstract class Animal
{
    abstract public function sound(): string;
}

class Dog extends Animal
{
    public string $kind = "dog-kind";

    public function sound(): string
    {
        return "wang";
    }
}

class Puppy extends Dog
{
    public function sound(): string
    {
        return "yip";
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
        // 父类变量 → 子类
        Animal $a = new Dog();
        if ($a instanceof Dog) {
            echo $a->sound(), "\n";     // wang
        }

        Animal $c = new Cat();
        if ($c instanceof Cat) {
            echo $c->sound(), "\n";     // miao
        }

        // object → 具体类（成员访问）
        object $o = new Dog();
        if ($o instanceof Dog) {
            echo $o->kind, "\n";        // dog-kind
        }

        // && 右侧：左侧 instanceof 事实在右侧生效
        Animal $b = new Dog();
        if ($b instanceof Dog && $b->sound() == "wang") {
            echo "yes\n";
        }

        // 三元 then：条件 instanceof 事实仅在 then 生效
        object $d = new Dog();
        echo $d instanceof Dog ? $d->sound() : "no", "\n";  // wang

        // while 条件体：每轮以条件为真进入 ⇒ 体内收窄
        Animal $w = new Dog();
        int $i = 0;
        while ($w instanceof Dog && $i < 3) {
            $i = $i + 1;
            echo $w->sound(), "\n";     // wang x3
        }

        // 嵌套收窄到更具体的类
        Animal $n = new Puppy();
        if ($n instanceof Dog) {
            if ($n instanceof Puppy) {
                echo $n->sound(), "\n"; // yip
            }
        }
    }
}
