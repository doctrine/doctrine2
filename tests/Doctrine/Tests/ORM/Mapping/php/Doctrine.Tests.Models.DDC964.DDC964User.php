<?php

use Doctrine\ORM\Mapping\ClassMetadata;

$metadata->mapField(
    [
   'id'         => true,
   'fieldName'  => 'id',
   'type'       => 'integer',
   'columnName' => 'user_id',
   'length'     => 150,
    ]
);
$metadata->mapField(
    [
    'fieldName' => 'name',
    'type'      => 'string',
    'columnName'=> 'user_name',
    'nullable'  => true,
    'unique'    => false,
    'length'    => 250,
    ]
);

$metadata->mapManyToOne(
    [
   'fieldName'      => 'address',
   'targetEntity'   => 'DDC964Address',
   'cascade'        => ['persist','merge'],
   'joinColumn'     => ['name'=>'address_id', 'referencedColumnMame'=>'id'],
    ]
);

$metadata->mapManyToMany(
    [
   'fieldName'      => 'groups',
   'targetEntity'   => 'DDC964Group',
   'inversedBy'     => 'users',
   'cascade'        => ['persist','merge','detach'],
   'joinTable'      => [
        'name'          => 'ddc964_users_groups',
        'joinColumns'   => [
            [
            'name'=>'user_id',
            'referencedColumnName'=>'id',
            ]
        ],
        'inverseJoinColumns'=> [
            [
            'name'=>'group_id',
            'referencedColumnName'=>'id',
            ]
        ]
   ]
    ]
);

$metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
