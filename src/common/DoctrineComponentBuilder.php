<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\timer\IdleCheckRegistry;
use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\doctrine\orm\EmTransactionManager;
use Doctrine\DBAL\Connection;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Throwable;
use WeakMap;

class DoctrineComponentBuilder {
    use Wlf4p;

    const DOCTRINE_SUFFIX = '-doctrine';
    const DOCTRINE_CONN_SUFFIX = '-dbal';
    const DOCTRINE_EM_SUFFIX = '-em';
    const DOCTRINE_TXN_SUFFIX = '-emtxn';
    const DOCTRINE_DBAL_TXN_SUFFIX = '-dbaltxn';

    /**
     * @var EntityManager[]
     */
    private array $entityManagers = [];
    private EntityManager $primaryEntityManager;

    /**
     * @var PlatformTransactionManager[]
     */
    private array $transactionManagers = [];
    private EmTransactionManager $primaryTransactionManager;

    /**
     * @var Connection[]
     */
    private array $connections = [];
    private Connection $primaryConnection;

    /**
     * @var DbalTransactionManager[]
     */
    private array $dbalTransactionManagers = [];
    private DbalTransactionManager $primaryDbalTransactionManager;

    /**
     * @var DoctrineDbConfig[]
     */
    private array $dsConfig = [];
    private array $dsParams = [];

    private WeakMap $dsObjectMap;
    private WeakMap $dsConnectMap;

    public function __construct(
        private ApplicationContext $ctx,
        private ApplicationContextData $ctxData,
        array $dataSources
    ) {
        $this->dsObjectMap = new WeakMap();
        $this->dsConnectMap = new WeakMap();
        $this->init($dataSources);
    }

    private function init(array $dataSources): void {
        $primary = false;
        $first = false;

        $ref = new ReflectionClass(DoctrineDbConfig::class);

        foreach ($dataSources as $dataSource) {

            TypeAssert::notEmpty(
                'name',
                $dataSource['name'],
                'EntityManager configured without "name" parameter'
            );

            TypeAssert::notEmpty(
                'url',
                $dataSource['url'],
                'EntityManager configured without "url" parameter'
            );

            $ds = new DoctrineDbConfig();
            try {
                ObjectCreator::mapObject($ds, $dataSource, $ref);
            } catch (Throwable $e) {
                self::logException($e);
                throw new WinterException('Invalid Syntax in EntityManager configuration ', 0, $e);
            }
            $ds->setName($ds->getName() . self::DOCTRINE_SUFFIX);

            $parsedParams = $this->parseDsn($ds->getUrl());
            $ds->parseDoctrineParams($dataSource);
            foreach ($ds->getDoctrineOptions() as $key => $value) {
                if (!is_null($value) && $value !== '' && (is_array($value) && count($value) > 0)) {
                    $parsedParams[$key] = $value;
                }
            }

            if (!isset($parsedParams['driver']) || !$parsedParams['driver']) {
                throw new WinterException('Malformed parameter "url". No driver found');
            }
            $ds->setDriverClass($parsedParams['driver']);

            if (isset($parsedParams['user']) && $parsedParams['user']) {
                $ds->setUsername($parsedParams['user']);
            } else {
                $parsedParams['user'] = $ds->getUsername();
            }
            if (isset($parsedParams['password']) && $parsedParams['password']) {
                $ds->setPassword($parsedParams['password']);
            } else {
                $parsedParams['password'] = $ds->getPassword();
            }

            if ($primary && $ds->isPrimary()) {
                throw new WinterException('Two EntityManagers cannot have "isPrimary" set to "true"');
            }

            if ($ds->isPrimary()) {
                $primary = $ds;
            }
            if (!$first) {
                $first = $ds;
            }

            if (isset($this->dsConfig[$ds->getName()])) {
                throw new WinterException('Two EntityManagers cannot have same "name" "' . $ds->getName() . '"');
            }

            $this->dsConfig[$ds->getName()] = $ds;
            $this->dsParams[$ds->getName()] = $parsedParams;
        }

        if (!$primary && $first) {
            $first->setPrimary(true);
        }
    }

    /**
     * Parse the DSN string and return the array of key-value pairs
     */
    protected function parseDsn(string $url): array {
        $config = [];
        $parts = explode(":", $url, 2);
        $config['driver'] = $parts[0];

        if ($config['driver'] === 'sqlite') {
            $config['driver'] = 'sqlite3';
            $config['path'] = $parts[1];
            return $config;
        }

        if (!isset($parts[1])) {
            return $config;
        }
        $keyValues = explode(";", $parts[1]);
        foreach ($keyValues as $keyValue) {
            $kv = explode("=", $keyValue, 2);
            if (isset($kv[1])) {
                $config[$kv[0]] = $kv[1];
            }
        }

        return $config;
    }

