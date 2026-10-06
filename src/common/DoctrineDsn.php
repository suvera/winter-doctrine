<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

/**
 * Turns a winter-boot datasource url (PDO-style DSN, e.g.
 * "mysql:host=localhost;port=3306;dbname=app") into DBAL connection
 * parameters. Used for both standard and tenant datasources, so they
 * connect the same way.
 *
 * The DSN prefix is the DBAL driver name ("pdo_mysql", "pdo_pgsql",
 * "pgsql", ...). PDO prefixes that are not DBAL driver names are mapped to
 * their PDO driver: "mysql" => "pdo_mysql", "oci" => "pdo_oci". Prefixes
 * DBAL already knows ("pgsql", "sqlsrv", ...) are passed through unchanged,
 * so existing configs keep their driver. "sqlite" keeps its historic mapping to
 * the sqlite3 driver, and falls back to pdo_sqlite when ext-sqlite3 is
 * missing but PDO has its sqlite driver.
 */
final class DoctrineDsn {

    /**
     * PDO DSN prefixes that DBAL does not know as driver names.
     */
    private const PDO_PREFIX_DRIVERS = [
        'mysql' => 'pdo_mysql',
        'oci' => 'pdo_oci',
    ];

    private function __construct() {
    }

    public static function toParams(string $url): array {
        $config = [];
        $parts = explode(':', $url, 2);
        $driver = strtolower(trim($parts[0]));

        if ($driver === 'sqlite') {
            $config['driver'] = self::sqliteDriver();
            $path = $parts[1] ?? '';
            if ($path === '' || $path === ':memory:') {
                $config['memory'] = true;
            } else {
                $config['path'] = $path;
            }
            return $config;
        }

        $config['driver'] = self::PDO_PREFIX_DRIVERS[$driver] ?? $driver;

        if (!isset($parts[1])) {
            return $config;
        }
        foreach (explode(';', $parts[1]) as $keyValue) {
            $kv = explode('=', $keyValue, 2);
            if (isset($kv[1]) && trim($kv[0]) !== '') {
                $config[trim($kv[0])] = $kv[1];
            }
        }

        return $config;
    }

    /**
     * DBAL params for a datasource: DSN params, then explicit doctrine.*
     * options, then the configured username/password (explicit config wins
     * over credentials embedded in the DSN).
     */
    public static function connectionParams(
        string $url,
        string $username = '',
        string $password = '',
        array $doctrineOptions = []
    ): array {
        $params = self::toParams($url);
        foreach ($doctrineOptions as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $params[$key] = $value;
        }
        if ($username !== '') {
            $params['user'] = $username;
        }
        if ($password !== '') {
            $params['password'] = $password;
        }
        return $params;
    }

    private static function sqliteDriver(): string {
        if (!class_exists('SQLite3', false)
            && class_exists('PDO', false)
            && in_array('sqlite', \PDO::getAvailableDrivers(), true)
        ) {
            return 'pdo_sqlite';
        }
        return 'sqlite3';
    }
}
