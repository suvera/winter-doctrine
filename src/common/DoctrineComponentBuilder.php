<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\dbal\WinterConnection;
use dev\winterframework\doctrine\coroutine\CoroutineScopeProvider;
use dev\winterframework\doctrine\coroutine\CoroutineScopeProviders;
use dev\winterframework\doctrine\coroutine\CoroutineScopedPool;
use dev\winterframework\doctrine\coroutine\SwooleCoroutineScopeProvider;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use dev\winterframework\doctrine\orm\WinterEntityManager;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use ReflectionClass;
use Throwable;
use WeakMap;

class DoctrineComponentBuilder {
    use Wlf4p;

    const DOCTRINE_SUFFIX = '-doctrine';
    const DOCTRINE_CONN_SUFFIX = '-dbal';
    const DOCTRINE_EM_SUFFIX = '-em';
    const DOCTRINE_TXN_SUFFIX = '-emtxn';
    const DOCTRINE_DBAL_TXN_SUFFIX = '-dbaltxn';



    /**
     * @var EntityManager[]
     */
    private array $entityManagers = [];
    private EntityManager $primaryEntityManager;

    /**
     * @var PlatformTransactionManager[]
     */
    private array $transactionManagers = [];
    private EmTransactionManager $primaryTransactionManager;

    /**
     * @var Connection[]
     */
    private array $connections = [];
    private Connection $primaryConnection;

    /**
     * @var DbalTransactionManager[]
     */
    private array $dbalTransactionManagers = [];
    private DbalTransactionManager $primaryDbalTransactionManager;

    /**
     * @var DoctrineDbConfig[]
     */
    private array $dsConfig = [];
    private array $dsParams = [];



    private WeakMap $dsObjectMap;
    private WeakMap $dsConnectMap;

    /**
     * Kill switch / opt-in flag (application.yml):
     * `doctrine.coroutineScopedEntityManagers`. Defaults to on under Swoole,
     * off without it.
     */
    const COROUTINE_SCOPED_FLAG = 'doctrine.coroutineScopedEntityManagers';

    /**
     * Cap on concurrent scoped DB connections per pool (shipped as 50,
     * 0 = unlimited but not recommended).
     * application.yml: `doctrine.coroutineMaxDelegates`.
     */
    const COROUTINE_MAX_DELEGATES_FLAG = 'doctrine.coroutineMaxDelegates';

    /**
     * How long a new scope waits for a free DB connection before giving up.
     * application.yml: `doctrine.coroutineMaxWaitMs` (default 5000).
     */
    const COROUTINE_MAX_WAIT_MS_FLAG = 'doctrine.coroutineMaxWaitMs';

    private bool $coroutineScoped = false;
    private int $maxDelegates = 50;
    private int $maxWaitMs = 5000;
    private CoroutineScopeProvider $scopes;

    /**
     * @var CoroutineScopedPool[]
     */
    private array $emPools = [];

    /**
     * @var CoroutineScopedPool[]
     */
    private array $connPools = [];

    /**
     * @var Configuration[]
     */
    private array $ormConfigs = [];

    /**
     * @var EventManager[]
     */
    private array $sharedEventManagers = [];

    public function __construct(
        private ApplicationContext $ctx,
        private ApplicationContextData $ctxData,
        array $dataSources
    ) {
        $this->dsObjectMap = new WeakMap();
        $this->dsConnectMap = new WeakMap();
        $this->scopes = CoroutineScopeProviders::create();
        $this->init($dataSources);
        $this->coroutineScoped = $this->resolveCoroutineScoping();
        [$this->maxDelegates, $this->maxWaitMs] = $this->resolveCoroutineCaps();
    }

    public function getMaxDelegates(): int {
        return $this->maxDelegates;
    }

    public function getMaxWaitMs(): int {
        return $this->maxWaitMs;
    }

    public function isCoroutineScoped(): bool {
        return $this->coroutineScoped;
    }

    private function resolveCoroutineScoping(): bool {
        try {
            $props = $this->ctxData->getPropertyContext();
            if ($props->has(self::COROUTINE_SCOPED_FLAG)) {
                return (bool)filter_var(
                    $props->get(self::COROUTINE_SCOPED_FLAG),
                    FILTER_VALIDATE_BOOLEAN
                );
            }
        } catch (Throwable $e) {
            self::logException($e, 'Could not read ' . self::COROUTINE_SCOPED_FLAG);
        }
        return SwooleCoroutineScopeProvider::isAvailable();
    }

