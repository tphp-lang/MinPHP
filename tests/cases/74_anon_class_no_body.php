<?php

// expect-error: 期望 '{'

interface Handler
{
    public function handle(int $x): int;
}

class Main
{
    public function main(): void
    {
        $a = new class implements Handler;
    }
}
