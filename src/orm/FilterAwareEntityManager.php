<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\orm;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\FilterCollection;
use Override;

/**
 * The EntityManager the builder creates, in both modes (one per worker
 * with coroutine scoping off, one per coroutine scope with it on). It only
 * swaps in a SyncingFilterCollection, so ParameterizedFilter parameters
 * follow request state on every query path.
 */
class FilterAwareEntityManager extends EntityManager {
    private ?SyncingFilterCollection $syncingFilters = null;

    /** @param list<string> $names filter names to enable */
    public function enableFilters(array $names): void {
        $filters = $this->getFilters();
        foreach ($names as $name) {
            if (!$filters->isEnabled($name)) {
                $filters->enable($name);
            }
        }
    }

    #[Override]
    public function getFilters(): FilterCollection {
        return $this->syncingFilters ??= new SyncingFilterCollection($this);
    }

    #[Override]
    public function isFiltersStateClean(): bool {
        return $this->syncingFilters === null || $this->syncingFilters->isClean();
    }

    #[Override]
    public function hasFilters(): bool {
        return $this->syncingFilters !== null;
    }
}
