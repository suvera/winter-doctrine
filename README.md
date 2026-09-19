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
        migrations:
            enabled: false
        doctrine:
            entityPaths:
                - /path/to/defaultdb/entities
            isDevMode: false

    -   name: admindb
        url: "mysql:host=localhost;port=3307;dbname=testdb"
        username: xxxxx
        password: xxzzz
        migrations:
            enabled: true
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


## Coroutine-scoped EntityManagers (Swoole)

Think of the `EntityManager` as a notebook. It remembers every database row
your code has touched, so it does not have to ask the database twice. That
is great inside one request — and dangerous on a Swoole server, where one
worker handles hundreds of requests one after another. Without protection,
request number 2 would read request number 1's old notes instead of fresh
data from the database. Worse, two requests running at the same time would
scribble in the same notebook and corrupt each other's unflushed changes.

This library fixes that for you, automatically. Under Swoole, every request
(or job, or message) silently gets its own blank notebook with its own
database connection. When the request finishes, its notebook is thrown away.
You change nothing in your code.

Your existing code keeps working exactly as it is:

```php
#[Autowired]
private EntityManager $defaultEm;

public function findUser(int $id): ?User {
    // Always fresh data. Always isolated from other requests.
    return $this->defaultEm->find(User::class, $id);
}
```

Bean names, `instanceof EntityManager` checks, type-hints, and both
transaction styles (`#[Transactional]` and `EmTransactionManager`) all work
unchanged. Code paths that never touch the database allocate nothing, and
short-lived CLI scripts behave exactly as before.

You can turn the whole thing off with one flag (it is on under Swoole and
off without it):

```yaml
winter:
    coroutine:
        db:
            enabled: true   # kill switch: set to false to restore old behavior
```

Four rules to stay out of trouble:

1. **Always use the injected `EntityManager` itself.** Do NOT save things it
   gives you (`getRepository()` results, query builders) into a property and
   reuse them later — those belong to one request's notebook, which gets
   thrown away. Fetch them fresh from the `EntityManager` every time:

   ```php
   // WRONG: this repository dies with the first request's notebook.
   private EntityRepository $userRepo;

   // RIGHT: ask the EntityManager every time.
   $this->defaultEm->getRepository(User::class)->find($id);
   ```

2. **Keep event listeners stateless.** One shared event dispatcher serves all
   requests, so a listener that stores things in its own properties will mix
   up requests. Compute from the event, store nothing.

3. **Long-running loops are the one exception.** A daemon or scheduler that
   processes 10,000 items inside a single never-ending task reuses one
   notebook for all 10,000 items — and it keeps growing. Wrap each round so
   it gets a blank notebook, and you never have to think about it again:

   ```php
   use dev\winterframework\coroutine\CoroutineRunner;

   while (true) {
       // Each batch runs with a fresh notebook, then throws it away.
       CoroutineRunner::runInFreshCoroutine(fn() => $this->processNextBatch());
   }
   ```

   You do NOT need this helper for normal controllers, services, SQS/Kafka
   consumers, or scheduled jobs. Those already get a fresh notebook per run.
   Only loops that never end need it.

4. **One database connection per concurrent request, with a safety cap.**
   A blank notebook is useless without its own pen: every request that
   touches the database opens its own real database connection, so two
   requests can never share (and corrupt) one. Requests that never touch
   the database open none, and every connection is closed when its request
   finishes. The log line `Doctrine open DB connections: {...}` shows you
   the live count per pool — watch it after deploys.

   To stop a runaway fan-out from exhausting the database, each pool is
   capped at **50 open connections**. When all 50 are busy, the next
   request waits up to **5 seconds** for one to free up; if none does, it
   fails fast with a clear `PoolExhaustedException` instead of silently
   sharing (sharing would bring back the corruption bug). Tune both in
   `application.yml`:

   ```yaml
   winter:
       coroutine:
           db:
               maxConnections: 50  # max open DB connections per pool, 0 = unlimited (not recommended)
               maxWaitMs: 5000     # how long to wait for a free connection before failing
   ```

   A single datasource can override both caps in its own `connection`
   block (the override wins over the global defaults):

   ```yaml
   datasource:
       -   name: defaultdb
           # ...
           connection:
               maxConnections: 50
               maxWaitMs: 5000
   ```

   How to size the cap: add up every pool (one EntityManager pool plus one
   DBAL pool per datasource, per tenant — using each datasource's override
   where one is set), multiply by your Swoole worker count, and keep the
   total below the database's `max_connections`
   (MySQL defaults to 151, Postgres to 100). When in doubt, keep 50 and
   raise the database limit first — a loud `PoolExhaustedException` telling
   you to raise the cap is always better than a cryptic "too many
   connections" from the database at 3 AM.

   If the coroutine machinery itself fails, the failure is logged and the
   request degrades instead of crashing — but pool exhaustion stays loud:
   it still throws `PoolExhaustedException` rather than silently sharing
   a connection.

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

## Multi-Tenant Support

Winter Doctrine provides native support for multi-tenancy via `MultiTenantManager` and `TenantDataSourceProvider` (from winter-boot), allowing per-tenant `EntityManager`, `Connection`, and transaction manager instances.

### Configuration via `application.yml`

You can configure multi-tenant data sources directly in your `application.yml` by registering a provider class:

