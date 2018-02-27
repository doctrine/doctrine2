<?php

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\ORM\Mapping\ClassMetadata;

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
                length   = 240
 *          )
 *      )
 * })
 */
class DDC964Guest extends DDC964User
{
    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->setAttributeOverride('id', [
            'columnName'    => 'guest_id',
            'type'          => 'integer',
            'length'        => 140,
        ]
        );

        $metadata->setAttributeOverride('name',
            [
            'columnName'    => 'guest_name',
            'nullable'      => false,
            'unique'        => true,
            'length'        => 240,
            ]
        );
    }
}
