<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\multitenancy;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\exception\BeansDependencyException;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\pdbc\multitenant\TenantDataSourceProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;

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

    public function __construct(
        private string $providerClassName,
        private ApplicationContext $appCtx
    ) {
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
        
        $configObj = ORMSetup::createAttributeMetadataConfiguration(
            [],
            false,
            null,
            new ArrayAdapter(),
            false
        );
        
        return new EntityManager($this->buildConnection($config, $tenantId), $configObj);
    }

    /**
     * Close all cached connections and entity managers.
     */
    public function close(): void {
        foreach ($this->entityManagers as $em) {
            $em->close();
        }
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->entityManagers = [];
        $this->connections = [];
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
        if (isset($this->entityManagers[$tenantId])) {
            $this->entityManagers[$tenantId]->close();
            unset($this->entityManagers[$tenantId]);
        }
        if (isset($this->connections[$tenantId])) {
            $this->connections[$tenantId]->close();
            unset($this->connections[$tenantId]);
        }
        unset(
            $this->emTransactionManagers[$tenantId],
            $this->dbalTransactionManagers[$tenantId],
            $this->tenantConfigs[$tenantId]
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
