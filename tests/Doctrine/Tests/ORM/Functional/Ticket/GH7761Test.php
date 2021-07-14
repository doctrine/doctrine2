<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

final class GH7761Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH7761Entity::class,
            GH7761ChildEntity::class,
        ]);

        $parent = new GH7761Entity();
        $child  = new GH7761ChildEntity();
        $parent->children->add($child);

        $this->_em->persist($parent);
        $this->_em->persist($child);
        $this->_em->flush();
        $this->_em->clear();
    }

    public function testCollectionClearDoesNotClearIfNotPersisted(): void
    {
        $entity = $this->_em->find(GH7761Entity::class, 1);
        assert($entity instanceof GH7761Entity);
        $entity->children->clear();
        $this->_em->persist(new GH7761Entity());
        $this->_em->flush();

        $this->_em->clear();

        $entity = $this->_em->find(GH7761Entity::class, 1);
        self::assertCount(1, $entity->children);
    }

    /**
     * @group GH-7862
     */
    public function testCollectionClearDoesClearIfPersisted(): void
    {
        $entity = $this->_em->find(GH7761Entity::class, 1);
        assert($entity instanceof GH7761Entity);
        $entity->children->clear();
        $this->_em->persist($entity);
        $this->_em->flush();

        $this->_em->clear();

        $entity = $this->_em->find(GH7761Entity::class, 1);
        self::assertCount(0, $entity->children);
    }
}

/**
 * @Entity
 * @ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class GH7761Entity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var Collection<int, GH7761ChildEntity>
     * @ManyToMany(targetEntity="Doctrine\Tests\ORM\Functional\Ticket\GH7761ChildEntity", cascade={"all"})
     * @JoinTable(name="gh7761_to_child",
     *     joinColumns={@JoinColumn(name="entity_id")},
     *     inverseJoinColumns={@JoinColumn(name="child_id")}
     * )
     */
    public $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}

/**
 * @Entity
 * @ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class GH7761ChildEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}
