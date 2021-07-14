<?php

namespace Doctrine\Tests\Models\DDC869;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\MappedSuperclass;

/**
 * @MappedSuperclass(repositoryClass = "Doctrine\Tests\Models\DDC869\DDC869PaymentRepository")
 */
#[ORM\MappedSuperclass(repositoryClass: DDC869PaymentRepository::class)]
class DDC869Payment
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    protected $id;

    /**
     * @var float
     * @Column(type="float")
     */
    #[ORM\Column(type: 'float')]
    protected $value;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id'         => true,
                'fieldName'  => 'id',
                'type'       => 'integer',
                'columnName' => 'id',
            ]
        );
        $metadata->mapField(
            [
                'fieldName'  => 'value',
                'type'       => 'float',
            ]
        );
        $metadata->isMappedSuperclass = true;
        $metadata->setCustomRepositoryClass(DDC869PaymentRepository::class);
        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
    }
}
