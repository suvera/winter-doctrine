<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

/**
 * A Doctrine SQLFilter whose parameters come from request state (for
 * example the current tenant) rather than from code that remembers to set
 * them.
 *
 * Under Swoole an EntityManager outlives the request (with coroutine
 * scoping off it lives for the whole worker), so parameters set once per
 * EntityManager or once per request go stale; work that skips the request
 * middleware (queue consumers, timers, async tasks) would run with the
 * previous request's values. Instead, FilterAwareEntityManager calls
 * currentParameters() whenever Doctrine builds SQL or a cache key and
 * copies changed values into the filter with setParameter(), which also
 * changes Doctrine's query-cache key.
 *
 * Do not read request state inside addFilterConstraint(): Doctrine caches
 * DQL→SQL by the filter parameters, so SQL built for one tenant would be
 * served to another. Use getParameter() there.
 *
 * Register filters per datasource (either form):
 *   doctrine: { filters: { tenant: App\Orm\TenantFilter } }
 *   doctrine: { filters: [ { name: tenant, class: App\Orm\TenantFilter } ] }
 * Every listed filter is added to the ORM configuration and enabled on
 * every EntityManager.
 */
interface ParameterizedFilter {
    /**
     * Parameter values for the current request: name => scalar|null.
     * A null value is passed as the string "" (the filter decides whether
     * that means "fail closed").
     *
     * Called on every query, so keep it cheap. If a worker serves requests
     * concurrently in coroutines, read coroutine-local state here, not a
     * static property.
     *
     * @return array<string, int|string|bool|null>
     */
    public static function currentParameters(): array;
}
