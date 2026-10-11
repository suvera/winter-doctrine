<?php

declare(strict_types=1);

// ParameterizedFilter + FilterAwareEntityManager: a tenant filter follows
// request state on every query path, both with coroutine scoping off (one
// EntityManager per worker, built before any request) and on (façade over
// per-scope delegates). Run with: php tests/run.php

use dev\winterframework\coroutine\CoroutineScopedPool;
use dev\winterframework\doctrine\common\OrmConfigurationFactory;
use dev\winterframework\doctrine\orm\FilterAwareEntityManager;
use dev\winterframework\doctrine\orm\ParameterizedFilter;
use dev\winterframework\doctrine\orm\WinterEntityManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use WinterDoctrineTest\Fixture\Invoice;
use WinterDoctrineTest\Support\Checks;
use WinterDoctrineTest\Support\FakeScopes;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/FakeScopes.php';
require_once __DIR__ . '/Fixture/Invoice.php';

final class TenantFilterTestFilter extends SQLFilter implements ParameterizedFilter {
    public static ?int $tenant = null;

    public static function currentParameters(): array {
        return ['tenant' => self::$tenant];
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string {
        if (!$targetEntity->hasField('tenantId')) {
            return '';
        }
        if ($this->getParameter('tenant') === "''") {
            return '1 = 0'; // no tenant: fail closed
        }
        return $targetTableAlias . '.' . $targetEntity->getColumnName('tenantId') . ' = ' . $this->getParameter('tenant');
    }
}

$customers = fn(array $rows): array => array_map(fn(Invoice $i) => $i->customer, $rows);

$db = tempnam(sys_get_temp_dir(), 'wdtf');
register_shutdown_function(fn() => @unlink($db));
$config = OrmConfigurationFactory::create([__DIR__ . '/Fixture'], true, ['tenant' => TenantFilterTestFilter::class]);
$connect = fn() => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $db], $config);
Checks::check('filter registered in the ORM configuration', $config->getFilterClassName('tenant') === TenantFilterTestFilter::class);

$seed = $connect();
$seed->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, customer VARCHAR(50))');
foreach ([[1, 'Acme'], [1, 'Apex'], [2, 'Globex'], [2, 'Initech']] as [$t, $c]) {
    $seed->insert('invoice', ['tenant_id' => $t, 'customer' => $c]);
}
$globexId = (int)$seed->fetchOne("SELECT id FROM invoice WHERE customer = 'Globex'");

// --- Coroutine scoping off: one EntityManager per worker, built at boot. ---
TenantFilterTestFilter::$tenant = null;
$em = new FilterAwareEntityManager($connect(), $config);
$em->enableFilters(['tenant']);
Checks::check('filter enabled at build', $em->getFilters()->isEnabled('tenant'));
// Fetched at boot, before any tenant is known (e.g. injected into a service).
$invoices = $em->getRepository(Invoice::class);
$dql = 'SELECT i FROM ' . Invoice::class . ' i ORDER BY i.id';

TenantFilterTestFilter::$tenant = 1;
Checks::check('worker EM: tenant 1 findBy sees only its rows', $customers($invoices->findBy([], ['id' => 'ASC'])) === ['Acme', 'Apex']);
Checks::check('worker EM: tenant 1 DQL sees only its rows', $customers($em->createQuery($dql)->getResult()) === ['Acme', 'Apex']);
Checks::check('worker EM: find() of another tenant\'s row is filtered', $em->find(Invoice::class, $globexId) === null);
$em->createQuery($dql)->getResult();
Checks::check('worker EM: unchanged tenant keeps filter state clean', $em->isFiltersStateClean());
$em->clear();

TenantFilterTestFilter::$tenant = 2;
Checks::check('worker EM: next request (tenant 2) findBy', $customers($invoices->findBy([], ['id' => 'ASC'])) === ['Globex', 'Initech']);
Checks::check('worker EM: same DQL is not served tenant 1\'s cached SQL', $customers($em->createQuery($dql)->getResult()) === ['Globex', 'Initech']);
$em->clear();

TenantFilterTestFilter::$tenant = null;
Checks::check('worker EM: no tenant fails closed', $invoices->findAll() === []);

// --- Coroutine scoping on: façade over one delegate per request scope. ---
$scopes = new FakeScopes();
$pool = new CoroutineScopedPool(
    function () use ($connect, $config) {
        $d = new FilterAwareEntityManager($connect(), $config);
        $d->enableFilters(['tenant']);
        return $d;
    },
    $scopes,
    fn(EntityManager $d) => $d->close(),
    fn(EntityManager $d) => $d->isOpen(),
);
$facade = WinterEntityManager::create($pool);
foreach ([1 => ['Acme', 'Apex'], 2 => ['Globex', 'Initech']] as $tenant => $expected) {
    $scopes->id = "req-$tenant";
    // Repository taken before the tenant is set (as early middleware would).
    $repo = $facade->getRepository(Invoice::class);
    TenantFilterTestFilter::$tenant = $tenant;
    Checks::check("façade: tenant $tenant findBy", $customers($repo->findBy([], ['id' => 'ASC'])) === $expected);
    Checks::check("façade: tenant $tenant DQL", $customers($facade->createQuery($dql)->getResult()) === $expected);
    TenantFilterTestFilter::$tenant = null;
    $scopes->endScope("req-$tenant");
}