    /**
     * @return EntityManager[]
     */
    public function getEntityManagers(): array {
        return $this->entityManagers;
    }

    public function getPrimaryTransactionManager(): EmTransactionManager {
        if (!isset($this->primaryTransactionManager)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    return $this->primaryTransactionManager = $this->getTransactionManager($dsConfig->getName());
                }
            }
        }
        throw new WinterException('Could not find Primary EmTransactionManager');
    }

    public function getTransactionManager(string $name): EmTransactionManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_TXN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }

        if (isset($this->transactionManagers[$name])) {
            return $this->transactionManagers[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->transactionManagers[$name] = new EmTransactionManager(
                $this->getEntityManager($name)
            );
        }
        throw new WinterException('Could not find EmTransactionManager with name "' . $name . '"');
    }

    public function getPrimaryDbalTransactionManager(): DbalTransactionManager {
        if (!isset($this->primaryDbalTransactionManager)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    return $this->primaryDbalTransactionManager = $this->getDbalTransactionManager($dsConfig->getName());
                }
            }
        }
        throw new WinterException('Could not find Primary DbalTransactionManager');
    }

    public function getDbalTransactionManager(string $name): DbalTransactionManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_DBAL_TXN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }

        if (isset($this->dbalTransactionManagers[$name])) {
            return $this->dbalTransactionManagers[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->dbalTransactionManagers[$name] = new DbalTransactionManager(
                $this->getConnection($name)
            );
        }
        throw new WinterException('Could not find DbalTransactionManager with name "' . $name . '"');
    }

    public function getPrimaryConnection(): Connection {
        if (!isset($this->primaryConnection)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    return $this->primaryConnection = $this->getConnection($dsConfig->getName());
                }
            }
        }
        throw new WinterException('Could not find Primary EmTransactionManager');
    }

    public function getConnection(string $name): Connection {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_CONN_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->connections[$name] = $this->buildConnection($this->dsConfig[$name]);
        }
        throw new WinterException('Could not find Database Connection with name "' . $name . '"');
    }

    public function getPrimaryEntityManager(): EntityManager {
        if (!isset($this->primaryEntityManager)) {
            foreach ($this->dsConfig as $dsConfig) {
                if ($dsConfig->isPrimary()) {
                    $this->primaryEntityManager = $this->buildEntityManager($dsConfig);
                    return $this->primaryEntityManager;
                }
            }
            throw new WinterException('Could not find Primary EntityManager');
        }
        return $this->primaryEntityManager;
    }

    public function getEntityManager(string $name): EntityManager {
        $parts = explode('-', $name);
        if ('-' . $parts[count($parts) - 1] == self::DOCTRINE_EM_SUFFIX) {
            $name = implode('-', explode('-', $name, -1));
        }
        if (isset($this->entityManagers[$name])) {
            return $this->entityManagers[$name];
        } else if (isset($this->dsConfig[$name])) {
            return $this->entityManagers[$name] = $this->buildEntityManager($this->dsConfig[$name]);
        }
        throw new WinterException('Could not find EntityManager with name "' . $name . '"');
    }

    /**
     * @return DoctrineDbConfig[]
     */
    public function getDoctrineDbConfig(): array {
        return $this->dsConfig;
    }

    private function buildEntityManager(DoctrineDbConfig $ds): EntityManager {
        if (isset($this->dsObjectMap[$ds])) {
            return $this->dsObjectMap[$ds];
        }

        $config = ORMSetup::createAttributeMetadataConfiguration($ds->getEntityPaths(), $ds->isDevMode(), null, new ArrayAdapter(), false);
        $obj = new EntityManager($this->buildConnection($ds), $config);

        $this->dsObjectMap[$ds] = $obj;

        return $obj;
    }

    private function buildConnection(DoctrineDbConfig $ds): Connection {
        if (isset($this->dsConnectMap[$ds])) {
            return $this->dsConnectMap[$ds];
        }

        $dbParams = $this->dsParams[$ds->getName()];
        $config = ORMSetup::createAttributeMetadataConfiguration($ds->getEntityPaths(), $ds->isDevMode(), null, new ArrayAdapter(), false);

        $connection = DriverManager::getConnection($dbParams, $config);
        $this->dsConnectMap[$ds] = $connection;

        /** @var IdleCheckRegistry $idleCheck */
        $idleCheck = $this->ctx->beanByClass(IdleCheckRegistry::class);
        $idleCheck->register(function () use ($connection) {
            // TODO: Implement idle check
        });

        return $connection;
    }
}
