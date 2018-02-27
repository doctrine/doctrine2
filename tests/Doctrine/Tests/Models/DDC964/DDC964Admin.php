<?php

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\ORM\Mapping\ClassMetadata;

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
    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->setAssociationOverride('address',
            [
            'joinColumns'=> [
                [
                'name' => 'adminaddress_id',
                'referencedColumnName' => 'id',
                ]
            ]
            ]
        );

        $metadata->setAssociationOverride('groups',
            [
            'joinTable' => [
                'name'      => 'ddc964_users_admingroups',
                'joinColumns' => [
                    [
                    'name' => 'adminuser_id',
                    ]
                ],
                'inverseJoinColumns' => [[
                    'name'      => 'admingroup_id',
                ]]
            ]
            ]
        );
    }
}
