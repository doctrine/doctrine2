<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="vct_owning_manytomany_compositeid")
 */
class OwningManyToManyCompositeIdEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id3;

    /**
     * @var Collection<int, InversedManyToManyCompositeIdEntity>
     * @ManyToMany(targetEntity="InversedManyToManyCompositeIdEntity", inversedBy="associatedEntities")
     * @JoinTable(
     *     name="vct_xref_manytomany_compositeid",
     *     joinColumns={@JoinColumn(name="owning_id", referencedColumnName="id3")},
     *     inverseJoinColumns={
     *         @JoinColumn(name="inversed_id1", referencedColumnName="id1"),
     *         @JoinColumn(name="inversed_id2", referencedColumnName="id2")
     *     }
     * )
     */
    public $associatedEntities;

    public function __construct()
    {
        $this->associatedEntities = new ArrayCollection();
    }
}
