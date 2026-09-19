<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\dbal;

use dev\winterframework\coroutine\CoroutineScopedPool;
use Doctrine\DBAL\Connection;
use Override;
use ReflectionClass;
use Closure;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\TransactionIsolationLevel;
use Traversable;

/**
 * Coroutine-scoped DBAL Connection façade.
 *
 * Same pattern as the ORM façade: this IS a `Connection`, every operation
 * routes to the PDO-owning delegate of the current coroutine scope, and
 * `close()` destroys only the current scope's delegate (rebuilt lazily on
 * next touch). See `WinterEntityManager` for the full rationale.
 *
 * Known limitation: DBAL marks `convertException()` and
 * `convertExceptionDuringQuery()` as `final`, so they cannot be routed to
 * the current scope's delegate. All internal Connection paths that need
 * them are overridden and never reach the façade state; only direct
 * userland calls to those two methods are affected (they operate on the
 * façade's uninitialised parent state and will fail — convert driver
 * exceptions against a delegate obtained from a real query instead).
 */
class WinterConnection extends Connection {

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
     * Defence in depth: forward any method a future DBAL version adds before
     * the parity check forces a real override.
     */
    public function __call(string $name, array $arguments): mixed {
        $delegate = $this->pool->current();
        return $delegate->$name(...$arguments);
    }

    #[Override]
    public function getParams(): array {
        return $this->pool->current()->getParams();
    }

    #[Override]
    public function getDatabase(): ?string {
        return $this->pool->current()->getDatabase();
    }

    #[Override]
    public function getDriver(): Driver {
        return $this->pool->current()->getDriver();
    }

    #[Override]
    public function getConfiguration(): Configuration {
        return $this->pool->current()->getConfiguration();
    }

    #[Override]
    public function getDatabasePlatform(): AbstractPlatform {
        return $this->pool->current()->getDatabasePlatform();
    }

    #[Override]
    public function createExpressionBuilder(): ExpressionBuilder {
        return $this->pool->current()->createExpressionBuilder();
    }

    #[Override]
    public function getServerVersion(): string {
        return $this->pool->current()->getServerVersion();
    }

    #[Override]
    public function isAutoCommit(): bool {
        return $this->pool->current()->isAutoCommit();
    }

    #[Override]
    public function setAutoCommit(bool $autoCommit): void {
        $this->pool->current()->setAutoCommit($autoCommit);
    }

    #[Override]
    public function fetchAssociative(string $query, array $params = array (), array $types = array ()): array|false {
        return $this->pool->current()->fetchAssociative($query, $params, $types);
    }

    #[Override]
    public function fetchNumeric(string $query, array $params = array (), array $types = array ()): array|false {
        return $this->pool->current()->fetchNumeric($query, $params, $types);
    }

    #[Override]
    public function fetchOne(string $query, array $params = array (), array $types = array ()): mixed {
        return $this->pool->current()->fetchOne($query, $params, $types);
    }

    #[Override]
    public function isConnected(): bool {
        return $this->pool->current()->isConnected();
    }

    #[Override]
    public function isTransactionActive(): bool {
        return $this->pool->current()->isTransactionActive();
    }

    #[Override]
    public function delete(string $table, array $criteria = array (), array $types = array ()): string|int {
        return $this->pool->current()->delete($table, $criteria, $types);
    }

    #[Override]
    public function setTransactionIsolation(TransactionIsolationLevel $level): void {
        $this->pool->current()->setTransactionIsolation($level);
    }

    #[Override]
    public function getTransactionIsolation(): TransactionIsolationLevel {
        return $this->pool->current()->getTransactionIsolation();
    }

    #[Override]
    public function update(string $table, array $data, array $criteria = array (), array $types = array ()): string|int {
        return $this->pool->current()->update($table, $data, $criteria, $types);
    }

    #[Override]
    public function insert(string $table, array $data, array $types = array ()): string|int {
        return $this->pool->current()->insert($table, $data, $types);
    }

    #[Override]
    public function quoteIdentifier(string $identifier): string {
        return $this->pool->current()->quoteIdentifier($identifier);
    }

    #[Override]
    public function quoteSingleIdentifier(string $identifier): string {
        return $this->pool->current()->quoteSingleIdentifier($identifier);
    }

    #[Override]
    public function quote(string $value): string {
        return $this->pool->current()->quote($value);
    }

    #[Override]
    public function fetchAllNumeric(string $query, array $params = array (), array $types = array ()): array {
        return $this->pool->current()->fetchAllNumeric($query, $params, $types);
    }

    #[Override]
    public function fetchAllAssociative(string $query, array $params = array (), array $types = array ()): array {
        return $this->pool->current()->fetchAllAssociative($query, $params, $types);
    }

