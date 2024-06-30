<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\type\Arrays;

class DoctrineDbConfig extends DataSourceConfig {
    #[JsonProperty("doctrine.entityPaths")]
    private array $entityPaths = [];

    #[JsonProperty("doctrine.isDevMode")]
    private bool $isDevMode = false;

    private array $doctrineOptions = [];

    private string $name;

    #[JsonProperty("isPrimary")]
    private bool $primary = false;

    private string $url;
    private string $username = '';
    private string $password = '';

    private string $validationQuery = '';
    private string $driverClass;

    #[JsonProperty("connection.persistent")]
    private bool $persistent = false;

    #[JsonProperty("connection.errorMode")]
    private string $errorMode = 'ERRMODE_EXCEPTION';

    #[JsonProperty("connection.columnsCase")]
    private string $columnsCase = 'ERRMODE_EXCEPTION';

    #[JsonProperty("connection.timeoutSecs")]
    private int $timeoutSecs = 30;

    #[JsonProperty("connection.autoCommit")]
    private bool $autoCommit = true;

    #[JsonProperty("connection.rowsPrefetch")]
    private int $rowsPrefetch = 100;

    #[JsonProperty("connection.idleTimeout")]
    private int $idleTimeout = 600;

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

    public function getDoctrineOptions(): array {
        return $this->doctrineOptions;
    }

    public function setDoctrineOptions(array $doctrineOptions): void {
        $this->doctrineOptions = $doctrineOptions;
    }

    public function parseDoctrineParams(array $dataSource) {
        $data = $this->unFlatten($dataSource);
        if (isset($data['doctrine']) && is_array($data['doctrine'])) {
            unset($data['doctrine']['isDevMode'], $data['doctrine']['entityPaths']);
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
