<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\type\Arrays;

class DoctrineDbConfig extends DataSourceConfig {
    #[JsonProperty("doctrine.entityPaths")]
    protected array $entityPaths = [];

    #[JsonProperty("doctrine.isDevMode")]
    protected bool $isDevMode = false;

    /**
     * When true, this datasource acts as a multi-tenant template.
     * Instead of building a single Connection/EntityManager, the builder
     * creates TenantAwareConnection / TenantAwareEntityManager wrappers
     * that resolve the actual per-tenant database at runtime.
     */
    #[JsonProperty("doctrine.tenantTemplate")]
    protected bool $tenantTemplate = false;

    /**
     * Fully-qualified class name of a {@link TenantConnectionProvider}
     * implementation. Required when tenantTemplate is true.
     */
    #[JsonProperty("doctrine.tenantConnectionProvider")]
    protected string $tenantConnectionProvider = '';

    protected array $doctrineOptions = [];


    public function getEntityPaths(): array {
        return $this->entityPaths;
    }

    public function setEntityPaths(array $entityPaths): void {
        $this->entityPaths = $entityPaths;
    }

    public function isDevMode(): bool {
        return $this->isDevMode;
    }

    public function setDevMode(bool $isDevMode): void {
        $this->isDevMode = $isDevMode;
    }

    public function isTenantTemplate(): bool {
        return $this->tenantTemplate;
    }

    public function setTenantTemplate(bool $tenantTemplate): void {
        $this->tenantTemplate = $tenantTemplate;
    }

    public function getTenantConnectionProvider(): string {
        return $this->tenantConnectionProvider;
    }

    public function setTenantConnectionProvider(string $tenantConnectionProvider): void {
        $this->tenantConnectionProvider = $tenantConnectionProvider;
    }

    public function getDoctrineOptions(): array {
        return $this->doctrineOptions;
    }

    public function setDoctrineOptions(array $doctrineOptions): void {
        $this->doctrineOptions = $doctrineOptions;
    }

    public function parseDoctrineParams(array $dataSource) {
        $data = $this->unFlatten($dataSource);
        if (isset($data['doctrine']) && is_array($data['doctrine'])) {
            unset(
                $data['doctrine']['isDevMode'],
                $data['doctrine']['entityPaths'],
                $data['doctrine']['tenantTemplate'],
                $data['doctrine']['tenantConnectionProvider']
            );
            $this->doctrineOptions = $data['doctrine'];
        }
    }

    public function unFlatten(array $data): array {
        $output = [];
        foreach ($data as $key => $value) {
            $parts = explode('.', $key);
            $nested = &$output;
            while (count($parts) > 1) {
                $nested = &$nested[array_shift($parts)];
                if (!is_array($nested)) $nested = [];
            }
            $nested[array_shift($parts)] = $value;
        }
        return $output;
    }
}
