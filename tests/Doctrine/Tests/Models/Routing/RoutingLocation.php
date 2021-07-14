<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Routing;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/**
 * @Entity
 */
class RoutingLocation
{
    /**
     * @var int
     * @Id @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    public function getName(): string
    {
        return $this->name;
    }
}
