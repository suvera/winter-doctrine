<?php

declare(strict_types=1);

// Regression tests for the Doctrine transaction managers on top of
// winter-boot's AbstractPlatformTransactionManager (real SQLite, no
// Swoole). Each case failed before the 2.1.0 fixes:
// - a failed participant let the outer transaction commit partial work
// - statuses never became completed; rollback after a failed commit threw
//   "There is no active transaction" and hid the original error
// - REQUIRES_NEW / NOT_SUPPORTED ran on the suspended transaction's
//   connection, so an inner commit was undone by the outer rollback
// Run with: php tests/run.php

use dev\winterframework\coroutine\CoroutineScopedPool;
use dev\winterframework\coroutine\NullCoroutineScopeProvider;
use dev\winterframework\coroutine\PoolExhaustedException;
use dev\winterframework\doctrine\common\IsolatingScopeProvider;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\dbal\WinterConnection;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use dev\winterframework\doctrine\orm\WinterEntityManager;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\txn\TransactionDefinition;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use WinterDoctrineTest\Fixture\Widget;
use WinterDoctrineTest\Support\Checks;
use WinterDoctrineTest\Support\FakeScopes;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/FakeScopes.php';

$dbFile = function (): string {
    $f = tempnam(sys_get_temp_dir(), 'wdtx');
    register_shutdown_function(fn() => @unlink($f));
    return $f;
};
$connect = fn(string $file): Connection => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
$def = function (int $propagation = TransactionDefinition::PROPAGATION_REQUIRED): DefaultTransactionDefinition {
    $d = new DefaultTransactionDefinition();
    $d->setPropagationBehavior($propagation);
    return $d;
};
$count = fn(Connection $c, string $table = 't'): int => (int)$c->fetchOne("select count(*) from $table");

// ── 1. Failed participant forces the outer transaction to roll back (DBAL)
$file = $dbFile();
$conn = $connect($file);
$conn->executeStatement('create table t (v int)');
$tm = new DbalTransactionManager($conn);
$outer = $tm->getTransaction($def());
$conn->insert('t', ['v' => 1]);
$inner = $tm->getTransaction($def());
$tm->rollback($inner);            // inner method threw; outer caught it
$tm->commit($outer);
Checks::check('DBAL: failed participant rolls back the outer commit', $count($conn) === 0);
Checks::check('DBAL: connection left without a transaction', !$conn->isTransactionActive());

// ── 2. Same for the ORM: nothing is flushed after a participant failed
$ormConfig = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixture'], true, null, new ArrayAdapter());
$file = $dbFile();
$conn = $connect($file);
$conn->executeStatement('create table Widget (id integer primary key autoincrement, name varchar(255) not null unique)');
$em = new EntityManager($conn, $ormConfig);
$tm = new EmTransactionManager($em);
$outer = $tm->getTransaction($def());
$w = new Widget();
$w->name = 'a';
$em->persist($w);
$inner = $tm->getTransaction($def());
$tm->rollback($inner);
$tm->commit($outer);
Checks::check('ORM: failed participant rolls back the outer commit', $count($conn, 'Widget') === 0);

// ── 3. Status completion and rollback after a failed commit (ORM flush failure)
$file = $dbFile();
$conn = $connect($file);
$conn->executeStatement('create table Widget (id integer primary key autoincrement, name varchar(255) not null unique)');
$conn->insert('Widget', ['name' => 'dup']);
$em = new EntityManager($conn, $ormConfig);
$tm = new EmTransactionManager($em);

$ok = $tm->getTransaction($def());
$tm->commit($ok);
Checks::check('status is completed after commit', $ok->isCompleted());

$status = $tm->getTransaction($def());
$w = new Widget();
$w->name = 'dup';                 // unique violation at flush
$em->persist($w);
$commitError = null;
try {
    $tm->commit($status);
} catch (Throwable $e) {
    $commitError = $e;
}
Checks::check('failed commit throws', $commitError !== null);
Checks::check('status is completed after failed commit', $status->isCompleted());
$rollbackError = null;
try {
    $tm->rollback($status);       // what TransactionalAspect::commitFailed() and the README pattern do
} catch (Throwable $e) {
    $rollbackError = $e;
}
Checks::check('rollback after failed commit does not hide the commit error', $rollbackError === null);
Checks::check('failed commit leaves no open transaction', !$conn->isTransactionActive());
$again = false;
try {
    $tm->rollback($status);
} catch (Throwable) {
    $again = true;
}
Checks::check('second rollback is still rejected', $again);

// ── 4. DBAL commit failure where the server keeps the transaction open
// (SQLite deferred foreign key): the connection must stay usable.
$file = $dbFile();
$conn = $connect($file);
$conn->executeStatement('PRAGMA foreign_keys = ON');
$conn->executeStatement('create table parent (id integer primary key)');
$conn->executeStatement('create table child (id integer, pid integer references parent(id) deferrable initially deferred)');
$tm = new DbalTransactionManager($conn);
$status = $tm->getTransaction($def());
$conn->insert('child', ['id' => 1, 'pid' => 99]);
try {
    $tm->commit($status);
} catch (Throwable) {
}
$tm->rollback($status);
$usable = true;
try {
    $next = $tm->getTransaction($def());
    $conn->insert('parent', ['id' => 1]);
    $tm->commit($next);
} catch (Throwable) {
    $usable = false;
}
Checks::check('connection usable after a failed COMMIT', $usable && $count($conn, 'parent') === 1 && $count($conn, 'child') === 0);

