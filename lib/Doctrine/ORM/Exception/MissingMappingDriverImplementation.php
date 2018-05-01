<?php

declare(strict_types=1);

namespace Doctrine\ORM\Exception;

final class MissingMappingDriverImplementation extends \LogicException implements ManagerException
{
    public static function new() : self
    {
        return new self(
            "It's a requirement to specify a Metadata Driver and pass it " .
            'to Doctrine\\ORM\\Configuration::setMetadataDriverImpl().'
        );
    }
}
