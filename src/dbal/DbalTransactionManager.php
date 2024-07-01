<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\dbal;

use dev\winterframework\txn\support\AbstractPlatformTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\DBAL\Connection;

class DbalTransactionManager extends AbstractPlatformTransactionManager {
    use Wlf4p;

    public function __construct(
        private Connection $connection
    ) {
        //self::logInfo(__METHOD__ . ' called');
        parent::__construct();
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    protected function doCommit(TransactionStatus $status): void {
        //self::logInfo(__METHOD__ . ' called');
        /** @var DbalTransactionStatus $status */
        TypeAssert::typeOf($status, DbalTransactionStatus::class);
        $status->getTransaction()->commit();
    }

    protected function doGetTransaction(TransactionDefinition $definition): DbalTransactionStatus {
        //self::logInfo(__METHOD__ . ' called');
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
        //self::logInfo(__METHOD__ . ' called');
        /** @var DbalTransactionStatus $status */
        TypeAssert::typeOf($status, DbalTransactionStatus::class);
        $status->getTransaction()->rollback();
    }
}
