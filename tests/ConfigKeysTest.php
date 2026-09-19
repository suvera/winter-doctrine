<?php

declare(strict_types=1);

// Regression test: the coroutine pool wiring reads the winter.* config
// keys (renamed off doctrine.* when the boot-core classes moved). Run
// with: php tests/run.php

use dev\winterframework\doctrine\common\DoctrineComponentBuilder;
use WinterDoctrineTest\Support\Checks;

require_once __DIR__ . '/bootstrap.php';

Checks::check(
    'kill switch key is winter.coroutine.db.enabled',
    DoctrineComponentBuilder::COROUTINE_SCOPED_FLAG === 'winter.coroutine.db.enabled'
);
Checks::check(
    'cap key is winter.coroutine.db.maxConnections',
    DoctrineComponentBuilder::COROUTINE_MAX_DELEGATES_FLAG === 'winter.coroutine.db.maxConnections'
);
Checks::check(
    'wait key is winter.coroutine.db.maxWaitMs',
    DoctrineComponentBuilder::COROUTINE_MAX_WAIT_MS_FLAG === 'winter.coroutine.db.maxWaitMs'
);
