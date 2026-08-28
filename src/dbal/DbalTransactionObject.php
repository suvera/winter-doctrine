<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\dbal;

use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\TransactionObject;
use Doctrine\DBAL\Connection;
use Override;

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

    #[Override]
    public function begin(): void {
        $this->commitCounter++;

        if ($this->commitCounter == 1) {
            $this->connection->beginTransaction();
        }
    }

    #[Override]
    public function commit(): void {
        $this->commitCounter--;

        if ($this->commitCounter == 0) {
            $this->connection->commit();
            $this->committed = true;
        }
    }

    #[Override]
    public function rollback(): void {
        $this->commitCounter = 0;

        /**
         * Whole Transaction will be rolled back, even if a child method's rollback called
         */
        $this->connection->rollback();
    }

    #[Override]
    public function flush(): void {
    }

    #[Override]
    public function isRollbackOnly(): bool {
        return $this->isReadOnly();
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
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DbalDoctrineTransaction Manager');
    }

    #[Override]
    public function releaseSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DbalDoctrineTransaction Manager');
    }

    #[Override]
    public function createSavepoint(): Savepoint {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DbalDoctrineTransaction Manager');
    }
}
