<?php

declare(strict_types=1);

// DbalLockManager: database leases for #[Lockable] through Doctrine DBAL
// (real SQLite, no Swoole). Needs winter-boot 2.1.6+ (StoreLockManager).
// Run with: php tests/run.php

use dev\winterframework\coroutine\CoroutineScopedPool;
use dev\winterframework\coroutine\NullCoroutineScopeProvider;
use dev\winterframework\doctrine\common\IsolatingScopeProvider;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\dbal\WinterConnection;
use dev\winterframework\doctrine\lock\DbalLockManager;
use dev\winterframework\doctrine\lock\DbalLockStore;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\concurrent\LockException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use WinterDoctrineTest\Support\Checks;

require_once __DIR__ . '/bootstrap.php';

$dbFile = function (): string {
    $f = tempnam(sys_get_temp_dir(), 'wdlock');
    register_shutdown_function(fn() => @unlink($f));
    return $f;
};
$connect = fn(string $file): Connection => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);

// A winter-doctrine connection bean as the module builds it: a coroutine-scoped
// façade with REQUIRES_NEW isolation.
$facade = function (string $file) use ($connect): WinterConnection {
    $isolation = new IsolatingScopeProvider(new NullCoroutineScopeProvider());
    $pool = new CoroutineScopedPool(
        fn() => $connect($file),
        $isolation,
        function (Connection $c): void {
            if ($c->isTransactionActive()) {
                $c->rollBack();
            }
            $c->close();
        },
        null,
        null,
        'lock-dbal',
        0,
        50
    );
    return WinterConnection::create($pool, $isolation);
};

// ── 1. two pods exclude each other; the table is created on first use
$file = $dbFile();
$pod1 = new DbalLockManager($facade($file), pollMs: 5);
$pod2 = new DbalLockManager($facade($file), pollMs: 5);
$a = $pod1->provideLock('order-7', 30);
$b = $pod2->provideLock('order-7', 30);
Checks::check('first pod locks', $a->tryLock());
Checks::check('lock table created', $connect($file)->createSchemaManager()->tableExists('winter_locks'));
Checks::check('second pod waits and gives up', !$b->tryLock(30));
Checks::check('other names are free', $pod2->provideLock('order-8', 30)->tryLock());
$a->unlock();
Checks::check('second pod locks after release', $b->tryLock(0));
$b->unlock();

// ── 2. owner check and expiry
$store = new DbalLockStore($facade($file));
Checks::check('acquire with 1 ms lease', $store->acquire('k', 'owner-a', 1));
usleep(5000);
Checks::check('expired lease taken over', $store->acquire('k', 'owner-b', 60000));
Checks::check('old owner cannot release', !$store->release('k', 'owner-a'));
Checks::check('old owner cannot extend', !$store->refresh('k', 'owner-a', 1000));
Checks::check('owner extends', $store->refresh('k', 'owner-b', 1000));
Checks::check('unexpired lease kept', !$store->acquire('k', 'owner-c', 0));
Checks::check('owner releases', $store->release('k', 'owner-b'));

// ── 3. a lost lease makes update() throw
$lost = $pod1->provideLock('lost', 10);
$lost->tryLock();
$connect($file)->executeStatement("DELETE FROM winter_locks WHERE lock_name = 'lost'");
try {
    $lost->update(10);
    Checks::check('update() on a lost lease throws', false);
} catch (LockException) {
    Checks::check('update() on a lost lease throws', !$lost->isLocked());
}

// ── 4. a lock taken inside the caller's transaction is visible at once
$conn = $facade($file);
$tm = new DbalTransactionManager($conn);
$status = $tm->getTransaction(new DefaultTransactionDefinition());
$inTxn = (new DbalLockManager($conn))->provideLock('in-txn', 30);
Checks::check('lock inside a transaction', $inTxn->tryLock());
Checks::check('visible to another pod while the transaction is open',
    !(new DbalLockManager($facade($file)))->provideLock('in-txn', 30)->tryLock(0));
$inTxn->unlock();
$tm->rollback($status);
Checks::check('released lock stays released after the caller rolls back',
    (new DbalLockManager($facade($file)))->provideLock('in-txn', 30)->tryLock(0));

// ── 5. a plain DBAL connection inside a transaction is refused, not silently unsafe
$plain = $connect($file);
$plain->beginTransaction();
try {
    (new DbalLockManager($plain))->provideLock('plain', 5)->tryLock();
    Checks::check('plain connection in a transaction refused', false);
} catch (Throwable $e) {
    Checks::check('plain connection in a transaction refused', str_contains($e->getMessage(), 'inside a transaction'));
}
$plain->rollBack();
Checks::check('plain connection outside a transaction works', (new DbalLockManager($plain))->provideLock('plain', 5)->tryLock());

// ── 6. long names are hashed to fit the key column
Checks::check('long name hashed', strlen(DbalLockStore::key(str_repeat('x', 500))) === 71);
$long = $pod1->provideLock(str_repeat('y', 500), 5);
Checks::check('long name locks', $long->tryLock());
$long->unlock();

// ── 7. the generated DDL uses the platform's types
Checks::check('DDL has a primary key', str_contains((new DbalLockStore($connect($file)))->getCreateTableSql(), 'PRIMARY KEY (lock_name)'));
