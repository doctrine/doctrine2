<?php

namespace Doctrine\Tests\Models\DDC889;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\MappedSuperclass;

/**
 * @MappedSuperclass
 */
#[ORM\MappedSuperclass]
class DDC889SuperClass
{
    /**
     * @var string
     * @Column()
     */
    #[ORM\Column]
    protected $name;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            ['fieldName' => 'name']
        );

        $metadata->isMappedSuperclass = true;
        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
    }
}
