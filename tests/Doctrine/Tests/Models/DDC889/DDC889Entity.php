<?php

namespace Doctrine\Tests\Models\DDC889;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 */
#[ORM\Entity]
class DDC889Entity extends DDC889SuperClass
{
    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
    }
}
