<?php

declare(strict_types=1);

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\ClassMetadata;

/* @var $metadata ClassMetadata */
$fieldMetadata = new Mapping\FieldMetadata('id');
$fieldMetadata->setType(Type::getType('string'));
$fieldMetadata->setPrimaryKey(true);
$fieldMetadata->setIdentifierGeneratorType(Mapping\GeneratorType::NONE);

$metadata->addProperty($fieldMetadata);

$discrColumn = new Mapping\DiscriminatorColumnMetadata();

$discrColumn->setColumnName('dtype');
$discrColumn->setType(Type::getType('string'));
$discrColumn->setColumnDefinition("ENUM('ONE','TWO')");

$metadata->setDiscriminatorColumn($discrColumn);
