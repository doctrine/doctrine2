<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="vct_inversed_onetomany")
 */
class InversedOneToManyEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id1;

    /**
     * @psalm-var Collection<int, OwningManyToOneEntity>
     * @OneToMany(targetEntity="OwningManyToOneEntity", mappedBy="associatedEntity")
     */
    public $associatedEntities;

    /**
     * @var string
     * @Column(type="string", name="some_property")
     */
    public $someProperty;

    public function __construct()
    {
        $this->associatedEntities = new ArrayCollection();
    }
}
