<?php

declare(strict_types=1);

namespace Doctrine\ORM\Configuration\Exception;

use Doctrine\Common\Persistence\ObjectRepository;

final class InvalidEntityRepository extends \LogicException implements ConfigurationException
{
    public static function fromClassName(string $className) : self
    {
        return new self(
            "Invalid repository class '" . $className . "'. It must be a " . ObjectRepository::class . '.'
        );
    }
}
