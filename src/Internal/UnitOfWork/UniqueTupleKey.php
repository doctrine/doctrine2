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
 * Builds a comparable key for a unique tuple of an entity from the database
 * values of its fields and the identity of its referenced entities.
 *
 * The key is null when the tuple cannot collide or cannot be compared: a NULL
 * component, a component missing from the snapshot, or a value that does not
 * convert to a scalar database value.
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
     * @param ClassMetadata<object>     $class
     * @param list<array{string, bool}> $declaration
     * @param array<string, mixed>|null $snapshot
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
