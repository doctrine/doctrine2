<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Filter;

use Doctrine\ORM\Exception\ORMException;

use function sprintf;

class FilterException extends ORMException
{
    public static function cannotConvertListParameterIntoSingleValue(string $name): self
    {
        return new self(sprintf('Cannot convert list-based SQL filter parameter "%s" into a single value.', $name));
    }

    public static function cannotConvertSingleParameterIntoListValue(string $name): self
    {
        return new self(sprintf('Cannot convert single SQL filter parameter "%s" into a list value.', $name));
    }
}
