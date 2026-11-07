<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Table(name: 'customtype_generated_uppercases')]
#[Entity]
class CustomTypeGeneratedUpperCase
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;

    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $name;

    /** @var string */
    #[Column(
        type: 'upper_case_string',
        length: 255,
        insertable: false,
        updatable: false,
        options: ['default' => 'GENERATED'],
        generated: 'INSERT',
    )]
    public $generatedLowerCaseString;
}
