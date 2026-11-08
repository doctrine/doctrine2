<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Pagination;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\BinaryCode;
use Doctrine\Tests\DbalTypes\BinaryCodeType;

#[Table(name: 'pagination_coded_item')]
#[Entity]
class CodedItem
{
    #[Column(type: 'integer')]
    #[Id]
    #[GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[Column(type: BinaryCodeType::NAME)]
        public BinaryCode $code,
    ) {
    }
}
