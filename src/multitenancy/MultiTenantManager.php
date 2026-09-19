<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\multitenancy;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\coroutine\CoroutineScopeProvider;
use dev\winterframework\coroutine\CoroutineScopeProviders;
use dev\winterframework\coroutine\CoroutineScopedPool;
use dev\winterframework\doctrine\common\OrmConfigurationFactory;
use dev\winterframework\coroutine\SwooleCoroutineScopeProvider;
use dev\winterframework\doctrine\dbal\WinterConnection;
use dev\winterframework\doctrine\orm\WinterEntityManager;
use dev\winterframework\exception\BeansDependencyException;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\multitenant\TenantDataSourceProvider;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\DriverManager;
use Throwable;

/**
 * Manager for multi-tenant Doctrine access.
 *
 * Provides cached per-tenant {@link EntityManager}, {@link Connection},
 * {@link EmTransactionManager}, and {@link DbalTransactionManager} instances.
 *
 * ## Configuration
 *
 * In your application.yml:
 *
 * ```yaml
 * multitenant-datasource:
 *     - name: "tenantdb"
 *       url: "mysql:host=localhost;port=3306"
 *       providerClass: "App\\Config\\MyTenantDataSourceProvider"
 * ```
 *
 * ## Usage
 *
 * ```php
 * #[Autowired]
 * private MultiTenantManager $mt;
 *
 * public function doWork(string $tenantId): void {
 *     $em = $this->mt->getEntityManager($tenantId);
 *     $em->persist($entity);
 *     $em->flush();
 * }
 * ```
 */
class MultiTenantManager {

    /**
     * @var array<string, EntityManager>
     */
    private array $entityManagers = [];

    /**
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * @var array<string, EmTransactionManager>
     */
    private array $emTransactionManagers = [];

    /**
     * @var array<string, DbalTransactionManager>
     */
    private array $dbalTransactionManagers = [];

    /**
     * @var array<string, DataSourceConfig>
     */
    private array $tenantConfigs = [];

    private ?TenantDataSourceProvider $tenantDataSourceProvider = null;

    /**
     * @var array<string, CoroutineScopedPool>
     */
    private array $emPools = [];

    /**
     * @var array<string, CoroutineScopedPool>
     */
    private array $connPools = [];

    /**
     * @var array<string, Configuration>
     */
    private array $ormConfigs = [];

    /**
     * @var array<string, EventManager>
     */
    private array $sharedEventManagers = [];

    private CoroutineScopeProvider $scopes;
    private bool $coroutineScoped;
    private int $maxDelegates = 50;
    private int $maxWaitMs = 5000;

    /**
     * Shipped pool defaults. A tenant config still carrying these values
     * is treated as "no per-tenant override" and the global caps apply:
     * Track A's getters are non-nullable, so an untouched config cannot
     * be told apart from one explicitly set to the defaults, and that
     * edge collapses toward the global, which is the safe direction.
     */
    private const SHIPPED_DEFAULT_MAX_DELEGATES = 50;
    private const SHIPPED_DEFAULT_MAX_WAIT_MS = 5000;

    public function __construct(
        private string $providerClassName,
        private ApplicationContext $appCtx,
        ?CoroutineScopeProvider $scopes = null,
        ?bool $coroutineScoped = null,
        int $maxDelegates = 50,
        int $maxWaitMs = 5000
    ) {
        $this->scopes = $scopes ?? CoroutineScopeProviders::shared();
        $this->coroutineScoped = $coroutineScoped ?? SwooleCoroutineScopeProvider::isAvailable();
        $this->maxDelegates = max(0, $maxDelegates);
        $this->maxWaitMs = max(0, $maxWaitMs);
    }

    public function isCoroutineScoped(): bool {
        return $this->coroutineScoped;
    }

    public function setCoroutineScoped(bool $coroutineScoped): void {
        $this->coroutineScoped = $coroutineScoped;
    }

    /**
     * Pool caps for one tenant: the per-datasource `connection.*`
     * overrides win (read off the tenant config's Track A getters when
     * winter-boot exposes them), then the global caps, then the shipped
     * defaults (50 connections, 5000ms wait).
     *
     * @return array{0: int, 1: int} [maxDelegates, maxWaitMs]
     */
    private function resolvePoolCaps(DataSourceConfig $config): array {
        $max = $this->maxDelegates;
        if (method_exists($config, 'getMaxConnections')) {
            $perDs = (int)$config->getMaxConnections();
            if ($perDs !== self::SHIPPED_DEFAULT_MAX_DELEGATES) {
                $max = $perDs;
            }
        }
        $wait = $this->maxWaitMs;
        if (method_exists($config, 'getMaxWaitMs')) {
            $perDs = (int)$config->getMaxWaitMs();
            if ($perDs !== self::SHIPPED_DEFAULT_MAX_WAIT_MS) {
                $wait = $perDs;
            }
        }
        return [max(0, $max), max(0, $wait)];
    }

