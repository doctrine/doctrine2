<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\GH12403;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'gh12403_scalar_entity')]
class GH12403ScalarEntity
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public int|null $id = null;

    #[Column(type: 'string', length: 50)]
    public string $name = '';
}
