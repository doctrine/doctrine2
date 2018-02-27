<?php

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Models\Cache\Attraction;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\Cache\Travel;

$metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_NONE);
$metadata->setPrimaryTable(['name' => 'cache_city']);
$metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
$metadata->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_DEFERRED_IMPLICIT);

$metadata->enableCache(
    [
    'usage' => ClassMetadata::CACHE_USAGE_READ_ONLY
    ]
);

$metadata->mapField(
    [
   'fieldName' => 'id',
   'type' => 'integer',
   'id' => true,
    ]
);

$metadata->mapField(
    [
   'fieldName' => 'name',
   'type' => 'string',
    ]
);


$metadata->mapOneToOne(
    [
   'fieldName'      => 'state',
   'targetEntity'   => State::class,
   'inversedBy'     => 'cities',
   'joinColumns'    =>
   [
       [
    'name' => 'state_id',
    'referencedColumnName' => 'id',
       ]
   ]
    ]
);
$metadata->enableAssociationCache('state', [
    'usage' => ClassMetadata::CACHE_USAGE_READ_ONLY
]
);

$metadata->mapManyToMany(
    [
   'fieldName' => 'travels',
   'targetEntity' => Travel::class,
   'mappedBy' => 'visitedCities',
    ]
);

$metadata->mapOneToMany(
    [
   'fieldName' => 'attractions',
   'targetEntity' => Attraction::class,
   'mappedBy' => 'city',
   'orderBy' => ['name' => 'ASC',],
    ]
);
$metadata->enableAssociationCache('attractions', [
    'usage' => ClassMetadata::CACHE_USAGE_READ_ONLY
]
);
