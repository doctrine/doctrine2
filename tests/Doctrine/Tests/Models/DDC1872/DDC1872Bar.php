<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC1872;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;

/**
 * @Entity
 */
class DDC1872Bar
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     */
    private $id;
}
