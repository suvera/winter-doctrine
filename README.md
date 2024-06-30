# WinterBoot Module - Doctrine

Winter Doctrine is a module that provides easy configuration and access to Doctrine orm/dbal functionality from [WinterBoot](https://github.com/suvera/winter-boot) applications.

### About Doctrine:

- [https://www.doctrine-project.org/index.html](https://www.doctrine-project.org/index.html)

## Setup


```shell
composer require suvera/winter-doctrine
```

To enable [Eureka](https://www.doctrine-project.org/index.html) module in applications, append following code to **application.yml**

```yaml

modules:
    - module: dev\winterframework\eureka\DoctrineModule
      enabled: true

```

## application.yml

in your **application.yml** file you might already have setup datasources like this

```yaml

datasource:
    -   name: default
        url: "sqlite::memory:"
        username: xxxxx
        password: xxzzz
        doctrine:
            entityPaths:
                - /opt/databases/entities
                - /opt/databases/entities2
            isDevMode: false
        connection:
            persistent: true
            errorMode: ERRMODE_EXCEPTION
            columnsCase: CASE_NATURAL
            idleTimeout: 300
            autoCommit: true
            defaultrowprefetch: 100

```


Service/Client beans can be Autowired.

```phpt

#[Autowired('consulBean01')]
protected DiscoveryClient $discoveryClient;


#[Autowired('netflixBean01')]
protected EurekaClient $eurekaClient;
```