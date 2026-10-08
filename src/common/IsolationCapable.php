<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

/**
 * A Doctrine resource (EntityManager or Connection façade) that can switch
 * the current scope onto a dedicated delegate with its own connection, so
 * a REQUIRES_NEW / NOT_SUPPORTED transaction never shares the suspended
 * transaction's connection.
 */
interface IsolationCapable {

    public function supportsIsolation(): bool;

    /**
     * Route the current scope to a fresh, dedicated delegate.
     */
    public function beginIsolation(): void;

    /**
     * Destroy the current scope's dedicated delegate and route back to the
     * one that was active before beginIsolation().
     */
    public function endIsolation(): void;
}
