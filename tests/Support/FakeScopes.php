<?php

declare(strict_types=1);

namespace WinterDoctrineTest\Support;

use dev\winterframework\coroutine\CoroutineScopeProvider;

/**
 * Fake coroutine scopes for tests: emulates coroutines A, B, ... with
 * working defer hooks, without needing the Swoole extension.
 */
class FakeScopes implements CoroutineScopeProvider {
    public ?string $id = null;

    /** @var array<string, array<int, callable>> */
    public array $defers = [];

    public function isInCoroutine(): bool {
        return $this->id !== null;
    }

    public function getScopeId(): ?string {
        return $this->id;
    }

    public function defer(callable $fn): void {
        $this->defers[$this->id][] = $fn;
    }

    public function endScope(string $id): void {
        foreach ($this->defers[$id] ?? [] as $fn) {
            $fn();
        }
        unset($this->defers[$id]);
    }
}