    #[Override]
    public function fetchAllKeyValue(string $query, array $params = array (), array $types = array ()): array {
        return $this->pool->current()->fetchAllKeyValue($query, $params, $types);
    }

    #[Override]
    public function fetchAllAssociativeIndexed(string $query, array $params = array (), array $types = array ()): array {
        return $this->pool->current()->fetchAllAssociativeIndexed($query, $params, $types);
    }

    #[Override]
    public function fetchFirstColumn(string $query, array $params = array (), array $types = array ()): array {
        return $this->pool->current()->fetchFirstColumn($query, $params, $types);
    }

    #[Override]
    public function iterateNumeric(string $query, array $params = array (), array $types = array ()): Traversable {
        return $this->pool->current()->iterateNumeric($query, $params, $types);
    }

    #[Override]
    public function iterateAssociative(string $query, array $params = array (), array $types = array ()): Traversable {
        return $this->pool->current()->iterateAssociative($query, $params, $types);
    }

    #[Override]
    public function iterateKeyValue(string $query, array $params = array (), array $types = array ()): Traversable {
        return $this->pool->current()->iterateKeyValue($query, $params, $types);
    }

    #[Override]
    public function iterateAssociativeIndexed(string $query, array $params = array (), array $types = array ()): Traversable {
        return $this->pool->current()->iterateAssociativeIndexed($query, $params, $types);
    }

    #[Override]
    public function iterateColumn(string $query, array $params = array (), array $types = array ()): Traversable {
        return $this->pool->current()->iterateColumn($query, $params, $types);
    }

    #[Override]
    public function prepare(string $sql): Statement {
        return $this->pool->current()->prepare($sql);
    }

    #[Override]
    public function executeQuery(string $sql, array $params = array (), array $types = array (), ?QueryCacheProfile $qcp = NULL): Result {
        return $this->pool->current()->executeQuery($sql, $params, $types, $qcp);
    }

    #[Override]
    public function executeCacheQuery(string $sql, array $params, array $types, QueryCacheProfile $qcp): Result {
        return $this->pool->current()->executeCacheQuery($sql, $params, $types, $qcp);
    }

    #[Override]
    public function executeStatement(string $sql, array $params = array (), array $types = array ()): string|int {
        return $this->pool->current()->executeStatement($sql, $params, $types);
    }

    #[Override]
    public function getTransactionNestingLevel(): int {
        return $this->pool->current()->getTransactionNestingLevel();
    }

    #[Override]
    public function lastInsertId(): string|int {
        return $this->pool->current()->lastInsertId();
    }

    #[Override]
    public function transactional(Closure $func): mixed {
        return $this->pool->current()->transactional($func);
    }

    #[Override]
    public function setNestTransactionsWithSavepoints(bool $nestTransactionsWithSavepoints): void {
        $this->pool->current()->setNestTransactionsWithSavepoints($nestTransactionsWithSavepoints);
    }

    #[Override]
    public function getNestTransactionsWithSavepoints(): bool {
        return $this->pool->current()->getNestTransactionsWithSavepoints();
    }

    #[Override]
    public function beginTransaction(): void {
        $this->pool->current()->beginTransaction();
    }

    #[Override]
    public function commit(): void {
        $this->pool->current()->commit();
    }

    #[Override]
    public function rollBack(): void {
        $this->pool->current()->rollBack();
    }

    #[Override]
    public function createSavepoint(string $savepoint): void {
        $this->pool->current()->createSavepoint($savepoint);
    }

    #[Override]
    public function releaseSavepoint(string $savepoint): void {
        $this->pool->current()->releaseSavepoint($savepoint);
    }

    #[Override]
    public function rollbackSavepoint(string $savepoint): void {
        $this->pool->current()->rollbackSavepoint($savepoint);
    }

    #[Override]
    public function getNativeConnection() {
        return $this->pool->current()->getNativeConnection();
    }

    #[Override]
    public function createSchemaManager(): AbstractSchemaManager {
        return $this->pool->current()->createSchemaManager();
    }

    #[Override]
    public function setRollbackOnly(): void {
        $this->pool->current()->setRollbackOnly();
    }

    #[Override]
    public function isRollbackOnly(): bool {
        return $this->pool->current()->isRollbackOnly();
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, string $type): mixed {
        return $this->pool->current()->convertToDatabaseValue($value, $type);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, string $type): mixed {
        return $this->pool->current()->convertToPHPValue($value, $type);
    }

    #[Override]
    public function createQueryBuilder(): QueryBuilder {
        return $this->pool->current()->createQueryBuilder();
    }
}