// ── 5. REQUIRES_NEW on the coroutine-scoped façade runs on its own connection
$makeConnPool = function (IsolatingScopeProvider $scopes, string $file, int $max = 0) use ($connect): CoroutineScopedPool {
    return new CoroutineScopedPool(
        fn() => $connect($file),
        $scopes,
        function (Connection $c): void {
            if ($c->isTransactionActive()) {
                $c->rollBack();
            }
            $c->close();
        },
        null,
        null,
        'tx-dbal',
        $max,
        50
    );
};

$file = $dbFile();
$connect($file)->executeStatement('create table t (v int)');
$isolation = new IsolatingScopeProvider(new NullCoroutineScopeProvider());
$pool = $makeConnPool($isolation, $file);
$facade = WinterConnection::create($pool, $isolation);
$tm = new DbalTransactionManager($facade);

$outer = $tm->getTransaction($def());
$outerPdo = $facade->getNativeConnection();
$inner = $tm->getTransaction($def(TransactionDefinition::PROPAGATION_REQUIRES_NEW));
$innerPdo = $facade->getNativeConnection();
$facade->insert('t', ['v' => 1]);
$tm->commit($inner);
Checks::check('REQUIRES_NEW uses a separate connection', $innerPdo !== $outerPdo);
Checks::check('back on the outer connection after REQUIRES_NEW', $facade->getNativeConnection() === $outerPdo);
$facade->insert('t', ['v' => 2]);
$tm->rollback($outer);
Checks::check('REQUIRES_NEW commit survives the outer rollback', $count($connect($file)) === 1);
Checks::check('isolation depth back to zero', $isolation->getDepth() === 0);

// ── 6. NOT_SUPPORTED runs outside the suspended transaction
$outer = $tm->getTransaction($def());
$facade->insert('t', ['v' => 3]);
$none = $tm->getTransaction($def(TransactionDefinition::PROPAGATION_NOT_SUPPORTED));
Checks::check('NOT_SUPPORTED runs without a transaction', !$facade->isTransactionActive());
$tm->commit($none);
Checks::check('suspended transaction resumes after NOT_SUPPORTED', $facade->isTransactionActive());
$tm->rollback($outer);
Checks::check('outer rollback after NOT_SUPPORTED', $count($connect($file)) === 1);

// ── 7. ORM façade: REQUIRES_NEW flushes only its own unit of work
$file = $dbFile();
$connect($file)->executeStatement('create table Widget (id integer primary key autoincrement, name varchar(255) not null unique)');
$emIsolation = new IsolatingScopeProvider(new NullCoroutineScopeProvider());
$emPool = new CoroutineScopedPool(
    fn() => new EntityManager($connect($file), $ormConfig),
    $emIsolation,
    function (EntityManager $em): void {
        if ($em->getConnection()->isTransactionActive()) {
            $em->getConnection()->rollBack();
        }
        $em->close();
        $em->getConnection()->close();
    },
    fn(EntityManager $em) => $em->isOpen(),
    null,
    'tx-em',
    0,
    50
);
$emFacade = WinterEntityManager::create($emPool, $emIsolation);
$tm = new EmTransactionManager($emFacade);
$outer = $tm->getTransaction($def());
$a = new Widget();
$a->name = 'outer';
$emFacade->persist($a);
$inner = $tm->getTransaction($def(TransactionDefinition::PROPAGATION_REQUIRES_NEW));
$b = new Widget();
$b->name = 'inner';
$emFacade->persist($b);
$tm->commit($inner);
$tm->rollback($outer);
$names = $connect($file)->fetchFirstColumn('select name from Widget');
Checks::check('ORM REQUIRES_NEW commits only the inner entity', $names === ['inner']);

// ── 8. Isolated connections count toward maxConnections and are released
$scopes = new FakeScopes();
$isolation = new IsolatingScopeProvider($scopes);
$file = $dbFile();
$connect($file)->executeStatement('create table t (v int)');
$pool = $makeConnPool($isolation, $file, 1);
$facade = WinterConnection::create($pool, $isolation);
$tm = new DbalTransactionManager($facade);
$scopes->id = 'A';
$outer = $tm->getTransaction($def());
$exhausted = false;
try {
    $tm->getTransaction($def(TransactionDefinition::PROPAGATION_REQUIRES_NEW));
} catch (PoolExhaustedException) {
    $exhausted = true;
}
Checks::check('REQUIRES_NEW needs a second slot under the cap', $exhausted);
Checks::check('failed isolation leaves the scope un-isolated', $isolation->getDepth() === 0);
$tm->rollback($outer);

$pool2 = $makeConnPool($isolation, $file, 2);
$facade2 = WinterConnection::create($pool2, $isolation);
$tm2 = new DbalTransactionManager($facade2);
$outer = $tm2->getTransaction($def());
$inner = $tm2->getTransaction($def(TransactionDefinition::PROPAGATION_REQUIRES_NEW));
Checks::check('isolated delegate counted while active', $pool2->getActiveDelegateCount() === 2);
$tm2->commit($inner);
Checks::check('isolated delegate released at completion', $pool2->getActiveDelegateCount() === 1);
$tm2->commit($outer);
$scopes->endScope('A');
Checks::check('scope end releases everything', $pool2->getActiveDelegateCount() === 0);