```yaml
multitenant-datasource:
    - name: "tenantdb"
      url: "mysql:host=localhost;port=3306"
      migrations:
          enabled: true
      providerClass: "App\\Config\\MyTenantDataSourceProvider"
```

When configured, Winter Doctrine automatically initializes and registers a `MultiTenantManager` bean named `<name>-manager` (e.g. `tenantdb-manager`) in the application context.

### Step 1: Implement TenantDataSourceProvider

Create a `#[Configuration]` class that returns your implementation of [`TenantDataSourceProvider`](https://github.com/suvera/winter-boot/blob/main/src/pdbc/multitenant/TenantDataSourceProvider.php).

```php
use dev\winterframework\pdbc\datasource\DataSourceConfig;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;
use dev\winterframework\doctrine\orm\EntityManager;
use dev\winterframework\pdbc\multitenant\TenantDataSourceProvider;

#[Configuration]
class MyTenantDataSourceProvider {

    #[Autowired("admindb-doctrine-em")]
    private EntityManager $adminEm;

    #[Bean]
    public function getTenantDataSourceProvider(): TenantDataSourceProvider {
        return new class implements TenantDataSourceProvider {
            public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig {
                // Query your admin database (or any config store) for this tenant
                // $tenant = $this->adminEm->find(Tenant::class, $tenantId);
                // $dbHost = $tenant->dbHost;
                // $dbPort = $tenant->dbPort;
                // $username = $tenant->username;
                // $dbName = $tenant->database;
                
                $config = new DataSourceConfig();
                $config->setName($tenantId);
                $config->setUrl("mysql:host=localhost;port=3306;dbname=tenant_{$tenantId}_db");
                $config->setUsername("tenant_user");
                $config->setPassword("tenant_pass");
                return $config;
            }

            public function getTenantDataSourceConfigs(int $offset, int $limit): array {
                // Return a list of tenant configurations (optional)
                // Used for batch operations or tenant management
                return [];
            }
        };
    }
}
```

### Step 2: Use in Business Classes

```php
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\doctrine\multitenancy\MultiTenantManager;

#[Service]
class TenantOrderService {

    #[Autowired]
    private MultiTenantManager $mt;

    public function createOrder(string $tenantId, array $orderData): void {
        // Get tenant-specific EntityManager
        $em = $this->mt->getEntityManager($tenantId);

        // Get tenant-specific Connection
        $conn = $this->mt->getConnection($tenantId);

        // Get tenant-specific TransactionManager
        $txnMgr = $this->mt->getEmTransactionManager($tenantId);

        $em->persist($order);
        $em->flush();
    }

    public function processOrders(string $tenantId): void {
        $em = $this->mt->getEntityManager($tenantId);
        $em->getConnection()->beginTransaction();
        try {
            // ... do work ...
            $em->getConnection()->commit();
        } catch (\Throwable $e) {
            $em->getConnection()->rollBack();
            throw $e;
        }
    }
}
```

### With Multiple MultiTenantManagers

If you have multiple multi-tenant data sources:

```yaml
multitenant-datasource:
    - name: "regionDb"
      url: "mysql:host=localhost;port=3306"
      migrations:
          enabled: true
      providerClass: "App\\Config\\RegionTenantProvider"
    - name: "productDb"
      url: "mysql:host=localhost;port=3306"
      migrations:
          enabled: true
      providerClass: "App\\Config\\ProductTenantProvider"
```

```php
#[Component]
class CrossTenantService {

    #[Autowired("regionDb-manager")]
    private MultiTenantManager $regionMt;

    #[Autowired("productDb-manager")]
    private MultiTenantManager $productMt;

    public function process(string $regionTenantId, string $productTenantId): void {
        $regionEm = $this->regionMt->getEntityManager($regionTenantId);
        $productConn = $this->productMt->getConnection($productTenantId);
        // ...
    }
}
```

### API Reference

#### MultiTenantManager

The `MultiTenantManager` class manages tenant-specific Doctrine connections and entity managers.

##### `getEntityManager`

Returns a cached per-tenant `EntityManager`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$tenantId` | `string` | The unique identifier of the tenant |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`EntityManager`](https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/entity-managers.html) | Per-tenant EntityManager instance |

---

##### `getConnection`

Returns a cached per-tenant `Connection`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$tenantId` | `string` | The unique identifier of the tenant |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`Connection`](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/connection.html) | Per-tenant Connection instance |

---

##### `getEmTransactionManager`

Returns a cached per-tenant `EmTransactionManager`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$tenantId` | `string` | The unique identifier of the tenant |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`EmTransactionManager`](src/orm/EmTransactionManager.php) | Per-tenant ORM transaction manager instance |

---

##### `getDbalTransactionManager`

Returns a cached per-tenant `DbalTransactionManager`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$tenantId` | `string` | The unique identifier of the tenant |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`DbalTransactionManager`](src/dbal/DbalTransactionManager.php) | Per-tenant DBAL transaction manager instance |

---

### Helper Methods

```php
// Close all cached connections and entity managers
$this->mt->close();

// Evict a specific tenant (force reconnect on next access)
$this->mt->evictTenant('tenant-123');

// List all currently-cached tenant IDs
$tenantIds = $this->mt->getCachedTenantIds();

// Get the tenant data source provider for advanced use
$provider = $this->mt->getTenantDataSourceProvider();
```
