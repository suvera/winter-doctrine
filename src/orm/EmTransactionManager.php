<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\doctrine\common\DoctrineTransactionManagerSupport;
use dev\winterframework\txn\support\AbstractPlatformTransactionManager;
use dev\winterframework\txn\TransactionDefinition;
use dev\winterframework\txn\TransactionStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\ORM\EntityManager;
use Override;
use Throwable;

class EmTransactionManager extends AbstractPlatformTransactionManager {
    use Wlf4p;
    use DoctrineTransactionManagerSupport;

    public function __construct(
        protected EntityManager $entityManager
    ) {
        parent::__construct();
    }

    public function getEntityManager(): EntityManager {
        return $this->entityManager;
    }

    #[Override]
    protected function isolationResource(): object {
        return $this->entityManager;
    }

    #[Override]
    protected function doCommit(TransactionStatus $status): void {
        /** @var EmTransactionStatus $status */
        TypeAssert::typeOf($status, EmTransactionStatus::class);
        try {
            $status->getTransaction()->commit();
        } catch (Throwable $e) {
            $this->recordFailedCommit($status);
            throw $e;
        }
    }

    #[Override]
    protected function doGetTransaction(TransactionDefinition $definition): EmTransactionStatus {
        $txn = new EmTransactionObject($this->getEntityManager());
        $txn->setReadOnly($definition->isReadOnly());

        return new EmTransactionStatus(
            $txn,
            true,
            $definition->isReadOnly()
        );
    }

    #[Override]
    protected function doRollback(TransactionStatus $status): void {
        /** @var EmTransactionStatus $status */
        TypeAssert::typeOf($status, EmTransactionStatus::class);
        $status->getTransaction()->rollback();
    }
}
