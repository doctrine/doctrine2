<?php

use Doctrine\ORM\Mapping\ClassMetadata;

$metadata->enableCache(
    [
    'usage' => ClassMetadata::CACHE_USAGE_READ_ONLY
    ]
);
$metadata->mapManyToOne(
    [
    'fieldName'      => 'foo',
    'id'         => true,
    'targetEntity'   => 'PHPSLCFoo'
    ]
);
