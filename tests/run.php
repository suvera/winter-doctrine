<?php

declare(strict_types=1);

// Minimal test runner (no framework): php tests/run.php
// Each *Test.php runs on include and reports via Checks.

require_once __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $test) {
    // PoolCapTest exercises the winter-boot-owned pool through the facade.
    // It runs wherever winter-boot is loadable (host app, sibling checkout)
    // and skips cleanly standalone: the pool's own suite lives in boot.
    if (basename($test) === 'PoolCapTest.php'
        && !class_exists('dev\winterframework\coroutine\CoroutineScopedPool')
    ) {
        echo "== PoolCapTest.php ==\nSKIP (winter-boot pool not loadable)\n";
        continue;
    }
    echo '== ' . basename($test) . " ==\n";
    require_once $test;
}

$failures = WinterDoctrineTest\Support\Checks::failures();
echo $failures === 0 ? "ALL GREEN\n" : $failures . " FAILURES\n";
exit($failures === 0 ? 0 : 1);
