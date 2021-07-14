<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\ORM\Mapping\AssociationOverride;
use Doctrine\ORM\Mapping\AssociationOverrides;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;

/**
 * @Entity
 * @AssociationOverrides({
 *      @AssociationOverride(name="groups",
 *          joinTable=@JoinTable(
 *              name="ddc964_users_admingroups",
 *              joinColumns=@JoinColumn(name="adminuser_id"),
 *              inverseJoinColumns=@JoinColumn(name="admingroup_id")
 *          )
 *      ),
 *      @AssociationOverride(name="address",
 *          joinColumns=@JoinColumn(
 *              name="adminaddress_id", referencedColumnName="id"
 *          )
 *      )
 * })
 */
class DDC964Admin extends DDC964User
{
    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setAssociationOverride(
            'address',
            [
                'joinColumns' => [
                    [
                        'name' => 'adminaddress_id',
                        'referencedColumnName' => 'id',
                    ],
                ],
            ]
        );

        $metadata->setAssociationOverride(
            'groups',
            [
                'joinTable' => [
                    'name'      => 'ddc964_users_admingroups',
                    'joinColumns' => [
                        ['name' => 'adminuser_id'],
                    ],
                    'inverseJoinColumns' => [
                        ['name' => 'admingroup_id'],
                    ],
                ],
            ]
        );
    }
}
