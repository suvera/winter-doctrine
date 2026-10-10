<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\lock;

use dev\winterframework\util\concurrent\StoreLockManager;
use Doctrine\DBAL\Connection;

/**
 * Distributed #[Lockable] locking in a database table, through Doctrine
 * DBAL (see DbalLockStore). Needs winter-boot 2.1.6 or later.
 *
 *   #[Autowired('admindb-doctrine-dbal')]
 *   private Connection $adminDbal;
 *
 *   #[Bean('dbLockManager')]
 *   public function dbLockManager(): LockManager {
 *       return new DbalLockManager($this->adminDbal);
 *   }
 *
 *   #[Lockable(name: 'order-#{id}', ttlSeconds: 30, lockManager: 'dbLockManager')]
 */
class DbalLockManager extends StoreLockManager {

    /**
     * @param int $pollMs pause between attempts while waiting (waitMilliSecs)
     */
    public function __construct(
        Connection $connection,
        string $table = DbalLockStore::DEFAULT_TABLE,
        bool $createTable = true,
        int $pollMs = 50,
    ) {
        parent::__construct(new DbalLockStore($connection, $table, $createTable), $pollMs);
    }
}
