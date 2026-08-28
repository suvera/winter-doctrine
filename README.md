# WinterBoot Module - Doctrine

Winter Doctrine is a module that provides easy configuration and access to Doctrine orm/dbal functionality from [WinterBoot](https://github.com/suvera/winter-boot) applications.

### About Doctrine:

- [https://www.doctrine-project.org/index.html](https://www.doctrine-project.org/index.html)

## Setup


```shell
composer require suvera/winter-doctrine
```

To enable [Doctrine](https://www.doctrine-project.org/index.html) module in applications, append following code to **application.yml**

```yaml

modules:
    - module: dev\winterframework\doctrine\DoctrineModule
      enabled: true

```

## application.yml

in your **application.yml** file you might already have setup datasources like this.

In below example, there are two datasources configured here with names.

1. defaultdb  (**isPrimary: true**)
2. admindb

```yaml

datasource:
    -   name: defaultdb
        isPrimary: true
        url: "sqlite::memory:"
        username: xxxxx
        password: xxzzz
        doctrine:
            entityPaths:
                - /path/to/defaultdb/entities
            isDevMode: false

    -   name: admindb
        url: "mysql:host=localhost;port=3307;dbname=testdb"
        username: xxxxx
        password: xxzzz
        doctrine:
            entityPaths:
                - /path/to/admindb/entities
                - /path/other/admindb/entities2
            isDevMode: false
            driver:
            driverOptions:
            wrapperClass:
            driverClass: 
        connection:
            persistent: true
            errorMode: ERRMODE_EXCEPTION
            columnsCase: CASE_NATURAL
            idleTimeout: 300
            autoCommit: true
            defaultrowprefetch: 100

```


ORM/DBAL beans can be Autowired. No need to created them manually.

Bean names are suffixed as following way. Autowired code should input bean name.


| Bean Type     | Bean Name |
| ------------- | ------------- |
| ORM EntityManager | {name}-doctrine-em  |
| ORM Tranaction Manager | {name}-doctrine-emtxn  |
| DBAL Connection | {name}-doctrine-dbal  |
| DBAL Tranaction Manager | {name}-doctrine-dbaltxn  |

Examples below

### ORM EntityManager
```phpt

// ORM - Primary (defaultdb)
#[Autowired]
private EntityManager $defaultEm;
// Alternatively coded as: #[Autowired("defaultdb-doctrine-em")]


// ORM 
#[Autowired("admindb-doctrine-em")]
private EntityManager $adminEm;

```

### ORM Transaction Managers
```phpt

// ORM - Primary Tranaction Manager (defaultdb)
#[Autowired]
private EmTransactionManager $defaultTxnManager;
// Alternatively coded as: #[Autowired("defaultdb-doctrine-emtxn")]


// ORM Tranaction Manager
#[Autowired("admindb-doctrine-emtxn")]
private EmTransactionManager $adminTxnManager;

```

### DBAL Connection
```phpt

// DBAL Connection - Primary (defaultdb)
#[Autowired]
private Connection $defaultConn;
// Alternatively coded as: #[Autowired("defaultdb-doctrine-dbal")]


// DBAL Connection
#[Autowired("admindb-doctrine-dbal")]
private Connection $adminConn;

```

### DBAL Transaction Managers
```phpt

// DBAL - Primary Tranaction Manager (defaultdb)
#[Autowired]
private DbalTransactionManager $defaultTxnManager;
// Alternatively coded as: #[Autowired("defaultdb-doctrine-dbaltxn")]


// DBAL Tranaction Manager
#[Autowired("admindb-doctrine-dbaltxn")]
private DbalTransactionManager $adminTxnManager;

```


## How to use Transactions

### Declarative Transactions (AOP)

Executing something under ORM/DBAL transaction is easy by just using **#[Transactional]** annotation:

```phpt
#[Autowired("admindb-doctrine-em")]
private EntityManager $adminEm;

#[Transactional(transactionManager: "admindb-doctrine-emtxn")]
public function executeInTransaction(): void {
    // do something here
    foreach ($objects as $obj) {
        $this->adminEm->persist($obj);
    }
    // do more things here
}
```

### Programmatic Transactions

For fine-grained control, use `EmTransactionManager` (ORM) or `DbalTransactionManager` (DBAL) with the `getTransaction()`/`commit()`/`rollback()` pattern.

#### ORM Example — UserService with EmTransactionManager

```phpt
use dev\winterframework\doctrine\orm\EmTransactionManager;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\ORM\EntityManager;

#[Service]
class UserService {
    use Wlf4p;

    #[Autowired]
    private EmTransactionManager $txnManager;

    private function getEm(): EntityManager {
        return $this->txnManager->getEntityManager();
    }

    public function createUser(User $user): User
    {
        $status = $this->txnManager->getTransaction(new DefaultTransactionDefinition());
        try {
            $this->getEm()->persist($user);
            $this->getEm()->flush();
            $this->txnManager->commit($status);
            return $user;
        } catch (\Throwable $e) {
            $this->txnManager->rollback($status);
            throw $e;
        }
    }

    public function updateUser(User $user): User
    {
        $status = $this->txnManager->getTransaction(new DefaultTransactionDefinition());
        try {
            $existing = $this->getEm()->find(User::class, $user->getId());
            if ($existing) {
                $existing->setName($user->getName());
                $existing->setEmail($user->getEmail());
                $existing->setAge($user->getAge());
                $this->getEm()->flush();
            }
            $this->txnManager->commit($status);
            return $user;
        } catch (\Throwable $e) {
            $this->txnManager->rollback($status);
            throw $e;
        }
    }

    public function deleteUser(int $id): bool
    {
        $status = $this->txnManager->getTransaction(new DefaultTransactionDefinition());
        try {
            $user = $this->getEm()->find(User::class, $id);
            if ($user) {
                $this->getEm()->remove($user);
                $this->getEm()->flush();
                $this->txnManager->commit($status);
                return true;
            }
            $this->txnManager->commit($status);
            return false;
        } catch (\Throwable $e) {
            $this->txnManager->rollback($status);
            throw $e;
        }
    }

    public function findById(int $id): ?User
    {
        return $this->getEm()->find(User::class, $id);
    }

    public function findAll(): array
    {
        return $this->getEm()->getRepository(User::class)->findAll();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->getEm()->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
```

#### DBAL Example — UserDbalService with DbalTransactionManager

```phpt
use dev\winterframework\doctrine\dbal\DbalTransactionManager;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\log\Wlf4p;
use Doctrine\DBAL\Connection;

#[Service]
class UserDbalService {
    use Wlf4p;

    #[Autowired("defaultdb-doctrine-dbal")]
    private Connection $conn;

    #[Autowired("defaultdb-doctrine-dbaltxn")]
    private DbalTransactionManager $txnManager;

    public function createUser(User $user): User
    {
        $status = $this->txnManager->getTransaction(new DefaultTransactionDefinition());
        try {
            $this->conn->executeStatement(
                "INSERT INTO doctrine_users (name, email, age) VALUES (:name, :email, :age)",
                ['name' => $user->getName(), 'email' => $user->getEmail(), 'age' => $user->getAge()]
            );
            $user->setId((int) $this->conn->lastInsertId());
            $this->txnManager->commit($status);
            return $user;
        } catch (\Throwable $e) {
            $this->txnManager->rollback($status);
            throw $e;
        }
    }

    public function updateUser(User $user): User
    {
        $status = $this->txnManager->getTransaction(new DefaultTransactionDefinition());
        try {
            $this->conn->executeStatement(
                "UPDATE doctrine_users SET name = :name, email = :email, age = :age WHERE id = :id",
                ['name' => $user->getName(), 'email' => $user->getEmail(), 'age' => $user->getAge(), 'id' => $user->getId()]
            );
            $this->txnManager->commit($status);
            return $user;
        } catch (\Throwable $e) {
            $this->txnManager->rollback($status);
            throw $e;
        }
    }

    public function deleteUser(int $id): bool
    {
        $status = $this->txnManager->getTransaction(new DefaultTransactionDefinition());
        try {
            $affected = $this->conn->executeStatement(
                "DELETE FROM doctrine_users WHERE id = :id",
                ['id' => $id]
            );
            $this->txnManager->commit($status);
            return $affected > 0;
        } catch (\Throwable $e) {
            $this->txnManager->rollback($status);
            throw $e;
        }
    }

    public function findById(int $id): ?User
    {
        $row = $this->conn->fetchAssociative(
            "SELECT * FROM doctrine_users WHERE id = :id",
            ['id' => $id]
        );
        if (!$row) return null;
        return new User((int) $row['id'], $row['name'], $row['email'], isset($row['age']) ? (int) $row['age'] : null);
    }

    public function findAll(): array
    {
        $rows = $this->conn->fetchAllAssociative("SELECT * FROM doctrine_users ORDER BY id");
        $users = [];
        foreach ($rows as $row) {
            $users[] = new User((int) $row['id'], $row['name'], $row['email'], isset($row['age']) ? (int) $row['age'] : null);
        }
        return $users;
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->conn->fetchAssociative(
            "SELECT * FROM doctrine_users WHERE email = :email",
            ['email' => $email]
        );
        if (!$row) return null;
        return new User((int) $row['id'], $row['name'], $row['email'], isset($row['age']) ? (int) $row['age'] : null);
    }
}
```

---

## Multi-Tenancy Support

When you need **separate database per tenant**, configure a datasource as a tenant template. At runtime, you pass the tenant identifier explicitly to a single autowired `TenantDoctrineProvider`, which resolves and caches per-tenant connections.

### Architecture

```
Your code knows which tenant it's operating on (from request/auth/etc.)
              ↓
$this->doctrine->getEntityManager('tenant-123')
              ↓
TenantDoctrineProvider checks its pool: has 'tenant-123'?
   ├─ Yes → returns cached EntityManager
   └─ No  → calls TenantConnectionProvider::resolveParams('tenant-123', $baseParams)
              ↓
Provider queries admin DB → returns tenant-specific dbname/host/credentials
              ↓
Creates Connection + EntityManager for that tenant, caches them
              ↓
Returns the tenant-specific EntityManager
```

### Configuration

Add `doctrine.tenantTemplate: true` and a `doctrine.tenantConnectionProvider` class to your datasource:

```yaml
datasource:
    -   name: app
        isPrimary: true
        url: "pdo_mysql:host=localhost;port=3306;user=root;password=secret"
        doctrine:
            entityPaths:
                - /path/to/entities
            isDevMode: false
            tenantTemplate: true
            tenantConnectionProvider: "App\\MultiTenancy\\AdminDbTenantProvider"
```

### TenantConnectionProvider Interface

Implement this interface to resolve tenant-specific database parameters at runtime:

```php
use dev\winterframework\doctrine\multitenancy\TenantConnectionProvider;

class AdminDbTenantProvider implements TenantConnectionProvider {

    public function resolveParams(string $tenantId, array $baseParams): array {
        // Query your admin database (or any config store) for this tenant
        // $tenantInfo = $this->adminRepo->findTenant($tenantId);

        return array_merge($baseParams, [
            'dbname'   => 'tenant_' . $tenantId . '_db',
            // 'host'     => $tenantInfo->host,
            // 'user'     => $tenantInfo->username,
            // 'password' => $tenantInfo->password,
        ]);
    }
}
```

### Autowiring — Single TenantDoctrineProvider Bean

A multi-tenant datasource exposes **one** autowirable bean:

| Bean Type | Bean Name |
|-----------|-----------|
| TenantDoctrineProvider | `{name}-doctrine-tenant` |

```php
use dev\winterframework\doctrine\multitenancy\TenantDoctrineProvider;

#[Autowired("app-doctrine-tenant")]
private TenantDoctrineProvider $doctrine;
```

If the tenant datasource is `isPrimary: true`, you can omit the bean name:

```php
#[Autowired]
private TenantDoctrineProvider $doctrine;
```

### Using the TenantDoctrineProvider

All methods take an explicit `$tenantId` argument — you are responsible for knowing which tenant you're operating on:

```php
public function doWork(string $tenantId): void {
    // EntityManager for this tenant
    $em = $this->doctrine->getEntityManager($tenantId);

    // DBAL Connection for this tenant
    $conn = $this->doctrine->getConnection($tenantId);

    // ORM Transaction Manager for this tenant
    $emTxn = $this->doctrine->getEmTransactionManager($tenantId);

    // DBAL Transaction Manager for this tenant
    $dbalTxn = $this->doctrine->getDbalTransactionManager($tenantId);

    $em->persist($entity);
    $em->flush();
}
```

### Transactions with Multi-Tenancy

Since transactions are tied to a specific tenant, obtain the tenant's transaction manager and use it directly (or wire it into your own transaction orchestration):

```php
public function executeInTransaction(string $tenantId): void {
    $emTxn = $this->doctrine->getEmTransactionManager($tenantId);
    $emTxn->getEntityManager()->persist($entity);
    // ... use the transaction manager's API directly
}
```

### How It Works Internally

- [`TenantDoctrineProvider`](src/multitenancy/TenantDoctrineProvider.php) maintains lazy pools of `tenantId → Connection`, `tenantId → EntityManager`, `tenantId → EmTransactionManager`, and `tenantId → DbalTransactionManager`.
- On first access for a tenant, it calls `TenantConnectionProvider::resolveParams()` to obtain the tenant's connection parameters, then builds and caches the Doctrine objects.
- Subsequent calls for the same tenant reuse the cached objects.
- The tenant ID is **explicit** — there is no hidden thread-local state. The caller always passes `$tenantId`.
- Non-tenant datasources continue to work exactly as before — only datasources with `tenantTemplate: true` get a `TenantDoctrineProvider` bean.

### Helper Methods

```php
// Close all cached connections/entity managers
$this->doctrine->close();

// Evict a specific tenant (force reconnect on next access)
$this->doctrine->evictTenant('tenant-123');

// List all currently-cached tenant IDs
$tenantIds = $this->doctrine->getCachedTenantIds();
```
