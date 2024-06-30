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
