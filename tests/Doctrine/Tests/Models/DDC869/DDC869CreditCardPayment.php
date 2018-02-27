<?php

namespace Doctrine\Tests\Models\DDC869;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @Entity
 */
class DDC869CreditCardPayment extends DDC869Payment
{

    /** @Column(type="string") */
    protected $creditCardNumber;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
           'fieldName'  => 'creditCardNumber',
           'type'       => 'string',
            ]
        );
    }

}
