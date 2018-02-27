<?php

use Doctrine\ORM\Mapping\ClassMetadata;

$metadata->mapField(
    [
   'id'                 => true,
   'fieldName'          => 'id',
    ]
);

$metadata->setDiscriminatorColumn(
    [
    'name'              => "dtype",
    'columnDefinition'  => "ENUM('ONE','TWO')"
    ]
);

$metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
