<?php

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Models\DDC869\DDC869PaymentRepository;

$metadata->mapField(
    [
   'id'         => true,
   'fieldName'  => 'id',
   'type'       => 'integer',
   'columnName' => 'id',
    ]
);
$metadata->mapField(
    [
   'fieldName'  => 'value',
   'type'       => 'float',
    ]
);
$metadata->isMappedSuperclass = true;
$metadata->setCustomRepositoryClass(DDC869PaymentRepository::class);
$metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
