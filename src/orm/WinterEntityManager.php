<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use dev\winterframework\coroutine\CoroutineScopedPool;
use Doctrine\ORM\EntityManager;
use Override;
use ReflectionClass;
use Throwable;
use DateTimeInterface;
use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\UnitOfWork;

/**
 * Coroutine-scoped EntityManager façade.
 *
 * This IS an `EntityManager` (by inheritance), so bean names, `instanceof`
 * checks and existing concrete type-hints keep working. Every operation is
 * routed to the real EM bound to the current coroutine scope; outside
 * coroutines a single process-wide delegate is used (historic behaviour).
 *
 * Notes for maintainers:
 * - Every public `EntityManager` method must be overridden below so no call
 *   can reach the (never initialised) parent state. A reflection parity
 *   check guards this against ORM upgrades.
 * - `__call()` forwards anything missed, as defence in depth only.
 * - `close()` destroys just the current scope's delegate; the next touch in
 *   the same scope lazily rebuilds it, so a failed unit of work (e.g. a
 *   rolled-back `wrapInTransaction()`, which closes the EM) never poisons
 *   the rest of the coroutine.
 * - Never cache anything derived from this façade (`getRepository()`,
 *   query builders, proxies bind to one scope's delegate); inject and use
 *   the façade itself.
 */
class WinterEntityManager extends EntityManager {

    protected CoroutineScopedPool $pool;

    /**
     * The parent constructor is deliberately never invoked: all state lives
     * in the per-scope delegates. Use this factory instead of `new`.
     */
    public static function create(CoroutineScopedPool $pool): self {
        /** @var self $instance */
        $instance = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->pool = $pool;
        return $instance;
    }

    #[Override]
    public function close(): void {
        $this->pool->invalidateCurrent();
    }

    /**
     * Defence in depth: forward any method a future ORM version adds before
     * the parity check forces a real override.
     */
    public function __call(string $name, array $arguments): mixed {
        try {
            $delegate = $this->pool->current();
            return $delegate->$name(...$arguments);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    #[Override]
    public function getConnection(): Connection {
        return $this->pool->current()->getConnection();
    }

    #[Override]
    public function getMetadataFactory(): ClassMetadataFactory {
        return $this->pool->current()->getMetadataFactory();
    }

    #[Override]
    public function getExpressionBuilder(): Expr {
        return $this->pool->current()->getExpressionBuilder();
    }

    #[Override]
    public function beginTransaction(): void {
        $this->pool->current()->beginTransaction();
    }

    #[Override]
    public function getCache(): ?Cache {
        return $this->pool->current()->getCache();
    }

    #[Override]
    public function wrapInTransaction(callable $func): mixed {
        return $this->pool->current()->wrapInTransaction($func);
    }

    #[Override]
    public function commit(): void {
        $this->pool->current()->commit();
    }

    #[Override]
    public function rollback(): void {
        $this->pool->current()->rollback();
    }

    #[Override]
    public function getClassMetadata(string $className): ClassMetadata {
        return $this->pool->current()->getClassMetadata($className);
    }

    #[Override]
    public function createQuery(string $dql = ''): Query {
        return $this->pool->current()->createQuery($dql);
    }

    #[Override]
    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery {
        return $this->pool->current()->createNativeQuery($sql, $rsm);
    }

    #[Override]
    public function createQueryBuilder(): QueryBuilder {
        return $this->pool->current()->createQueryBuilder();
    }

    #[Override]
    public function flush(): void {
        $this->pool->current()->flush();
    }

    #[Override]
    public function find($className, mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object {
        return $this->pool->current()->find($className, $id, $lockMode, $lockVersion);
    }

    #[Override]
    public function getReference(string $entityName, mixed $id): ?object {
        return $this->pool->current()->getReference($entityName, $id);
    }

    #[Override]
    public function clear(): void {
        $this->pool->current()->clear();
    }

    #[Override]
    public function persist(object $object): void {
        $this->pool->current()->persist($object);
    }

    #[Override]
    public function remove(object $object): void {
        $this->pool->current()->remove($object);
    }

    #[Override]
    public function refresh(object $object, LockMode|int|null $lockMode = LockMode::NONE): void {
        $this->pool->current()->refresh($object, $lockMode);
    }

    #[Override]
    public function detach(object $object): void {
        $this->pool->current()->detach($object);
    }

    #[Override]
    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void {
        $this->pool->current()->lock($entity, $lockMode, $lockVersion);
    }

    #[Override]
    public function getRepository(string $className): EntityRepository {
        return $this->pool->current()->getRepository($className);
    }

    #[Override]
    public function contains(object $object): bool {
        return $this->pool->current()->contains($object);
    }

    #[Override]
    public function getEventManager(): EventManagerInterface {
        return $this->pool->current()->getEventManager();
    }

    #[Override]
    public function getConfiguration(): Configuration {
        return $this->pool->current()->getConfiguration();
    }

    #[Override]
    public function isOpen(): bool {
        return $this->pool->current()->isOpen();
    }

    #[Override]
    public function getUnitOfWork(): UnitOfWork {
        return $this->pool->current()->getUnitOfWork();
    }

    #[Override]
    public function newHydrator(string|int $hydrationMode): AbstractHydrator {
        return $this->pool->current()->newHydrator($hydrationMode);
    }

    #[Override]
    public function getProxyFactory(): ProxyFactory {
        return $this->pool->current()->getProxyFactory();
    }

    #[Override]
    public function initializeObject(object $obj): void {
        $this->pool->current()->initializeObject($obj);
    }

    #[Override]
    public function isUninitializedObject($value): bool {
        return $this->pool->current()->isUninitializedObject($value);
    }

    #[Override]
    public function getFilters(): FilterCollection {
        return $this->pool->current()->getFilters();
    }

    #[Override]
    public function isFiltersStateClean(): bool {
        return $this->pool->current()->isFiltersStateClean();
    }

    #[Override]
    public function hasFilters(): bool {
        return $this->pool->current()->hasFilters();
    }

}
