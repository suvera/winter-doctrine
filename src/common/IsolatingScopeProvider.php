<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use dev\winterframework\coroutine\CoroutineScopeProvider;

/**
 * Scope provider decorator that lets a transaction manager open an
 * isolated sub-scope (REQUIRES_NEW / NOT_SUPPORTED propagation).
 *
 * While isolation is active, getScopeId() returns a derived id
 * ("<scope>#iso<depth>"), so a CoroutineScopedPool built on this provider
 * hands out a separate delegate with its own connection. That delegate is
 * counted against the pool's maxConnections like any other scope, waits
 * for a free slot the same way, and is destroyed at endIsolation() (or at
 * coroutine end, through the forwarded defer()).
 *
 * Isolation nests: each begin() pushes one level, each end() pops one.
 */
final class IsolatingScopeProvider implements CoroutineScopeProvider {

    private const PROCESS_SCOPE = 'process';

    /**
     * @var array<string, int> base scope id => isolation depth
     */
    private array $depths = [];

    public function __construct(
        private CoroutineScopeProvider $inner
    ) {
    }

    public function getInner(): CoroutineScopeProvider {
        return $this->inner;
    }

    public function isInCoroutine(): bool {
        return $this->inner->isInCoroutine();
    }

    public function getScopeId(): ?string {
        $base = $this->inner->getScopeId();
        $depth = $this->depths[$base ?? self::PROCESS_SCOPE] ?? 0;
        if ($depth === 0) {
            return $base;
        }
        return ($base ?? self::PROCESS_SCOPE) . '#iso' . $depth;
    }

    public function defer(callable $fn): void {
        $this->inner->defer($fn);
    }

    public function begin(): void {
        $base = $this->inner->getScopeId();
        $key = $base ?? self::PROCESS_SCOPE;
        if (!isset($this->depths[$key])) {
            $this->depths[$key] = 0;
            if ($base !== null) {
                // A crashed unit of work must not leave the scope isolated.
                $this->inner->defer(function () use ($key): void {
                    unset($this->depths[$key]);
                });
            }
        }
        $this->depths[$key]++;
    }

    public function end(): void {
        $key = $this->inner->getScopeId() ?? self::PROCESS_SCOPE;
        if (!isset($this->depths[$key])) {
            return;
        }
        $this->depths[$key]--;
        if ($this->depths[$key] <= 0) {
            unset($this->depths[$key]);
        }
    }

    public function getDepth(): int {
        return $this->depths[$this->inner->getScopeId() ?? self::PROCESS_SCOPE] ?? 0;
    }
}
