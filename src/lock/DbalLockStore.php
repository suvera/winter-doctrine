<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\lock;

use dev\winterframework\core\System;
use dev\winterframework\doctrine\common\IsolationCapable;
use dev\winterframework\exception\WinterException;
use dev\winterframework\util\concurrent\LockStore;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;

/**
 * Lock leases in a database table, through a Doctrine DBAL connection.
 * Same table layout as winter-boot's PdoLockStore, so both can share it:
 *
 *   lock_name  VARCHAR(191) PRIMARY KEY   (names longer than 191 bytes are hashed)
 *   owner      VARCHAR(64)                random token of the holding handle
 *   expires_at BIGINT                     epoch milliseconds; 0 = until released
 *
 * With a winter-doctrine connection bean (`<ds>-doctrine-dbal`) every
 * statement runs on an isolated connection and commits at once, so a lock
 * is visible to other pods immediately, even inside the caller's
 * transaction. A plain DBAL Connection must not be inside a transaction
 * when a lock is taken; that is refused rather than silently unsafe.
 *
 * The table is created on first use with the platform's own types, so it
 * works on every database DBAL supports. Expiry uses the application
 * clock, so keep pod clocks in sync (NTP).
 */
class DbalLockStore implements LockStore {
    use Wlf4p;

    public const DEFAULT_TABLE = 'winter_locks';
    public const MAX_NAME_BYTES = 191;

    private bool $tableReady;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = self::DEFAULT_TABLE,
        bool $createTable = true,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table)) {
            throw new WinterException('Invalid lock table name');
        }
        $this->tableReady = !$createTable;
    }

    public function acquire(string $name, string $owner, int $ttlMs): bool {
        $key = self::key($name);
        return $this->run(function (Connection $c) use ($key, $owner, $ttlMs): bool {
            $now = System::currentTimeMillis();
            $expires = $ttlMs > 0 ? $now + $ttlMs : 0;
            try {
                $c->transactional(fn(Connection $t) => $t->executeStatement(
                    'INSERT INTO ' . $this->table . ' (lock_name, owner, expires_at) VALUES (?, ?, ?)',
                    [$key, $owner, $expires]
                ));
                return true;
            } catch (UniqueConstraintViolationException) {
                // Held already: take it over only if the holder's lease has expired.
            }
            return (int)$c->transactional(fn(Connection $t) => $t->executeStatement(
                'UPDATE ' . $this->table . ' SET owner = ?, expires_at = ?'
                . ' WHERE lock_name = ? AND expires_at > 0 AND expires_at < ?',
                [$owner, $expires, $key, $now]
            )) === 1;
        });
    }

    public function release(string $name, string $owner): bool {
        $key = self::key($name);
        return $this->run(fn(Connection $c): bool => (int)$c->transactional(fn(Connection $t) => $t->executeStatement(
            'DELETE FROM ' . $this->table . ' WHERE lock_name = ? AND owner = ?',
            [$key, $owner]
        )) === 1);
    }

    public function refresh(string $name, string $owner, int $ttlMs): bool {
        $key = self::key($name);
        return $this->run(function (Connection $c) use ($key, $owner, $ttlMs): bool {
            $now = System::currentTimeMillis();
            return (int)$c->transactional(fn(Connection $t) => $t->executeStatement(
                'UPDATE ' . $this->table . ' SET expires_at = ?'
                . ' WHERE lock_name = ? AND owner = ? AND (expires_at = 0 OR expires_at >= ?)',
                [$ttlMs > 0 ? $now + $ttlMs : 0, $key, $owner, $now]
            )) === 1;
        });
    }

    /** Lock names as stored: names over the key size are hashed. */
    public static function key(string $name): string {
        return strlen($name) <= self::MAX_NAME_BYTES ? $name : 'sha256:' . hash('sha256', $name);
    }

    /** The table's DDL for this connection's platform, for creating it by hand. */
    public function getCreateTableSql(): string {
        $p = $this->connection->getDatabasePlatform();
        return 'CREATE TABLE ' . $this->table . ' ('
            . 'lock_name ' . $p->getStringTypeDeclarationSQL(['length' => self::MAX_NAME_BYTES]) . ' NOT NULL, '
            . 'owner ' . $p->getStringTypeDeclarationSQL(['length' => 64]) . ' NOT NULL, '
            . 'expires_at ' . $p->getBigIntTypeDeclarationSQL([]) . ' NOT NULL, '
            . 'PRIMARY KEY (lock_name))';
    }

    private function run(callable $fn): mixed {
        $c = $this->connection;
        $isolated = $c instanceof IsolationCapable && $c->supportsIsolation();
        if ($isolated) {
            $c->beginIsolation();
        } elseif ($c->isTransactionActive()) {
            // The lock row would join that transaction and stay invisible to
            // other pods until it commits: not a lock at all.
            throw new WinterException('DbalLockStore: the connection is inside a transaction; use a'
                . ' winter-doctrine connection bean (isolation) or a dedicated connection');
        }
        try {
            $this->ensureTable($c);
            return $fn($c);
        } finally {
            if ($isolated) {
                $c->endIsolation();
            }
        }
    }

    private function ensureTable(Connection $c): void {
        if ($this->tableReady) {
            return;
        }
        try {
            if (!$c->createSchemaManager()->tableExists($this->table)) {
                $c->executeStatement($this->getCreateTableSql());
            }
        } catch (Throwable $e) {
            // Another pod may have created it at the same moment; a real
            // problem shows up on the first lock statement.
            self::logWarning('Could not create lock table ' . $this->table . ': ' . $e->getMessage());
        }
        $this->tableReady = true;
    }
}
