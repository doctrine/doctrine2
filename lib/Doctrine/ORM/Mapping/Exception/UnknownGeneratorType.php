<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Exception;

use Doctrine\ORM\ORMException;

final class UnknownGeneratorType extends \LogicException implements ORMException
{
    public static function create(string $generatorType) : self
    {
        return new self('Unknown generator type: ' . $generatorType);
    }
}