    /**
     * @return array{0: int, 1: int} [maxDelegates, maxWaitMs]
     */
    private function resolveCoroutineCaps(): array {
        $max = 50;
        $wait = 5000;
        try {
            $props = $this->ctxData->getPropertyContext();
            if ($props->has(self::COROUTINE_MAX_DELEGATES_FLAG)) {
                $max = max(0, (int)$props->get(self::COROUTINE_MAX_DELEGATES_FLAG));
            }
            if ($props->has(self::COROUTINE_MAX_WAIT_MS_FLAG)) {
                $wait = max(0, (int)$props->get(self::COROUTINE_MAX_WAIT_MS_FLAG));
            }
        } catch (Throwable $e) {
            self::logException($e, 'Could not read coroutine pool caps, using unlimited');
        }
        return [$max, $wait];
    }

    private function init(array $dataSources): void {
        $primary = false;
        $first = false;

        $ref = new ReflectionClass(DoctrineDbConfig::class);

        foreach ($dataSources as $dataSource) {

            TypeAssert::notEmpty(
                'name',
                $dataSource['name'],
                'EntityManager configured without "name" parameter'
            );

            TypeAssert::notEmpty(
                'url',
                $dataSource['url'],
                'EntityManager configured without "url" parameter'
            );

            $ds = new DoctrineDbConfig();
            try {
                ObjectCreator::mapObject($ds, $dataSource, $ref);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Invalid Syntax in EntityManager configuration ', 0, $e);
            }
            $ds->setName($ds->getName() . self::DOCTRINE_SUFFIX);

            $parsedParams = $this->parseDsn($ds->getUrl());
            $ds->parseDoctrineParams($dataSource);
            foreach ($ds->getDoctrineOptions() as $key => $value) {
                if (!is_null($value) && $value !== '' && (is_array($value) && count($value) > 0)) {
                    $parsedParams[$key] = $value;
                }
            }

            if (!isset($parsedParams['driver']) || !$parsedParams['driver']) {
                throw new WinterException('Malformed parameter "url". No driver found');
            }
            $ds->setDriverClass($parsedParams['driver']);

            if (isset($parsedParams['user']) && $parsedParams['user']) {
                $ds->setUsername($parsedParams['user']);
            } else {
                $parsedParams['user'] = $ds->getUsername();
            }
            if (isset($parsedParams['password']) && $parsedParams['password']) {
                $ds->setPassword($parsedParams['password']);
            } else {
                $parsedParams['password'] = $ds->getPassword();
            }

            if ($primary && $ds->isPrimary()) {
                throw new WinterException('Two EntityManagers cannot have "isPrimary" set to "true"');
            }

            if ($ds->isPrimary()) {
                $primary = $ds;
            }
            if (!$first) {
                $first = $ds;
            }

            if (isset($this->dsConfig[$ds->getName()])) {
                throw new WinterException('Two EntityManagers cannot have same "name" "' . $ds->getName() . '"');
            }

            $this->dsConfig[$ds->getName()] = $ds;
            $this->dsParams[$ds->getName()] = $parsedParams;
        }

        if (!$primary && $first) {
            $first->setPrimary(true);
        }
    }

    /**
     * Parse the DSN string and return the array of key-value pairs
     */
    protected function parseDsn(string $url): array {
        $config = [];
        $parts = explode(":", $url, 2);
        $config['driver'] = $parts[0];

        if ($config['driver'] === 'sqlite') {
            $config['driver'] = 'sqlite3';
            $config['path'] = $parts[1];
            return $config;
        }

        if (!isset($parts[1])) {
            return $config;
        }
        $keyValues = explode(";", $parts[1]);
        foreach ($keyValues as $keyValue) {
            $kv = explode("=", $keyValue, 2);
            if (isset($kv[1])) {
                $config[$kv[0]] = $kv[1];
            }
        }

        return $config;
    }

    /**
     * @return EntityManager[]
     */
    public function getEntityManagers(): array {
        return $this->entityManagers;
    }

