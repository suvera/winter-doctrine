<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\dbal;

use dev\winterframework\txn\support\AbstractPlatformTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use Doctrine\DBAL\Connection;

class DbalTransactionManager extends AbstractPlatformTransactionManager {

    public function __construct(
        private Connection $connection
    ) {
        parent::__construct();
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    protected function doCommit(TransactionStatus $status): void {
        /** @var DbalTransactionStatus $status */
        TypeAssert::typeOf($status, DbalTransactionStatus::class);
        $status->getTransaction()->commit();
    }

    protected function doGetTransaction(TransactionDefinition $definition): DbalTransactionStatus {
        $txn = new DbalTransactionObject($this->getConnection());
        $txn->setReadOnly($definition->isReadOnly());

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $status = new DbalTransactionStatus(
            $txn,
            true,
            $definition->isReadOnly()
        );

        return $status;
    }

    protected function doRollback(TransactionStatus $status): void {
        /** @var DbalTransactionStatus $status */
        TypeAssert::typeOf($status, DbalTransactionStatus::class);
        $status->getTransaction()->rollback();
    }
}
