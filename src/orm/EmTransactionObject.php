<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\doctrine\common\DoctrineTransactionObject;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Override;

class EmTransactionObject extends DoctrineTransactionObject {

    public function __construct(
        private EntityManager $entityManager
    ) {
    }

    public function getEntityManager(): EntityManager {
        return $this->entityManager;
    }

    #[Override]
    protected function connection(): Connection {
        return $this->entityManager->getConnection();
    }

    #[Override]
    protected function beforeCommit(): void {
        $this->entityManager->flush();
    }
}
