<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\multitenancy;

/**
 * Implement this interface to provide tenant-specific database connection
 * parameters at runtime.
 *
 * A typical implementation queries an "admin" database (or any other
 * configuration store) to look up the host, port, dbname, user, and
 * password for the given tenant identifier.
 *
 * The returned array MUST contain at minimum:
 *   - 'driver'   (e.g. 'pdo_mysql', 'pdo_pgsql')
 *   - 'dbname'   (the tenant-specific database name)
 *
 * It MAY override any other Doctrine connection parameter (host, port,
 * user, password, charset, etc.).
 */
interface TenantConnectionProvider {

    /**
     * Resolve connection parameters for the given tenant.
     *
     * @param string $tenantId  the tenant identifier
     * @param array  $baseParams the base/template connection params from config
     * @return array complete Doctrine DBAL connection params for this tenant
     */
    public function resolveParams(string $tenantId, array $baseParams): array;
}