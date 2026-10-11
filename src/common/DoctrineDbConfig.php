<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\exception\WinterException;
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\type\Arrays;

class DoctrineDbConfig extends DataSourceConfig {
    #[JsonProperty("doctrine.entityPaths")]
    protected array $entityPaths = [];

    #[JsonProperty("doctrine.isDevMode")]
    protected bool $isDevMode = false;

    /**
     * SQLFilters enabled on every EntityManager. Either YAML form works:
     *   doctrine: { filters: { tenant: App\Orm\TenantFilter } }
     *   doctrine: { filters: [ { name: tenant, class: App\Orm\TenantFilter } ] }
     * The map form arrives flattened (doctrine.filters.tenant) and is
     * picked up in parseDoctrineParams().
     */
    #[JsonProperty("doctrine.filters")]
    protected array $filters = [];



    protected array $doctrineOptions = [];

    // Pool caps (`connection.maxConnections` / `connection.maxWaitMs`)
    // are inherited from winter-boot's DataSourceConfig (Track A): the
    // per-datasource getters getMaxConnections()/getMaxWaitMs() carry
    // explicit overrides, defaulting to the shipped 50/5000.


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

    /**
     * @return array<string, class-string> name => class
     * @throws WinterException on a malformed entry (a filter that silently
     *         drops out could be a tenant boundary)
     */
    public function getFilters(): array {
        $out = [];
        foreach ($this->filters as $key => $value) {
            if (is_array($value) && isset($value['name'], $value['class'])
                && is_string($value['name']) && is_string($value['class'])) {
                $out[$value['name']] = $value['class'];
            } elseif (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            } else {
                throw new WinterException('Invalid doctrine.filters entry ' . json_encode([$key => $value])
                    . ' in datasource ' . $this->getName()
                    . ': expected name: class, or { name: ..., class: ... }');
            }
        }
        return $out;
    }

    public function setFilters(array $filters): void {
        $this->filters = $filters;
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
            // The map form of doctrine.filters is flattened to
            // doctrine.filters.<name>, so the JsonProperty never sees it.
            if ($this->filters === [] && isset($data['doctrine']['filters'])
                && is_array($data['doctrine']['filters'])) {
                $this->filters = $data['doctrine']['filters'];
            }
            unset(
                $data['doctrine']['isDevMode'],
                $data['doctrine']['entityPaths'],
                $data['doctrine']['filters']
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
