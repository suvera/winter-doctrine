<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\dbal;

use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\TransactionObject;
use Doctrine\DBAL\Connection;

class DbalTransactionObject implements TransactionObject {

    protected bool $committed = false;
    private ?int $previousIsolationLevel = null;
    private bool $readOnly = false;
    private bool $suspended = false;
    protected int $commitCounter = 0;

    public function __construct(
        private Connection $connection
    ) {
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    public function getPreviousIsolationLevel(): ?int {
        return $this->previousIsolationLevel;
    }

    public function begin(): void {
        $this->commitCounter++;

        if ($this->commitCounter == 1) {
            $this->connection->beginTransaction();
        }
    }

    public function commit(): void {
        $this->commitCounter--;

        if ($this->commitCounter == 0) {
            $this->connection->commit();
            $this->committed = true;
        }
    }

    public function rollback(): void {
        $this->commitCounter = 0;

        /**
         * Whole Transaction will be rolled back, even if a child method's rollback called
         */
        $this->connection->rollback();
    }

    public function flush(): void {
    }

    public function isRollbackOnly(): bool {
        return $this->isReadOnly();
    }

    public function setPreviousIsolationLevel(?int $previousIsolationLevel): void {
        $this->previousIsolationLevel = $previousIsolationLevel;
    }

    public function isCommitted(): bool {
        return $this->committed;
    }

    public function setCommitted(bool $committed): void {
        $this->committed = $committed;
    }

    public function isSuspended(): bool {
        return $this->suspended;
    }

    public function suspend(): void {
        $this->suspended = true;
    }

    public function resume(): void {
        $this->suspended = false;
    }

    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }

    public function isSavepointAllowed(): bool {
        return false;
    }

    public function rollbackToSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DbalDoctrineTransaction Manager');
    }

    public function releaseSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DbalDoctrineTransaction Manager');
    }

    public function createSavepoint(): Savepoint {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DbalDoctrineTransaction Manager');
    }
}
