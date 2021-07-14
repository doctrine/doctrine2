<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ManyToManyPersister;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="manytomanypersister_child")
 */
class ChildClass
{
    /**
     * @Id
     * @Column(name="id1", type="integer")
     * @var int
     */
    public $id1;

    /**
     * @Id
     * @ManyToOne(targetEntity=OtherParentClass::class, cascade={"persist"})
     * @JoinColumn(name="other_parent_id", referencedColumnName="id")
     * @var OtherParentClass
     */
    public $otherParent;

    /**
     * @ManyToMany(targetEntity=ParentClass::class, inversedBy="children")
     * @JoinTable(
     *     name="parent_child",
     *     joinColumns={
     *         @JoinColumn(name="child_id1", referencedColumnName="id1"),
     *         @JoinColumn(name="child_id2", referencedColumnName="other_parent_id")
     *     },
     *     inverseJoinColumns={@JoinColumn(name="parent_id", referencedColumnName="id")}
     * )
     * @var Collection|ParentClass[]
     * @psalm-var Collection<ParentClass>
     */
    public $parents;

    public function __construct(int $id1, OtherParentClass $otherParent)
    {
        $this->id1         = $id1;
        $this->otherParent = $otherParent;
        $this->parents     = new ArrayCollection();
    }
}
