<?php

declare(strict_types=1);

namespace Doctrine\ORM\Exception;

use RuntimeException;

use function sprintf;

final class SharedCollection extends RuntimeException implements ORMException
{
    public static function betweenAssociations(string $className, string $fieldName, string $otherFieldName): self
    {
        return new self(sprintf(
            'The collection for %s::$%s is already managed for %s::$%s. A collection instance cannot be shared between two associations.',
            $className,
            $fieldName,
            $className,
            $otherFieldName,
        ));
    }
}
