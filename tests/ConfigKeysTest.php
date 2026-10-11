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

// doctrine.filters: list form from YAML, and a plain map.
$filtersCfg = new dev\winterframework\doctrine\common\DoctrineDbConfig();
$filtersCfg->setFilters([['name' => 'tenant', 'class' => 'App\\TenantFilter']]);
Checks::check('filters list form maps name => class', $filtersCfg->getFilters() === ['tenant' => 'App\\TenantFilter']);
$filtersCfg->setFilters(['tenant' => 'App\\TenantFilter']);
Checks::check('filters map form is kept', $filtersCfg->getFilters() === ['tenant' => 'App\\TenantFilter']);

// doctrine.filters map form, as it arrives from YAML (flattened to dotted keys).
$flat = dev\winterframework\type\Arrays::flattenByKey([
    'doctrine' => ['filters' => ['tenant' => 'App\\TenantFilter'], 'driver' => 'pdo_mysql'],
]);
$mapCfg = new dev\winterframework\doctrine\common\DoctrineDbConfig();
$mapCfg->parseDoctrineParams($flat);
Checks::check('flattened filters map form is picked up', $mapCfg->getFilters() === ['tenant' => 'App\\TenantFilter']);
Checks::check('filters kept out of doctrine options', $mapCfg->getDoctrineOptions() === ['driver' => 'pdo_mysql']);

// A malformed entry fails loudly instead of dropping the filter.
$badCfg = new dev\winterframework\doctrine\common\DoctrineDbConfig();
$badCfg->setName('main');
$badCfg->setFilters(['tenant']);
$threw = false;
try {
    $badCfg->getFilters();
} catch (dev\winterframework\exception\WinterException) {
    $threw = true;
}
Checks::check('malformed filters entry throws', $threw);

// Boot warning: coroutine scoping switched off while Swoole is loaded.
Checks::check('warns when scoping is off under Swoole', DoctrineComponentBuilder::sharedEntityManagerWarning(false, true) !== null);
Checks::check('no warning when scoping is on', DoctrineComponentBuilder::sharedEntityManagerWarning(true, true) === null);
Checks::check('no warning without Swoole', DoctrineComponentBuilder::sharedEntityManagerWarning(false, false) === null);
