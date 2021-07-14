<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\JoinedInheritanceType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;

/**
 * @Entity
 * @InheritanceType("JOINED")
 */
class RootClass
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id @GeneratedValue
     */
    public $id;
}