    public function getPrimaryTransactionManager(): EmTransactionManager {
        if (!isset($this->primaryTransactionManager)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    return $this->primaryTransactionManager = $this->getTransactionManager($dsConfig->getName());
                }
            }
        }
        throw new WinterException('Could not find Primary EmTransactionManager');
    }

    public function getTransactionManager(string $name): EmTransactionManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_TXN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }

        if (isset($this->transactionManagers[$name])) {
            return $this->transactionManagers[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->transactionManagers[$name] = new EmTransactionManager(
                $this->getEntityManager($name)
            );
        }
        throw new WinterException('Could not find EmTransactionManager with name "' . $name . '"');
    }

    public function getPrimaryDbalTransactionManager(): DbalTransactionManager {
        if (!isset($this->primaryDbalTransactionManager)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    return $this->primaryDbalTransactionManager = $this->getDbalTransactionManager($dsConfig->getName());
                }
            }
        }
        throw new WinterException('Could not find Primary DbalTransactionManager');
    }

    public function getDbalTransactionManager(string $name): DbalTransactionManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_DBAL_TXN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }

        if (isset($this->dbalTransactionManagers[$name])) {
            return $this->dbalTransactionManagers[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->dbalTransactionManagers[$name] = new DbalTransactionManager(
                $this->getConnection($name)
            );
        }
        throw new WinterException('Could not find DbalTransactionManager with name "' . $name . '"');
    }

    public function getPrimaryConnection(): Connection {
        if ($this->coroutineScoped) {
            return $this->getConnection($this->getPrimaryDsName());
        }
        if (!isset($this->primaryConnection)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    return $this->primaryConnection = $this->getConnection($dsConfig->getName());
                }
            }
        }
        throw new WinterException('Could not find Primary EmTransactionManager');
    }

    public function getConnection(string $name): Connection {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_CONN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }

        if ($this->coroutineScoped && isset($this->dsConfig[$name])) {
            return $this->getScopedConnection($name);
        }

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->connections[$name] = $this->buildConnection($this->dsConfig[$name]);
        }
        throw new WinterException('Could not find Database Connection with name "' . $name . '"');
    }

    public function getPrimaryEntityManager(): EntityManager {
        if ($this->coroutineScoped) {
            return $this->getEntityManager($this->getPrimaryDsName());
        }
        if (!isset($this->primaryEntityManager)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    $this->primaryEntityManager = $this->buildEntityManager($dsConfig);
                    return $this->primaryEntityManager;
                }
            }
            throw new WinterException('Could not find Primary EntityManager');
        }
        return $this->primaryEntityManager;
    }

    public function getEntityManager(string $name): EntityManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_EM_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }
        if ($this->coroutineScoped && isset($this->dsConfig[$name])) {
            return $this->getScopedEntityManager($name);
        }
        if (isset($this->entityManagers[$name])) {
            return $this->entityManagers[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->entityManagers[$name] = $this->buildEntityManager($this->dsConfig[$name]);
        }
        throw new WinterException('Could not find EntityManager with name "' . $name . '"');
    }

    private function getPrimaryDsName(): string {
        foreach ($this->dsConfig as $dsConfig) {
            if ($dsConfig->isPrimary()) {
                return $dsConfig->getName();
            }
        }
        throw new WinterException('Could not find Primary EntityManager');
    }

    /**
     * Coroutine-scoped façade (stable bean identity; delegates are per-scope).
     */
    private function getScopedEntityManager(string $name): WinterEntityManager {
        $facade = $this->entityManagers[$name] ?? null;
        if ($facade instanceof WinterEntityManager) {
            return $facade;
        }
        $ds = $this->dsConfig[$name];
        $pool = $this->emPools[$name] ?? null;
        if ($pool === null) {
            $pool = new CoroutineScopedPool(
                fn() => $this->buildDelegateEntityManager($ds),
                $this->scopes,
                function (EntityManager $em): void {
                    $this->destroyDelegateEntityManager($em);
                },
                fn(EntityManager $em) => $em->isOpen(),
                null,
                $name . '-em',
                $this->maxDelegates,
                $this->maxWaitMs
            );
            $this->emPools[$name] = $pool;
        }
        $facade = WinterEntityManager::create($pool);
        $this->entityManagers[$name] = $facade;
        return $facade;
    }

    /**
     * Coroutine-scoped Connection façade (stable bean identity).
     */
    private function getScopedConnection(string $name): Connection {
        $facade = $this->connections[$name] ?? null;
        if ($facade instanceof WinterConnection) {
            return $facade;
        }
        $ds = $this->dsConfig[$name];
        $pool = $this->connPools[$name] ?? null;
        if ($pool === null) {
            $pool = new CoroutineScopedPool(
                fn() => $this->buildFreshConnection($ds),
                $this->scopes,
                function (Connection $conn): void {
                    $this->destroyDelegateConnection($conn);
                },
                null,
                null,
                $name . '-dbal',
                $this->maxDelegates,
                $this->maxWaitMs
            );
            $this->connPools[$name] = $pool;
        }
        $facade = WinterConnection::create($pool);
        $this->connections[$name] = $facade;
        return $facade;
    }

    private function getSharedEventManager(DoctrineDbConfig $ds): EventManager {
        $name = $ds->getName();
        if (!isset($this->sharedEventManagers[$name])) {
            $this->sharedEventManagers[$name] = new EventManager();
        }
        return $this->sharedEventManagers[$name];
    }

    private function getOrmConfiguration(DoctrineDbConfig $ds): Configuration {
        $name = $ds->getName();
        if (!isset($this->ormConfigs[$name])) {
            $this->ormConfigs[$name] = OrmConfigurationFactory::create(
                $ds->getEntityPaths(),
                $ds->isDevMode()
            );
        }
        return $this->ormConfigs[$name];
    }

    /**
     * Build a virgin delegate: shared metadata config + shared event
     * manager, but its OWN DBAL connection (separate PDO per coroutine is
     * non-negotiable — sharing merges DB transactions across coroutines).
     */
    private function buildDelegateEntityManager(DoctrineDbConfig $ds): EntityManager {
        return new EntityManager(
            $this->buildFreshConnection($ds),
            $this->getOrmConfiguration($ds),
            $this->getSharedEventManager($ds)
        );
    }

    private function destroyDelegateEntityManager(EntityManager $em): void {
        try {
            $conn = $em->getConnection();
            if ($conn->isTransactionActive()) {
                try {
                    $conn->rollBack();
                } catch (Throwable $e) {
                    self::logException($e, 'Rollback of leftover transaction failed');
                }
            }
        } catch (Throwable) {
            // Fall through to close attempts.
        }
        try {
            if ($em->isOpen()) {
                $em->close();
            }
        } catch (Throwable $e) {
            self::logException($e, 'Delegate EntityManager close failed');
        }
        try {
            $em->getConnection()->close();
        } catch (Throwable $e) {
            self::logException($e, 'Delegate Connection close failed');
        }
    }

    private function destroyDelegateConnection(Connection $conn): void {
        try {
            if ($conn->isTransactionActive()) {
                try {
                    $conn->rollBack();
                } catch (Throwable $e) {
                    self::logException($e, 'Rollback of leftover transaction failed');
                }
            }
        } catch (Throwable) {
            // Fall through to close.
        }
        try {
            $conn->close();
        } catch (Throwable $e) {
            self::logException($e, 'Delegate Connection close failed');
        }
    }

    /**
     * Observability for operations: active delegate counts per pool.
     *
     * @return array<string, int> pool name => active delegate count
     */
    public function getActiveDelegateCounts(): array {
        $out = [];
        foreach ($this->emPools as $name => $pool) {
            $out[$name . '-em'] = $pool->getActiveDelegateCount();
        }
        foreach ($this->connPools as $name => $pool) {
            $out[$name . '-dbal'] = $pool->getActiveDelegateCount();
        }
        return $out;
    }

    /**
     * @return DoctrineDbConfig[]
     */
    public function getDoctrineDbConfig(): array {
        return $this->dsConfig;
    }

    private function buildEntityManager(DoctrineDbConfig $ds): EntityManager {
        if (isset($this->dsObjectMap[$ds])) {
            return $this->dsObjectMap[$ds];
        }

        $config = OrmConfigurationFactory::create(
            $ds->getEntityPaths(),
            $ds->isDevMode()
        );

        $obj = new EntityManager($this->buildConnection($ds), $config);

        $this->dsObjectMap[$ds] = $obj;

        return $obj;
    }

    private function buildConnection(DoctrineDbConfig $ds): Connection {
        if (isset($this->dsConnectMap[$ds])) {
            return $this->dsConnectMap[$ds];
        }

        $connection = $this->buildFreshConnection($ds);
        $this->dsConnectMap[$ds] = $connection;

        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $this->ctx->beanByClass(IdleCheckRegistry::class);
        $idleCheck->register(function () use ($connection) {
            try {
                if (!$connection->isConnected()) {
                    return;
                }
                $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            } catch (Throwable $e) {
                self::logException($e, 'Doctrine idle connection check failed');
                try {
                    $connection->close();
                } catch (Throwable $ignored) {
                }
            }
        });

        $idleCheck->register(function () {
            $counts = $this->getActiveDelegateCounts();
            if (!empty($counts)) {
                self::logDebug('Doctrine open DB connections: ' . json_encode($counts));
            }
        });

        return $connection;
    }

    /**
     * Always builds a brand-new connection (own PDO). Used for every
     * coroutine delegate; never memoized.
     */
    private function buildFreshConnection(DoctrineDbConfig $ds): Connection {
        $dbParams = $this->dsParams[$ds->getName()];
        return DriverManager::getConnection($dbParams, $this->getOrmConfiguration($ds));
    }
}
