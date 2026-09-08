<?php

declare(strict_types=1);

namespace dev\winterframework\doctrine\common;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;

/**
 * datetimetz_immutable that tolerates real PostgreSQL output.
 *
 * PostgreSQL renders TIMESTAMPTZ with fractional seconds whenever they
 * are nonzero (e.g. "2026-09-03 10:57:50.91198+00" from DEFAULT NOW()),
 * but DBAL's platform format "Y-m-d H:i:sO" has no ".u" — conversion
 * then throws and any read of such a row 500s. Whole-second values
 * parse under the parent format and are untouched.
 *
 * Register once per process before the first conversion:
 *   Type::overrideType(Types::DATETIMETZ_IMMUTABLE, TolerantDateTimeTzImmutableType::class);
 */
class TolerantDateTimeTzImmutableType extends DateTimeTzImmutableType {
    /** @var string[] formats tried before delegating to the parent */
    private const array EXTRA_FORMATS = [
        'Y-m-d H:i:s.uO',
        'Y-m-d H:i:s.uP',
    ];

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable {
        if (is_string($value)) {
            foreach (self::EXTRA_FORMATS as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        return parent::convertToPHPValue($value, $platform);
    }
}
