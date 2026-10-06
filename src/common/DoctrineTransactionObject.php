<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\TransactionObject;
use Doctrine\DBAL\Connection;
use Override;
use PDO;
use Throwable;

/**
 * Shared state and connection handling for the ORM and DBAL transaction
 * objects.
 *
 * - Rollback-only is tracked separately from read-only, so a failed
 *   participant (PROPAGATION_REQUIRED join) can force the owning
 *   transaction to roll back instead of committing partial work.
 * - The DBAL nesting level opened by begin() is remembered, so commit
 *   failures and rollbacks undo exactly this transaction's level and never
 *   leave the connection inside a dangling transaction.
 */
abstract class DoctrineTransactionObject implements TransactionObject {

    protected bool $committed = false;
    private ?int $previousIsolationLevel = null;
    private bool $readOnly = false;
    private bool $rollbackOnly = false;
    private bool $suspended = false;
    protected int $commitCounter = 0;

    /**
     * DBAL nesting level right after this transaction began (0: not begun).
     */
    private int $level = 0;

    abstract protected function connection(): Connection;

    /**
     * Work to run inside the transaction right before it commits
     * (the ORM flushes its unit of work here).
     */
    protected function beforeCommit(): void {
    }

    #[Override]
    public function begin(): void {
        $this->commitCounter++;

        if ($this->commitCounter == 1) {
            $conn = $this->connection();
            $conn->beginTransaction();
            $this->level = $conn->getTransactionNestingLevel();
        }
    }

    #[Override]
    public function commit(): void {
        $this->commitCounter--;

        if ($this->commitCounter == 0) {
            try {
                $this->beforeCommit();
                $this->connection()->commit();
            } catch (Throwable $e) {
                $this->rollbackOwnLevel();
                throw $e;
            }
            $this->level = 0;
            $this->committed = true;
        }
    }

    #[Override]
    public function rollback(): void {
        $this->commitCounter = 0;

        /**
         * Whole Transaction will be rolled back, even if a child method's rollback called
         */
        $this->rollbackOwnLevel();
    }

    /**
     * Roll back this transaction's DBAL level if it is still open. A level
     * that is already gone (failed commit, delegate rebuilt after its
     * EntityManager closed) is not an error.
     */
    private function rollbackOwnLevel(): void {
        if ($this->level === 0) {
            return;
        }
        $level = $this->level;
        $this->level = 0;
        $conn = $this->connection();
        if ($conn->isTransactionActive() && $conn->getTransactionNestingLevel() >= $level) {
            $conn->rollBack();
            return;
        }
        if ($level === 1 && !$conn->isTransactionActive()) {
            $this->rollbackDanglingNativeTransaction($conn);
        }
    }

    /**
     * A failed COMMIT can leave the server transaction open while DBAL has
     * already dropped its nesting level (e.g. SQLite deferred constraints).
     * On a long-lived connection every later BEGIN would then fail, so close
     * the physical transaction this object owned.
     */
    private function rollbackDanglingNativeTransaction(Connection $conn): void {
        try {
            if (!$conn->isConnected()) {
                return;
            }
            $native = $conn->getNativeConnection();
            if ($native instanceof PDO && $native->inTransaction()) {
                $native->rollBack();
            }
        } catch (Throwable) {
            // Best effort: the original failure is what the caller sees.
        }
    }

    #[Override]
    public function flush(): void {
    }

    #[Override]
    public function isRollbackOnly(): bool {
        return $this->rollbackOnly || $this->readOnly;
    }

    public function setRollbackOnly(bool $rollbackOnly = true): void {
        $this->rollbackOnly = $rollbackOnly;
    }

    public function getPreviousIsolationLevel(): ?int {
        return $this->previousIsolationLevel;
    }

    public function setPreviousIsolationLevel(?int $previousIsolationLevel): void {
        $this->previousIsolationLevel = $previousIsolationLevel;
    }

    #[Override]
    public function isCommitted(): bool {
        return $this->committed;
    }

    public function setCommitted(bool $committed): void {
        $this->committed = $committed;
    }

    #[Override]
    public function isSuspended(): bool {
        return $this->suspended;
    }

    #[Override]
    public function suspend(): void {
        $this->suspended = true;
    }

    #[Override]
    public function resume(): void {
        $this->suspended = false;
    }

    #[Override]
    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }

    #[Override]
    public function isSavepointAllowed(): bool {
        return false;
    }

    #[Override]
    public function rollbackToSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by Doctrine transaction managers');
    }

    #[Override]
    public function releaseSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by Doctrine transaction managers');
    }

    #[Override]
    public function createSavepoint(): Savepoint {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by Doctrine transaction managers');
    }
}
