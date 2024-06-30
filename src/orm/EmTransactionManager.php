<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\txn\support\AbstractPlatformTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use Doctrine\ORM\EntityManager;

class EmTransactionManager extends AbstractPlatformTransactionManager {

    public function __construct(
        protected EntityManager $entityManager
    ) {
        parent::__construct();
    }

    public function getEntityManager(): EntityManager {
        return $this->entityManager;
    }

    protected function doCommit(TransactionStatus $status): void {
        /** @var EmTransactionStatus $status */
        TypeAssert::typeOf($status, EmTransactionStatus::class);
        $status->getTransaction()->commit();
    }

    protected function doGetTransaction(TransactionDefinition $definition): EmTransactionStatus {
        $txn = new EmTransactionObject($this->getEntityManager());
        $txn->setReadOnly($definition->isReadOnly());

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $status = new EmTransactionStatus(
            $txn,
            true,
            $definition->isReadOnly()
        );

        return $status;
    }

    protected function doRollback(TransactionStatus $status): void {
        /** @var EmTransactionStatus $status */
        TypeAssert::typeOf($status, EmTransactionStatus::class);
        $status->getTransaction()->rollback();
    }
}
