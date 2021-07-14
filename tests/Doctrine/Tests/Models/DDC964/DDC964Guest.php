<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\ORM\Mapping\AttributeOverride;
use Doctrine\ORM\Mapping\AttributeOverrides;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 * @AttributeOverrides({
 *      @AttributeOverride(name="id",
 *          column=@Column(
 *              name     = "guest_id",
 *              type     = "integer",
                length   = 140
 *          )
 *      ),
 *      @AttributeOverride(name="name",
 *          column=@Column(
 *              name     = "guest_name",
 *              nullable = false,
 *              unique   = true,
 *              length   = 240
 *          )
 *      )
 * })
 */
class DDC964Guest extends DDC964User
{
    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setAttributeOverride('id', [
            'columnName'    => 'guest_id',
            'type'          => 'integer',
            'length'        => 140,
        ]);

        $metadata->setAttributeOverride(
            'name',
            [
                'columnName'    => 'guest_name',
                'nullable'      => false,
                'unique'        => true,
                'length'        => 240,
            ]
        );
    }
}
