<?php

declare(strict_types=1);

namespace Doctrine\ORM\Exception;

use function implode;

/**
 * Exception thrown when a Proxy fails to retrieve an Entity result.
 */
final class EntityNotFound extends \RuntimeException implements ORMException
{
    /**
     * Static constructor.
     *
     * @param string   $className
     * @param string[] $id
     *
     * @return self
     */
    public static function fromClassNameAndIdentifier($className, array $id)
    {
        $ids = [];

        foreach ($id as $key => $value) {
            $ids[] = $key . '(' . $value . ')';
        }

        return new self(
            'Entity of type \'' . $className . '\'' . ($ids ? ' for IDs ' . implode(', ', $ids) : '') . ' was not found'
        );
    }
}
