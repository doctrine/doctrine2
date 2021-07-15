<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\ORM\Exception\ORMException;

use function implode;
use function sprintf;

/**
 * Exception thrown when a Proxy fails to retrieve an Entity result.
 */
class EntityNotFoundException extends ORMException
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

    /**
     * Instance for which no identifier can be found
     *
     * @psalm-param class-string $className
     */
    public static function noIdentifierFound(string $className): self
    {
        return new self(sprintf(
            'Unable to find "%s" entity identifier associated with the UnitOfWork',
            $className
        ));
    }
}
