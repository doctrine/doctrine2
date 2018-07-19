<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Exception;

use Doctrine\ORM\ORMException;
use function sprintf;

final class MissingColumnException extends \LogicException implements ORMException
{
    public static function fromColumnSourceAndTarget(string $column, string $source, string $target) : self
    {
        return new self(sprintf(
            'Column name "%s" referenced for relation from %s towards %s does not exist.',
            $column,
            $source,
            $target
        ));
    }
}
