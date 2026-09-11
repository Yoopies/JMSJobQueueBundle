<?php

namespace JMS\JobQueueBundle\Entity\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * Stores a flattened exception as JSON.
 *
 * Values are read back as the array produced by FlattenException::toArray()
 * rather than as an object: that array is the only representation callers ever
 * use, and JSON keeps class names out of the column, which is what made old
 * rows unreadable whenever an exception class was renamed or removed.
 *
 * Rows written before the switch to JSON still hold a serialized
 * FlattenException and are converted on read, so no data migration is needed.
 * A row that cannot be converted - typically because it names a class that no
 * longer exists - reads as false, so callers can tell "no stack trace" apart
 * from "a stack trace we can no longer display".
 */
class SafeObjectType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // Kept as a BLOB so existing installations need no schema migration.
        return $platform->getBlobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof FlattenException) {
            $value = $value->toArray();
        }

        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!str_starts_with($value, '[') && !str_starts_with($value, '{')) {
            return self::convertLegacySerializedValue($value);
        }

        try {
            return json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Converts a row written before this type stored JSON.
     */
    private static function convertLegacySerializedValue(string $value): array|false|null
    {
        // Jobs that ended without an exception were stored as serialize(null).
        if ('N;' === $value) {
            return null;
        }

        try {
            $exception = @unserialize($value, ['allowed_classes' => [FlattenException::class]]);
        } catch (\Throwable) {
            return false;
        }

        // Anything else - a class that no longer exists, a truncated payload -
        // is a trace we can no longer display.
        if ( ! $exception instanceof FlattenException) {
            return false;
        }

        try {
            return $exception->toArray();
        } catch (\Throwable) {
            return false;
        }
    }
}
