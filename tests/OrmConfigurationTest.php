<?php

declare(strict_types=1);

// Regression test: ORM configuration must build on every supported major
// without calling version-specific APIs unguarded (this fatally broke host
// apps on ORM 3: "Call to undefined method Configuration::
// enableNativeLazyObjects()"). Run with: php tests/run.php

use dev\winterframework\doctrine\common\OrmConfigurationFactory;
use WinterDoctrineTest\Support\Checks;

require_once __DIR__ . '/bootstrap.php';

$config = OrmConfigurationFactory::create([__DIR__ . '/Fixture'], true);
Checks::check('factory builds a Configuration', $config instanceof Doctrine\ORM\Configuration);
Checks::check(
    'metadata driver is wired',
    $config->getMetadataDriverImpl() !== null
);
Checks::check(
    'native lazy objects enabled where supported',
    !method_exists($config, 'isNativeLazyObjectsEnabled')
        || $config->isNativeLazyObjectsEnabled() === true
);
