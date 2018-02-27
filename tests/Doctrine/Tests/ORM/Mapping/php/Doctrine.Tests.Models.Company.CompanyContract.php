<?php

use Doctrine\ORM\Mapping\ClassMetadata;

$metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_JOINED);
$metadata->setTableName( 'company_contracts');
$metadata->setDiscriminatorColumn(
    [
    'name' => 'discr',
    'type' => 'string',
    ]
);

$metadata->mapField(
    [
    'id'        => true,
    'name'      => 'id',
    'fieldName' => 'id',
    ]
);

$metadata->mapField(
    [
    'type'      => 'boolean',
    'name'      => 'completed',
    'fieldName' => 'completed',
    ]
);

$metadata->setDiscriminatorMap(
    [
    "fix"       => "CompanyFixContract",
    "flexible"  => "CompanyFlexContract",
    "flexultra" => "CompanyFlexUltraContract"
    ]
);

$metadata->addEntityListener(\Doctrine\ORM\Events::postPersist, 'CompanyContractListener', 'postPersistHandler');
$metadata->addEntityListener(\Doctrine\ORM\Events::prePersist, 'CompanyContractListener', 'prePersistHandler');

$metadata->addEntityListener(\Doctrine\ORM\Events::postUpdate, 'CompanyContractListener', 'postUpdateHandler');
$metadata->addEntityListener(\Doctrine\ORM\Events::preUpdate, 'CompanyContractListener', 'preUpdateHandler');

$metadata->addEntityListener(\Doctrine\ORM\Events::postRemove, 'CompanyContractListener', 'postRemoveHandler');
$metadata->addEntityListener(\Doctrine\ORM\Events::preRemove, 'CompanyContractListener', 'preRemoveHandler');

$metadata->addEntityListener(\Doctrine\ORM\Events::preFlush, 'CompanyContractListener', 'preFlushHandler');
$metadata->addEntityListener(\Doctrine\ORM\Events::postLoad, 'CompanyContractListener', 'postLoadHandler');
