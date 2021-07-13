<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC869;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * @Entity
 */
#[ORM\Entity]
class DDC869ChequePayment extends DDC869Payment
{
    /**
     * @var string
     * @Column(type="string")
     */
    #[ORM\Column(type: 'string')]
    protected $serialNumber;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'fieldName'  => 'serialNumber',
                'type'       => 'string',
            ]
        );
    }
}
