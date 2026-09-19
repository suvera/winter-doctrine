<?php

declare(strict_types=1);

namespace WinterDoctrineTest\Support;

class Checks {
    private static int $failures = 0;

    public static function check(string $name, bool $cond): void {
        echo ($cond ? 'PASS' : 'FAIL') . ' ' . $name . "\n";
        if (!$cond) {
            self::$failures++;
        }
    }

    public static function failures(): int {
        return self::$failures;
    }
}
