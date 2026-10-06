<?php

declare(strict_types=1);

// Regression test for MultiTenantManager: transaction-manager getters
// fataled on missing imports, and tenant connections passed DBAL 4 a
// "url" parameter it no longer supports (DriverRequired). Real SQLite,
// both the plain and the coroutine-scoped code paths.
// Run with: php tests/run.php

use dev\winterframework\coroutine\NullCoroutineScopeProvider;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\multitenancy\MultiTenantManager;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\multitenant\TenantDataSourceProvider;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use WinterDoctrineTest\Fixture\Widget;
use WinterDoctrineTest\Support\Checks;
use WinterDoctrineTest\Support\FakeAppContext;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/FakeAppContext.php';

$dir = sys_get_temp_dir() . '/wdmt' . getmypid();
@mkdir($dir);
register_shutdown_function(function () use ($dir) {
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
});

class TestTenantProvider implements TenantDataSourceProvider {
    public function __construct(private string $dir) {
    }

    public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig {
        $config = new DataSourceConfig();
        $config->setName($tenantId);
        $config->setUrl('sqlite:' . $this->dir . '/' . $tenantId . '.db');
        return $config;
    }

    public function getAllTenantIds(): array {
        return ['t1', 't2'];
    }
}

$appCtx = FakeAppContext::create([TestTenantProvider::class => new TestTenantProvider($dir)]);

foreach (['plain' => false, 'coroutine-scoped' => true] as $mode => $scoped) {
    $mt = new MultiTenantManager(
        TestTenantProvider::class,
        $appCtx,
        new NullCoroutineScopeProvider(),
        $scoped,
        50,
        5000,
        [__DIR__ . '/Fixture'],
        true
    );

    $conn = $mt->getConnection('t1');
    $ok = false;
    try {
        $conn->executeStatement('create table if not exists Widget (id integer primary key autoincrement, name varchar(255) not null)');
        $ok = (int)$conn->fetchOne('select 1') === 1;
    } catch (Throwable $e) {
        echo '  ' . $e::class . ': ' . $e->getMessage() . "\n";
    }
    Checks::check("$mode: tenant connection works with a PDO-style DSN", $ok);

    $emTm = null;
    $dbalTm = null;
    try {
        $emTm = $mt->getEmTransactionManager('t1');
        $dbalTm = $mt->getDbalTransactionManager('t1');
    } catch (Throwable $e) {
        echo '  ' . $e::class . ': ' . $e->getMessage() . "\n";
    }
    Checks::check("$mode: getEmTransactionManager()", $emTm instanceof EmTransactionManager);
    Checks::check("$mode: getDbalTransactionManager()", $dbalTm instanceof DbalTransactionManager);

    // Entity paths reach the tenant EntityManager: persist + commit works.
    $before = (int)$conn->fetchOne('select count(*) from Widget');
    $status = $emTm->getTransaction(new DefaultTransactionDefinition());
    $w = new Widget();
    $w->name = $mode;
    $mt->getEntityManager('t1')->persist($w);
    $emTm->commit($status);
    Checks::check("$mode: tenant EntityManager persists through its transaction manager",
        (int)$conn->fetchOne('select count(*) from Widget') === $before + 1);

    Checks::check("$mode: tenants share one ORM configuration",
        $mt->getEntityManager('t1')->getConfiguration() === $mt->getEntityManager('t2')->getConfiguration());
    Checks::check("$mode: cached tenant ids include EntityManager-only tenants",
        in_array('t2', $mt->getCachedTenantIds(), true));
    $mt->close();
}
