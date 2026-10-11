<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Single place that builds Doctrine ORM configurations.
 *
 * Version-sensitive setup lives here and nowhere else: optional ORM APIs
 * are enabled by capability (`method_exists`), never by version number,
 * so the same code runs on every supported ORM major.
 */
final class OrmConfigurationFactory {

    private function __construct() {
    }

    /**
     * @param array<string, class-string> $filters SQLFilter name => class
     */
    public static function create(array $entityPaths, bool $isDevMode, array $filters = []): Configuration {
        $config = ORMSetup::createAttributeMetadataConfig(
            $entityPaths,
            $isDevMode,
            null,
            new ArrayAdapter()
        );

        if (method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        foreach ($filters as $name => $class) {
            $config->addFilter((string)$name, $class);
        }

        return $config;
    }
}
