<?php

namespace Doctrine\Tests\Models\AddManyToOne;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;

class Reference
{
    public static function loadMetadata(ClassMetadata $metadata)
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->createField('id', 'integer')->isPrimaryKey()->generatedValue()->build();
        $builder->addField('name', 'string');

        $builder->addManyToOne('user', 'Doctrine\Tests\Models\AddManyToOne\User');
    }

    protected $id;
    protected $name;
    protected $user;
}
