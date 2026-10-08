<?php

declare(strict_types=1);

// Minimal test runner (no framework): php tests/run.php
// Each *Test.php runs on include and reports via Checks.

require_once __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $test) {
    // These tests exercise winter-boot classes (pool, transaction manager
    // base, DataSourceConfig). They run wherever winter-boot is loadable
    // (host app, WINTER_BOOT_DIR, sibling checkout) and skip cleanly
    // standalone.
    if (in_array(basename($test), ['PoolCapTest.php', 'TransactionTest.php', 'MultiTenantTest.php'], true)
        && !class_exists('dev\winterframework\txn\support\AbstractPlatformTransactionManager')
    ) {
        echo '== ' . basename($test) . " ==\nSKIP (winter-boot not loadable)\n";
        continue;
    }
    echo '== ' . basename($test) . " ==\n";
    require_once $test;
}

$failures = WinterDoctrineTest\Support\Checks::failures();
echo $failures === 0 ? "ALL GREEN\n" : $failures . " FAILURES\n";
exit($failures === 0 ? 0 : 1);
