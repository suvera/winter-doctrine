<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\txn\TransactionStatus;
use Throwable;
use WeakMap;

/**
 * Behaviour shared by EmTransactionManager and DbalTransactionManager on
 * top of winter-boot's AbstractPlatformTransactionManager.
 *
 * Expects the using class to extend AbstractPlatformTransactionManager and
 * to use the Wlf4p logging trait.
 */
trait DoctrineTransactionManagerSupport {

    /**
     * Statuses whose commit failed. The failed commit already rolled back
     * (DoctrineTransactionObject::commit), so the rollback() that callers
     * and TransactionalAspect::commitFailed() issue next is a no-op instead
     * of an exception that would hide the original commit failure.
     */
    private ?WeakMap $failedCommits = null;

    private bool $sharedIsolationWarned = false;

    /**
     * The resource REQUIRES_NEW / NOT_SUPPORTED must isolate (the injected
     * EntityManager or Connection).
     */
    abstract protected function isolationResource(): object;

    public function rollback(TransactionStatus $status): void {
        if ($status->isCompleted() && $this->failedCommits !== null && isset($this->failedCommits[$status])) {
            unset($this->failedCommits[$status]);
            return;
        }
        parent::rollback($status);
    }

    protected function recordFailedCommit(TransactionStatus $status): void {
        $this->failedCommits ??= new WeakMap();
        $this->failedCommits[$status] = true;
    }

    /**
     * A participant (joined PROPAGATION_REQUIRED call) that fails must make
     * the owning transaction roll back. winter-boot only propagates that
     * for its own PDBC transaction objects, so mark ours here.
     */
    protected function processRollback(TransactionStatus $status): void {
        if (!$status->isNewTransaction() && !$status->hasSavepoint() && $status->hasTransaction()) {
            $txn = $status->getTransaction();
            if ($txn instanceof DoctrineTransactionObject) {
                $txn->setRollbackOnly(true);
            }
        }
        parent::processRollback($status);
    }

    /**
     * REQUIRES_NEW / NOT_SUPPORTED: move the current scope onto a dedicated
     * delegate with its own connection, so the inner work neither commits
     * nor sees the suspended transaction.
     */
    protected function beginIsolation(): void {
        $resource = $this->isolationResource();
        if ($resource instanceof IsolationCapable && $resource->supportsIsolation()) {
            $resource->beginIsolation();
            return;
        }
        if (!$this->sharedIsolationWarned) {
            $this->sharedIsolationWarned = true;
            self::logWarning('REQUIRES_NEW/NOT_SUPPORTED on ' . static::class
                . ' run on the shared connection (coroutine-scoped Doctrine is disabled),'
                . ' so the inner work is not isolated from the suspended transaction');
        }
    }

    protected function endIsolation(): void {
        $resource = $this->isolationResource();
        if ($resource instanceof IsolationCapable && $resource->supportsIsolation()) {
            try {
                $resource->endIsolation();
            } catch (Throwable $e) {
                self::logException($e, 'Ending isolated Doctrine transaction scope failed');
            }
        }
    }
}
