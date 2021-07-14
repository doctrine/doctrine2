<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CompositeKeyInheritance;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"child" = "JoinedChildClass", "root" = "JoinedRootClass"})
 */
class JoinedRootClass
{
    /**
     * @var string
     * @Column(type="string")
     * @Id
     */
    protected $keyPart1 = 'part-1';

    /**
     * @var string
     * @Column(type="string")
     * @Id
     */
    protected $keyPart2 = 'part-2';
}
