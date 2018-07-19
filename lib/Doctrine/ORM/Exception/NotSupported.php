<?php

declare(strict_types=1);

namespace Doctrine\ORM\Exception;

use Doctrine\ORM\ORMException;

final class NotSupported extends \LogicException implements ORMException
{
    public static function create() : self
    {
        return new self('This behaviour is (currently) not supported by Doctrine 2');
    }
}
