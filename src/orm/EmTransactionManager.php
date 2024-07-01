<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\txn\support\AbstractPlatformTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\ORM\EntityManager;

class EmTransactionManager extends AbstractPlatformTransactionManager {
    use Wlf4p;

    public function __construct(
        protected EntityManager $entityManager
    ) {
        //self::logInfo(__METHOD__ . ' called');
        parent::__construct();
    }

    public function getEntityManager(): EntityManager {
        return $this->entityManager;
    }

    protected function doCommit(TransactionStatus $status): void {
        //self::logInfo(__METHOD__ . ' called');
        /** @var EmTransactionStatus $status */
        TypeAssert::typeOf($status, EmTransactionStatus::class);
        $status->getTransaction()->commit();
    }

    protected function doGetTransaction(TransactionDefinition $definition): EmTransactionStatus {
        //self::logInfo(__METHOD__ . ' called');
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
        //self::logInfo(__METHOD__ . ' called');
        /** @var EmTransactionStatus $status */
        TypeAssert::typeOf($status, EmTransactionStatus::class);
        $status->getTransaction()->rollback();
    }
}
