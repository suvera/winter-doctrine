<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\dbal;

use dev\winterframework\doctrine\common\DoctrineTransactionObject;
use Doctrine\DBAL\Connection;
use Override;

class DbalTransactionObject extends DoctrineTransactionObject {

    public function __construct(
        private Connection $connection
    ) {
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    #[Override]
    protected function connection(): Connection {
        return $this->connection;
    }
}
