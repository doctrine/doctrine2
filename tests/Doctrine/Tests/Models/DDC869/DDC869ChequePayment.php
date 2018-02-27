<?php

namespace Doctrine\Tests\Models\DDC869;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity
 */
class DDC869ChequePayment extends DDC869Payment
{

    /** @Column(type="string") */
    protected $serialNumber;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
           'fieldName'  => 'serialNumber',
           'type'       => 'string',
            ]
        );
    }

}
