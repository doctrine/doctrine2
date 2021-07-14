<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CompositeKeyInheritance;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name = "joined_derived_identity")
 */
class JoinedDerivedIdentityClass
{
    /**
     * @var string
     * @Column(type="string")
     * @Id
     */
    protected $id = 'part-0';

    /**
     * @var JoinedDerivedRootClass[]
     * @OneToMany(
     *     targetEntity="JoinedDerivedRootClass",
     *     mappedBy="keyPart1"
     * )
     */
    protected $children;
}
