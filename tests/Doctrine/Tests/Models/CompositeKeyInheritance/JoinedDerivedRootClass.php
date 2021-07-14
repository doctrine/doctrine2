<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CompositeKeyInheritance;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name = "joined_derived_root")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"child" = "JoinedDerivedChildClass", "root" = "JoinedDerivedRootClass"})
 */
class JoinedDerivedRootClass
{
    /**
     * @var JoinedDerivedIdentityClass
     * @ManyToOne(
     *     targetEntity="JoinedDerivedIdentityClass",
     *     inversedBy="children"
     * )
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
