<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Hydration;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;

/** @Entity */
class EntityWithArrayDefaultArrayValueM2M
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @psalm-var Collection<int, SimpleEntity>
     * @ManyToMany(targetEntity=SimpleEntity::class)
     */
    public $collection = [];
}
