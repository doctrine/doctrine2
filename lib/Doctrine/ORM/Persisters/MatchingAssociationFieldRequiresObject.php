<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters;

use Doctrine\ORM\Exception\PersisterException;
use Doctrine\ORM\ORMException;
use LogicException;

use function sprintf;

final class MatchingAssociationFieldRequiresObject extends PersisterException
{
    public static function fromClassAndAssociation(string $class, string $associationName): self
    {
        return new self(sprintf(
            'Cannot match on %s::%s with a non-object value. Matching objects by id is ' .
            'not compatible with matching on an in-memory collection, which compares objects by reference.',
            $class,
            $associationName
        ));
    }
}
