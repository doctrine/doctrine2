<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\UnitOfWork;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use Throwable;

use function implode;
use function is_object;
use function is_scalar;
use function serialize;
use function spl_object_id;

/**
 * Computes the string key of a metadata-declared unique tuple for one
 * entity, in the database representation: every field component is
 * converted with Type::convertToDatabaseValue() for the platform of the
 * flush, in memory and without I/O, so keys are equal when the database
 * sees equal values.
 *
 * The key is null when the tuple is not comparable: a component is NULL
 * (NULL never conflicts in a unique constraint), the candidate's snapshot
 * does not carry it, or the component has no database representation — a
 * converter failure or a non-scalar conversion result. The comparison of
 * that candidate against that declaration is skipped; this class never
 * throws.
 *
 * @internal
 */
final class UniqueTupleKey
{
    public function __construct(
        private readonly AbstractPlatform $platform,
    ) {
    }

    /**
     * String key of the unique tuple for the entity, or null when the tuple
     * is not comparable.
     *
     * The snapshot is the candidate side: the contents of its database row.
     * A null snapshot reads the insertion side from the entity's current
     * values.
     *
     * @param ClassMetadata<object>     $class
     * @param list<array{string, bool}> $declaration
     * @param array<string, mixed>|null $snapshot    entity data snapshot for the candidate side
     */
    public function forDeclaration(ClassMetadata $class, object $entity, array $declaration, array|null $snapshot): string|null
    {
        $parts = [];

        foreach ($declaration as [$fieldName, $isAssociation]) {
            if ($snapshot === null) {
                $value = $class->getFieldValue($entity, $fieldName);
            } else {
                $value = $snapshot[$fieldName] ?? null;
            }

            if ($isAssociation) {
                if (! is_object($value)) {
                    return null;
                }

                $parts[] = 'o' . spl_object_id($value);

                continue;
            }

            if ($value === null) {
                return null;
            }

            $fieldMapping = $class->fieldMappings[$fieldName] ?? null;
            if ($fieldMapping === null) {
                return null;
            }

            try {
                $converted = Type::getType($fieldMapping->type)->convertToDatabaseValue($value, $this->platform);
            } catch (Throwable) {
                return null;
            }

            if (! is_scalar($converted)) {
                return null;
            }

            $parts[] = serialize($converted);
        }

        return implode(' ', $parts);
    }
}
