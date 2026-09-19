<?php

declare(strict_types=1);

// Regression test: facade signatures must stay compatible with every
// supported Doctrine version. ORM 3.x declares lock modes as
// `LockMode|int|null` (plain ints allowed); generating the overrides from
// ORM 4 narrower types (`?LockMode`) fatals at class load under ORM 3 with:
// "Declaration ... must be compatible". Run with: php tests/run.php

use dev\winterframework\doctrine\orm\WinterEntityManager;
use WinterDoctrineTest\Support\Checks;

require_once __DIR__ . '/bootstrap.php';

function lockModeAllowsInt(string $method, int $paramIndex): bool {
    $param = new ReflectionParameter([WinterEntityManager::class, $method], $paramIndex);
    $type = $param->getType();
    if (!$type instanceof ReflectionUnionType) {
        return false;
    }
    foreach ($type->getTypes() as $inner) {
        if ($inner instanceof ReflectionNamedType && $inner->getName() === 'int') {
            return true;
        }
    }
    return false;
}

Checks::check('find() lock mode accepts int (ORM 3 compat)', lockModeAllowsInt('find', 2));
Checks::check('refresh() lock mode accepts int (ORM 3 compat)', lockModeAllowsInt('refresh', 1));
Checks::check('lock() lock mode accepts int (ORM 3 compat)', lockModeAllowsInt('lock', 1));

// The facade must still BE an EntityManager for autowiring and type-hints.
Checks::check(
    'facade extends EntityManager',
    is_subclass_of(WinterEntityManager::class, Doctrine\ORM\EntityManager::class)
);
