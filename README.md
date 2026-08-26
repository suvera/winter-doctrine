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


## How to use Transcations - as AOP

Articles About winter-boot framework.

1. [Transaction Management](https://github.com/suvera/winter-boot/blob/master/docs/transactions.md)
2. [Aspect Oriented Magic](https://github.com/suvera/winter-boot/blob/master/docs/custom_aop.md)

Executing something under ORM/DBAL transaction is pretty easy by just using **Transactional** annotation

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
