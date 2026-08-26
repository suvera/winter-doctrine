<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\multitenancy;

use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Single autowirable bean for multi-tenant Doctrine access.
 *
 * All methods accept an explicit `$tenantId` — the caller is responsible
 * for knowing which tenant it's operating on (typically resolved from the
 * request context by the application layer).
 *
 * Internally maintains lazy pools of Connection and EntityManager per
 * tenant, so repeated calls for the same tenant reuse existing connections.
 *
 * ## Autowiring
 *
 * ```php
 * #[Autowired("app-doctrine-tenant")]
 * private TenantDoctrineProvider $doctrine;
 *
 * public function doWork(string $tenantId): void {
 *     $em = $this->doctrine->getEntityManager($tenantId);
 *     $em->persist($entity);
 *     $em->flush();
 * }
 * ```
 */
class TenantDoctrineProvider {

    /**
     * @var array<string, Connection> pool of per-tenant connections
     */
    private array $tenantConnections = [];

    /**
     * @var array<string, EntityManager> pool of per-tenant entity managers
     */
    private array $tenantEntityManagers = [];

    /**
     * @var array<string, EmTransactionManager> pool of per-tenant ORM transaction managers
     */
    private array $tenantEmTransactionManagers = [];

    /**
     * @var array<string, DbalTransactionManager> pool of per-tenant DBAL transaction managers
     */
    private array $tenantDbalTransactionManagers = [];

    private readonly Configuration $ormConfig;

    /**
     * @param array<string, mixed>         $baseParams  the template connection params (driver, host, user, password)
     * @param TenantConnectionProvider     $provider    resolves tenant-specific params (dbname, etc.)
     * @param string[]                     $entityPaths paths to Doctrine entity classes
     * @param bool                         $isDevMode   whether to run in dev mode
     */
    public function __construct(
        private readonly array $baseParams,
        private readonly TenantConnectionProvider $provider,
        array $entityPaths,
        bool $isDevMode = false,
    ) {
        $this->ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            $entityPaths,
            $isDevMode,
            null,
            new ArrayAdapter(),
            false
        );
    }

    // ──────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────

    /**
     * Get the EntityManager for the given tenant.
     *
     * The EM is lazily created on first access and cached for subsequent
     * calls with the same tenant ID.
     */
    public function getEntityManager(string $tenantId): EntityManager {
        if (!isset($this->tenantEntityManagers[$tenantId])) {
            $this->tenantEntityManagers[$tenantId] = $this->createEntityManager($tenantId);
        }
        return $this->tenantEntityManagers[$tenantId];
    }

    /**
     * Get the DBAL Connection for the given tenant.
     *
     * The connection is lazily created on first access and cached for
     * subsequent calls with the same tenant ID.
     */
    public function getConnection(string $tenantId): Connection {
        if (!isset($this->tenantConnections[$tenantId])) {
            $this->tenantConnections[$tenantId] = $this->createConnection($tenantId);
        }
        return $this->tenantConnections[$tenantId];
    }

    /**
     * Get the ORM TransactionManager for the given tenant.
     *
     * Backed by the tenant-specific EntityManager.
     */
    public function getEmTransactionManager(string $tenantId): EmTransactionManager {
        if (!isset($this->tenantEmTransactionManagers[$tenantId])) {
            $this->tenantEmTransactionManagers[$tenantId] = new EmTransactionManager(
                $this->getEntityManager($tenantId)
            );
        }
        return $this->tenantEmTransactionManagers[$tenantId];
    }

    /**
     * Get the DBAL TransactionManager for the given tenant.
     *
     * Backed by the tenant-specific Connection.
     */
    public function getDbalTransactionManager(string $tenantId): DbalTransactionManager {
        if (!isset($this->tenantDbalTransactionManagers[$tenantId])) {
            $this->tenantDbalTransactionManagers[$tenantId] = new DbalTransactionManager(
                $this->getConnection($tenantId)
            );
        }
        return $this->tenantDbalTransactionManagers[$tenantId];
    }

    // ──────────────────────────────────────────────
    // Internal factory methods
    // ──────────────────────────────────────────────

    private function createConnection(string $tenantId): Connection {
        $params = $this->provider->resolveParams($tenantId, $this->baseParams);
        return DriverManager::getConnection($params);
    }

    private function createEntityManager(string $tenantId): EntityManager {
        return new EntityManager(
            $this->getConnection($tenantId),
            $this->ormConfig
        );
    }

    // ──────────────────────────────────────────────
    // Lifecycle / introspection
    // ──────────────────────────────────────────────

    /**
     * Close all cached connections and entity managers.
     */
    public function close(): void {
        foreach ($this->tenantEntityManagers as $em) {
            $em->close();
        }
        foreach ($this->tenantConnections as $conn) {
            $conn->close();
        }
        $this->tenantEntityManagers = [];
        $this->tenantConnections = [];
        $this->tenantEmTransactionManagers = [];
        $this->tenantDbalTransactionManagers = [];
    }

    /**
     * Evict a specific tenant from all pools (force reconnect on next access).
     */
    public function evictTenant(string $tenantId): void {
        if (isset($this->tenantEntityManagers[$tenantId])) {
            $this->tenantEntityManagers[$tenantId]->close();
            unset($this->tenantEntityManagers[$tenantId]);
        }
        if (isset($this->tenantConnections[$tenantId])) {
            $this->tenantConnections[$tenantId]->close();
            unset($this->tenantConnections[$tenantId]);
        }
        unset(
            $this->tenantEmTransactionManagers[$tenantId],
            $this->tenantDbalTransactionManagers[$tenantId]
        );
    }

    /**
     * Return all currently-cached tenant IDs (for monitoring).
     *
     * @return string[]
     */
    public function getCachedTenantIds(): array {
        return array_keys($this->tenantConnections);
    }
}