    /**
     * Get the tenant data source provider.
     *
     * @return TenantDataSourceProvider
     */
    public function getTenantDataSourceProvider(): TenantDataSourceProvider {
        if ($this->tenantDataSourceProvider === null) {
            if (!$this->appCtx->hasBeanByClass($this->providerClassName)) {
                throw new BeansDependencyException(
                    'TenantDataSourceProvider bean not found for class: ' . $this->providerClassName
                );
            }
            $this->tenantDataSourceProvider = $this->appCtx->beanByClass($this->providerClassName);
        }
        return $this->tenantDataSourceProvider;
    }

    /**
     * Get an EntityManager for the given tenant.
     * EntityManagers are cached per tenant ID.
     *
     * @param string $tenantId
     * @return EntityManager
     */
    public function getEntityManager(string $tenantId): EntityManager {
        if ($this->coroutineScoped) {
            return $this->getScopedEntityManager($tenantId);
        }
        if (!isset($this->entityManagers[$tenantId])) {
            $config = $this->getTenantConfig($tenantId);
            $this->entityManagers[$tenantId] = $this->buildEntityManager($config, $tenantId);
        }
        return $this->entityManagers[$tenantId];
    }

    /**
     * Get a Connection for the given tenant.
     * Connections are cached per tenant ID.
     *
     * @param string $tenantId
     * @return Connection
     */
    public function getConnection(string $tenantId): Connection {
        if ($this->coroutineScoped) {
            return $this->getScopedConnection($tenantId);
        }
        if (!isset($this->connections[$tenantId])) {
            $config = $this->getTenantConfig($tenantId);
            $this->connections[$tenantId] = $this->buildConnection($config, $tenantId);
        }
        return $this->connections[$tenantId];
    }

    /**
     * Get an EmTransactionManager for the given tenant.
     * Transaction managers are cached per tenant ID.
     *
     * @param string $tenantId
     * @return EmTransactionManager
     */
    public function getEmTransactionManager(string $tenantId): EmTransactionManager {
        if (!isset($this->emTransactionManagers[$tenantId])) {
            $this->emTransactionManagers[$tenantId] = new EmTransactionManager(
                $this->getEntityManager($tenantId)
            );
        }
        return $this->emTransactionManagers[$tenantId];
    }

    /**
     * Get a DbalTransactionManager for the given tenant.
     * Transaction managers are cached per tenant ID.
     *
     * @param string $tenantId
     * @return DbalTransactionManager
     */
    public function getDbalTransactionManager(string $tenantId): DbalTransactionManager {
        if (!isset($this->dbalTransactionManagers[$tenantId])) {
            $this->dbalTransactionManagers[$tenantId] = new DbalTransactionManager(
                $this->getConnection($tenantId)
            );
        }
        return $this->dbalTransactionManagers[$tenantId];
    }

    /**
     * Get or create a DataSourceConfig for the given tenant.
     *
     * @param string $tenantId
     * @return DataSourceConfig
     */
    private function getTenantConfig(string $tenantId): DataSourceConfig {
        if (!isset($this->tenantConfigs[$tenantId])) {
            $provider = $this->getTenantDataSourceProvider();
            $this->tenantConfigs[$tenantId] = $provider->getTenantDataSourceConfig($tenantId);
        }
        return $this->tenantConfigs[$tenantId];
    }

    /**
     * Coroutine-scoped façade per tenant (stable return identity; delegates
     * are keyed by tenant + coroutine scope).
     */
    private function getScopedEntityManager(string $tenantId): WinterEntityManager {
        $facade = $this->entityManagers[$tenantId] ?? null;
        if ($facade instanceof WinterEntityManager) {
            return $facade;
        }
        if (!isset($this->emPools[$tenantId])) {
            [$maxDelegates, $maxWaitMs] = $this->resolvePoolCaps($this->getTenantConfig($tenantId));
            $this->emPools[$tenantId] = new CoroutineScopedPool(
                fn() => $this->buildDelegateEntityManager($tenantId),
                $this->scopes,
                function (EntityManager $em): void {
                    $this->destroyDelegateEntityManager($em);
                },
                fn(EntityManager $em) => $em->isOpen(),
                null,
                $tenantId . '-em',
                $maxDelegates,
                $maxWaitMs
            );
        }
        $facade = WinterEntityManager::create($this->emPools[$tenantId]);
        $this->entityManagers[$tenantId] = $facade;
        return $facade;
    }

    private function getScopedConnection(string $tenantId): WinterConnection {
        $facade = $this->connections[$tenantId] ?? null;
        if ($facade instanceof WinterConnection) {
            return $facade;
        }
        if (!isset($this->connPools[$tenantId])) {
            [$maxDelegates, $maxWaitMs] = $this->resolvePoolCaps($this->getTenantConfig($tenantId));
            $this->connPools[$tenantId] = new CoroutineScopedPool(
                fn() => $this->buildDelegateConnection($tenantId),
                $this->scopes,
                function (Connection $conn): void {
                    $this->destroyDelegateConnection($conn);
                },
                null,
                null,
                $tenantId . '-dbal',
                $maxDelegates,
                $maxWaitMs
            );
        }
        $facade = WinterConnection::create($this->connPools[$tenantId]);
        $this->connections[$tenantId] = $facade;
        return $facade;
    }

