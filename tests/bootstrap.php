<?php

declare(strict_types=1);

// Test bootstrap: composer autoloader + minimal stand-ins for winter-boot
// framework classes that are not vendored in this repository. When the
// tests run inside a real winter-boot application the genuine classes win
// (trait_exists guard) and these stand-ins are never defined.

require dirname(__DIR__) . '/vendor/autoload.php';

if (!trait_exists('dev\winterframework\util\log\Wlf4p')) {
    eval('namespace dev\winterframework\util\log; trait Wlf4p {'
        . ' public static function logDebug(string $m, array $c = []): void {}'
        . ' public static function logInfo(string $m, array $c = []): void {}'
        . ' public static function logWarning(string $m, array $c = []): void {}'
        . ' public static function logError(string $m, array $c = []): void {}'
        . ' public static function logException(\Throwable $e, string $m = ""): void {}'
        . ' }');
}

require_once __DIR__ . '/Fixture/Widget.php';
require_once __DIR__ . '/Support/Checks.php';
// NOTE: Support/FakeScopes.php implements a winter-boot interface and is
// required only by tests that also need the boot-owned pool (see run.php).
