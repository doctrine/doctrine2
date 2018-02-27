<?php

use Doctrine\ORM\Mapping\ClassMetadata;

$metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_NONE);
$metadata->setPrimaryTable(
    [
   'indexes' => [
       ['columns' => ['content'], 'flags' => ['fulltext'], 'options'=> ['where' => 'content IS NOT NULL']]
   ]
    ]
);

$metadata->mapField(
    [
    'fieldName' => 'content',
    'type' => 'text',
    'scale' => 0,
    'length' => NULL,
    'unique' => false,
    'nullable' => false,
    'precision' => 0,
    'columnName' => 'content',
    ]
);
