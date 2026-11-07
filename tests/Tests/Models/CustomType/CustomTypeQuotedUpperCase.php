<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Table(name: 'customtype_quoted_uppercases')]
#[Entity]
class CustomTypeQuotedUpperCase
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;

    /** @var string */
    #[Column(type: 'upper_case_string', length: 255, name: '`key`')]
    public $lowerCaseString;
}