    private function getTenantOrmConfiguration(string $tenantId): Configuration {
        if (!isset($this->ormConfigs[$tenantId])) {
            $this->ormConfigs[$tenantId] = OrmConfigurationFactory::create([], false);
        }
        return $this->ormConfigs[$tenantId];
    }

    private function getTenantEventManager(string $tenantId): EventManager {
        if (!isset($this->sharedEventManagers[$tenantId])) {
            $this->sharedEventManagers[$tenantId] = new EventManager();
        }
        return $this->sharedEventManagers[$tenantId];
    }

    private function tenantDbParams(DataSourceConfig $config): array {
        $dbParams = ['url' => $config->getUrl()];
        if ($config->getUsername()) {
            $dbParams['user'] = $config->getUsername();
        }
        if ($config->getPassword()) {
            $dbParams['password'] = $config->getPassword();
        }
        return $dbParams;
    }

    private function buildDelegateConnection(string $tenantId): Connection {
        $config = $this->getTenantConfig($tenantId);
        return DriverManager::getConnection(
            $this->tenantDbParams($config),
            $this->getTenantOrmConfiguration($tenantId)
        );
    }

    private function buildDelegateEntityManager(string $tenantId): EntityManager {
        return new EntityManager(
            $this->buildDelegateConnection($tenantId),
            $this->getTenantOrmConfiguration($tenantId),
            $this->getTenantEventManager($tenantId)
        );
    }

    private function destroyDelegateEntityManager(EntityManager $em): void {
        try {
            if ($em->getConnection()->isTransactionActive()) {
                try {
                    $em->getConnection()->rollBack();
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        }
        try {
            if ($em->isOpen()) {
                $em->close();
            }
        } catch (Throwable) {
        }
        try {
            $em->getConnection()->close();
        } catch (Throwable) {
        }
    }

    private function destroyDelegateConnection(Connection $conn): void {
        try {
            if ($conn->isTransactionActive()) {
                try {
                    $conn->rollBack();
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        }
        try {
            $conn->close();
        } catch (Throwable) {
        }
    }

    private function buildConnection(DataSourceConfig $config, string $tenantId): Connection {
        $url = $config->getUrl();
        $user = $config->getUsername();
        $password = $config->getPassword();
        
        $dbParams = [
            'url' => $url,
        ];
        
        if ($user) {
            $dbParams['user'] = $user;
        }
        if ($password) {
            $dbParams['password'] = $password;
        }
        
        return DriverManager::getConnection($dbParams);
    }

    private function buildEntityManager(DataSourceConfig $config, string $tenantId): EntityManager {
        $url = $config->getUrl();
        $user = $config->getUsername();
        $password = $config->getPassword();
        
        $dbParams = [
            'url' => $url,
        ];
        
        if ($user) {
            $dbParams['user'] = $user;
        }
        if ($password) {
            $dbParams['password'] = $password;
        }
        
        $configObj = OrmConfigurationFactory::create([], false);
        
        return new EntityManager($this->buildConnection($config, $tenantId), $configObj);
    }

    /**
     * Close all cached connections and entity managers.
     */
    public function close(): void {
        foreach ($this->emPools as $pool) {
            $pool->closeAll();
        }
        foreach ($this->connPools as $pool) {
            $pool->closeAll();
        }
        foreach ($this->entityManagers as $em) {
            try {
                $em->close();
            } catch (Throwable) {
            }
        }
        foreach ($this->connections as $conn) {
            try {
                $conn->close();
            } catch (Throwable) {
            }
        }
        $this->entityManagers = [];
        $this->connections = [];
        $this->emPools = [];
        $this->connPools = [];
        $this->ormConfigs = [];
        $this->sharedEventManagers = [];
        $this->emTransactionManagers = [];
        $this->dbalTransactionManagers = [];
        $this->tenantConfigs = [];
    }

    /**
     * Evict a specific tenant from all pools (force reconnect on next access).
     *
     * @param string $tenantId
     */
    public function evictTenant(string $tenantId): void {
        if (isset($this->emPools[$tenantId])) {
            $this->emPools[$tenantId]->closeAll();
            unset($this->emPools[$tenantId]);
        }
        if (isset($this->connPools[$tenantId])) {
            $this->connPools[$tenantId]->closeAll();
            unset($this->connPools[$tenantId]);
        }
        if (isset($this->entityManagers[$tenantId])) {
            try {
                $this->entityManagers[$tenantId]->close();
            } catch (Throwable) {
            }
            unset($this->entityManagers[$tenantId]);
        }
        if (isset($this->connections[$tenantId])) {
            try {
                $this->connections[$tenantId]->close();
            } catch (Throwable) {
            }
            unset($this->connections[$tenantId]);
        }
        unset(
            $this->emTransactionManagers[$tenantId],
            $this->dbalTransactionManagers[$tenantId],
            $this->tenantConfigs[$tenantId],
            $this->ormConfigs[$tenantId],
            $this->sharedEventManagers[$tenantId]
        );
    }

    /**
     * Return all currently-cached tenant IDs (for monitoring).
     *
     * @return string[]
     */
    public function getCachedTenantIds(): array {
        return array_keys($this->connections);
    }
}
