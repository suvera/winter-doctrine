<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\pdbc\ex\SQLFeatureNotSupportedException;
use dev\winterframework\txn\Savepoint;
use dev\winterframework\txn\TransactionObject;
use Doctrine\ORM\EntityManager;
use Override;

class EmTransactionObject implements TransactionObject {

    protected bool $committed = false;
    private ?int $previousIsolationLevel = null;
    private bool $readOnly = false;
    private bool $suspended = false;
    protected int $commitCounter = 0;

    public function __construct(
        private EntityManager $entityManager
    ) {
    }

    public function getEntityManager(): EntityManager {
        return $this->entityManager;
    }

    public function getPreviousIsolationLevel(): ?int {
        return $this->previousIsolationLevel;
    }

    #[Override]
    public function begin(): void {
        $this->commitCounter++;

        if ($this->commitCounter == 1) {
            $this->entityManager->beginTransaction();
        }
    }

    #[Override]
    public function commit(): void {
        $this->commitCounter--;

        if ($this->commitCounter == 0) {
            $this->entityManager->flush();
            $this->entityManager->commit();
            $this->committed = true;
        }
    }

    #[Override]
    public function rollback(): void {
        $this->commitCounter = 0;

        /**
         * Whole Transaction will be rolled back, even if a child method's rollback called
         */
        $this->entityManager->rollback();
    }

    #[Override]
    public function flush(): void {
        // flush() will be done before commit
        // $this->entityManager->flush();
    }

    #[Override]
    public function isRollbackOnly(): bool {
        return $this->isReadOnly();
    }

    #[Override]
    public function setPreviousIsolationLevel(?int $previousIsolationLevel): void {
        $this->previousIsolationLevel = $previousIsolationLevel;
    }

    #[Override]
    public function isCommitted(): bool {
        return $this->committed;
    }

    #[Override]
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

    #[Override]
    public function setReadOnly(bool $readOnly): void {
        $this->readOnly = $readOnly;
    }

    #[Override]
    public function isSavepointAllowed(): bool {
        return false;
    }

    #[Override]
    public function rollbackToSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DoctrineTransaction Manager');
    }

    #[Override]
    public function releaseSavepoint(Savepoint $point): void {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DoctrineTransaction Manager');
    }

    #[Override]
    public function createSavepoint(): Savepoint {
        throw new SQLFeatureNotSupportedException('Savepoint is not supported by DoctrineTransaction Manager');
    }
}
