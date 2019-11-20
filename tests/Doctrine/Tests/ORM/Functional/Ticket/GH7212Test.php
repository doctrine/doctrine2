<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Annotation\Column;
use Doctrine\ORM\Annotation\Entity;
use Doctrine\ORM\Annotation\Id;
use Doctrine\ORM\Annotation\ManyToOne;
use Doctrine\ORM\Annotation\OneToMany;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH7212Test extends OrmFunctionalTestCase
{
    /** @var EntityManagerInterface */
    private $entityManager;

    public function setUp() : void
    {
        parent::setUp();

        $this->entityManager = $this->em ?? $this->_em;
        $schemaTool = $this->schemaTool ?? $this->_schemaTool;

        try {
            $schemaTool->createSchema(
                [
                    $this->entityManager->getClassMetadata(GH7212Parent::class),
                    $this->entityManager->getClassMetadata(GH7212Child::class),
                ]
            );
        } catch (\Exception $ignore) {
        }
    }

    public function testRelationUpdatedWithIndexByDefined() : void
    {
        $parent = new GH7212Parent(1);
        $child = new GH7212Child(1, $parent);

        $this->entityManager->persist($parent);
        $this->entityManager->persist($child);

        $this->entityManager->flush();
        $this->entityManager->clear();

        /** @var GH7212Parent $parent */
        $parent = $this->entityManager->createQueryBuilder()
            ->select('p, c')
            ->from(GH7212Parent::class, 'p')
            ->leftJoin('p.children', 'c')
            ->getQuery()
            ->getSingleResult();

        $children = $parent->getChildren();
        foreach ($children as $child) {
            $child->setParent(null);
            $parent->removeChild($child);
        }

        $this->entityManager->flush();

        $childrenScalar = $this->entityManager->getConnection()->fetchAll('SELECT * FROM GH7212Child');
        $this->assertCount(1, $childrenScalar);
        $this->assertSame('1', $childrenScalar[0]['id']);
        $this->assertNull($childrenScalar[0]['parent_id']);
    }
}

/** @Entity */
class GH7212Parent
{
    /**
     * @Column(type="integer")
     * @Id
     * @var int
     */
    protected $id;

    /**
     * @OneToMany(targetEntity=GH7212Child::class, mappedBy="parent", indexBy="id")
     * @var GH7212Child[]|Collection
     */
    protected $children;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->children = new ArrayCollection();
    }

    public function getId() : int
    {
        return $this->id;
    }

    public function addChild(GH7212Child $child) : void
    {
        $this->children->set($child->getId(), $child);
    }

    public function removeChild(GH7212Child $child) : void
    {
        $this->children->remove($child->getId());
    }

    /**
     * @return GH7212Child[]Collection
     */
    public function getChildren() : Collection
    {
        return $this->children;
    }
}

/** @Entity */
class GH7212Child
{
    /**
     * @Column(type="integer")
     * @Id
     * @var int
     */
    private $id;

    /**
     * @ManyToOne(targetEntity=GH7212Parent::class, inversedBy="children")
     * @var GH7212Parent|null
     */
    private $parent;

    public function __construct(int $id, ?GH7212Parent $parent = null)
    {
        $this->id = $id;

        $this->setParent($parent);
    }

    public function getId() : int
    {
        return $this->id;
    }

    public function getParent() : ?GH7212Parent
    {
        return $this->parent;
    }

    public function setParent(?GH7212Parent $parent) : void
    {
        $this->parent = $parent;
    }
}
