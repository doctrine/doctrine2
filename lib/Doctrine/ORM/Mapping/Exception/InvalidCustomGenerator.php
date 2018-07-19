<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Exception;

use Doctrine\ORM\ORMException;
use function sprintf;
use function var_export;

final class InvalidCustomGenerator extends \LogicException implements ORMException
{
    public static function onClassNotConfigured() : self
    {
        return new self('Cannot instantiate custom generator, no class has been defined');
    }

    /**
     * @param mixed[] $definition
     */
    public static function onMissingClass(array $definition) : self
    {
        return new self(sprintf(
            'Cannot instantiate custom generator : %s',
            var_export($definition, true)
        ));
    }
}
