<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\txn\support\AbstractTransactionStatus;
use Override;

/**
 * Extends winter-boot's status so completion tracking, rollback-only
 * propagation and savepoint handling follow the framework semantics.
 */
class EmTransactionStatus extends AbstractTransactionStatus {

    public function __construct(
        ?EmTransactionObject $transaction = null,
        bool $newTransaction = true,
        bool $readOnly = false,
        bool $debug = false
    ) {
        parent::__construct($transaction, $newTransaction, $readOnly, $debug);
    }

    #[Override]
    public function getTransaction(): ?EmTransactionObject {
        /** @var EmTransactionObject|null $txn */
        $txn = $this->transaction;
        return $txn;
    }

    #[Override]
    public function setRollbackOnly(bool $rollbackOnly = true): void {
        if ($rollbackOnly) {
            $this->getTransaction()?->setRollbackOnly(true);
        }
    }
}
