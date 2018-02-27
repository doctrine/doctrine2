<?php

namespace Doctrine\Tests\Models\DDC889;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @MappedSuperclass
 */
class DDC889SuperClass
{

    /** @Column() */
    protected $name;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
           'fieldName'  => 'name',
            ]
        );

        $metadata->isMappedSuperclass = true;
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    }
}
