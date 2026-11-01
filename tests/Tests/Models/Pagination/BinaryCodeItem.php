<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Pagination;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\HexBinaryType;

#[Table(name: 'pagination_binary_code_item')]
#[Entity]
class BinaryCodeItem
{
    #[Column(type: 'integer')]
    #[Id]
    #[GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[Column(type: HexBinaryType::NAME, length: 4, options: ['fixed' => true])]
        public string $code,
    ) {
    }
}
