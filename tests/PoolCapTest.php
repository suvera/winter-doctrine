<?php

declare(strict_types=1);

// Focused test for the pool cap (doctrine.coroutineMaxDelegates /
// doctrine.coroutineMaxWaitMs). Run with: php tests/run.php
// Needs no database and no Swoole extension.

use dev\winterframework\doctrine\coroutine\CoroutineScopeProvider;
use dev\winterframework\doctrine\coroutine\CoroutineScopedPool;
use dev\winterframework\doctrine\coroutine\PoolExhaustedException;
use dev\winterframework\doctrine\orm\WinterEntityManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use WinterDoctrineTest\Fixture\Widget;
use WinterDoctrineTest\Support\Checks;
use WinterDoctrineTest\Support\FakeScopes;

require_once __DIR__ . '/bootstrap.php';

$config = ORMSetup::createAttributeMetadataConfig(
    [__DIR__ . '/Fixture'],
    true,
    null,
    new ArrayAdapter()
);

$makePool = function (FakeScopes $scopes, int $max, int $waitMs, string $name): CoroutineScopedPool {
    global $config;
    return new CoroutineScopedPool(
        function () use ($config) {
            // Offline params: metadata-only work never connects. The
            // explicit serverVersion skips even platform detection queries.
            $conn = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => '127.0.0.1',
                'user' => 'u',
                'password' => 'p',
                'dbname' => 'd',
                'serverVersion' => '16',
            ]);
            return new EntityManager($conn, $config);
        },
        $scopes,
        function (EntityManager $em): void {
            if ($em->isOpen()) {
                $em->close();
            }
        },
        fn(EntityManager $em) => $em->isOpen(),
        null,
        $name,
        $max,
        $waitMs
    );
};

// 1. Second scope fails fast with PoolExhaustedException when capped.
$scopes = new FakeScopes();
$pool = $makePool($scopes, 1, 50, 'cap-em');
$scopes->id = 'A';
$pool->current();
$scopes->id = 'B';
try {
    $pool->current();
    Checks::check('capped pool rejects second scope', false);
} catch (PoolExhaustedException $e) {
    Checks::check('capped pool rejects second scope', true);
    Checks::check('exception names pool', $e->getPoolName() === 'cap-em');
    Checks::check('exception reports counts', $e->getActiveDelegates() === 1 && $e->getMaxDelegates() === 1);
    Checks::check('exception mentions DB connections', str_contains($e->getMessage(), 'DB connection'));
}

// 2. Released slot is reusable by the waiting scope.
$scopes->endScope('A');
$scopes->id = 'B';
$pool->current();
Checks::check('released slot reusable', $pool->getActiveDelegateCount() === 1);

// 3. Shipped defaults: capped at 50 DB connections, 5s wait.
$defaults = $makePool(new FakeScopes(), 50, 5000, 'defaults-em');
Checks::check('shipped cap is 50', $defaults->getMaxDelegates() === 50);
Checks::check('shipped wait is 5s', $defaults->getMaxWaitMs() === 5000);

// 4. Unlimited opt-out (max 0): many scopes coexist.
$scopes = new FakeScopes();
$pool = $makePool($scopes, 0, 50, 'open-em');
foreach (['A', 'B', 'C', 'D', 'E'] as $id) {
    $scopes->id = $id;
    $pool->current();
}
Checks::check('unlimited opt-out allows many scopes', $pool->getActiveDelegateCount() === 5);

// 5. The process-wide fallback (CLI, outside coroutines) is never capped.
$scopes = new FakeScopes();
$pool = $makePool($scopes, 1, 50, 'cli-em');
$scopes->id = 'A';
$pool->current();
$scopes->id = null;
Checks::check('fallback delegate unaffected by cap', $pool->current() === $pool->current());

// 6. Closed delegates still rebuild under a cap (failed unit of work must
// not poison its scope, even when every slot is taken).
$scopes = new FakeScopes();
$pool = $makePool($scopes, 1, 50, 'reset-em');
$facade = WinterEntityManager::create($pool);
$scopes->id = 'A';
$pool->current()->close();
$facade->persist(new Widget());
Checks::check('auto-rebuild under cap', $pool->current()->isOpen());
Checks::check('count stable after rebuild', $pool->getActiveDelegateCount() === 1);

// 7. Negative config values clamp to unlimited instead of misbehaving.
$pool = $makePool(new FakeScopes(), -5, -1, 'clamp-em');
Checks::check('negative cap clamps', $pool->getMaxDelegates() === 0 && $pool->getMaxWaitMs() === 0);
