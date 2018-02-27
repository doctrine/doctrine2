<?php

namespace Doctrine\Tests\Models\DDC869;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @MappedSuperclass(repositoryClass = "Doctrine\Tests\Models\DDC869\DDC869PaymentRepository")
 */
class DDC869Payment
{

    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /** @Column(type="float") */
    protected $value;


    public static function loadMetadata(ClassMetadata $metadata)
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
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
    }

}
