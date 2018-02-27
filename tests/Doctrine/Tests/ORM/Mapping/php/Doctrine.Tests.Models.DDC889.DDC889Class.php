<?php

use Doctrine\ORM\Mapping\ClassMetadata;

$metadata->mapField(
    [
   'id'         => true,
   'fieldName'  => 'id',
   'type'       => 'integer',
   'columnName' => 'id',
    ]
);

//$metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
