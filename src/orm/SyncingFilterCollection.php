<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use Override;
use WeakMap;

/**
 * A FilterCollection that copies ParameterizedFilter::currentParameters()
 * into its filters whenever Doctrine reads filter state: DQL parsing and
 * query-cache keys, find()/findBy(), lazy loads, collections and
 * second-level-cache keys all go through getEnabledFilters(), getHash() or
 * isClean().
 *
 * The EntityManager may live for the whole worker (it does when coroutine
 * scoping is off), so parameters cannot be set once per EntityManager or
 * once per request; they are read when each query is built. Only changed
 * values are written: an unchanged tenant keeps the filter state clean and
 * the query cache warm, a changed one makes Doctrine re-parse under a new
 * cache key.
 */
class SyncingFilterCollection extends FilterCollection {
    /** @var WeakMap<object, array<string, string>> last values written per filter instance */
    private WeakMap $written;

    public function __construct(EntityManagerInterface $em) {
        parent::__construct($em);
        $this->written = new WeakMap();
    }

    #[Override]
    public function getEnabledFilters(): array {
        $this->sync();
        return parent::getEnabledFilters();
    }

    #[Override]
    public function getHash(): string {
        $this->sync();
        return parent::getHash();
    }

    #[Override]
    public function isClean(): bool {
        $this->sync();
        return parent::isClean();
    }

    private function sync(): void {
        foreach (parent::getEnabledFilters() as $filter) {
            if (!$filter instanceof ParameterizedFilter) {
                continue;
            }
            $last = $this->written[$filter] ?? [];
            $changed = false;
            foreach ($filter::currentParameters() as $name => $value) {
                $value = $value === null ? '' : (string)$value;
                if (($last[$name] ?? null) !== $value) {
                    // Marks the collection dirty; does not re-enter sync().
                    $filter->setParameter($name, $value);
                    $last[$name] = $value;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->written[$filter] = $last;
            }
        }
    }
}
