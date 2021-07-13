<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\StaticReflectionService;

/**
 * The DisconnectedClassMetadataFactory is used to create ClassMetadataInfo objects
 * that do not require the entity class actually exist. This allows us to
 * load some mapping information and use it to do things like generate code
 * from the mapping information.
 *
 * @link    www.doctrine-project.org
 */
class DisconnectedClassMetadataFactory extends ClassMetadataFactory
{
    /**
     * @return StaticReflectionService
     */
    public function getReflectionService()
    {
        return new StaticReflectionService();
    }
}
