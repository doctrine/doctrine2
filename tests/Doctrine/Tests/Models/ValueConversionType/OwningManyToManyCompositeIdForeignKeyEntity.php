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
 * @Table(name="vct_owning_manytomany_compositeid_foreignkey")
 */
class OwningManyToManyCompositeIdForeignKeyEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id2;

    /**
     * @var Collection<int, InversedManyToManyCompositeIdForeignKeyEntity>
     * @ManyToMany(targetEntity="InversedManyToManyCompositeIdForeignKeyEntity", inversedBy="associatedEntities")
     * @JoinTable(
     *     name="vct_xref_manytomany_compositeid_foreignkey",
     *     joinColumns={@JoinColumn(name="owning_id", referencedColumnName="id2")},
     *     inverseJoinColumns={
     *         @JoinColumn(name="associated_id", referencedColumnName="id1"),
     *         @JoinColumn(name="associated_foreign_id", referencedColumnName="foreign_id")
     *     }
     * )
     */
    public $associatedEntities;

    public function __construct()
    {
        $this->associatedEntities = new ArrayCollection();
    }
}
