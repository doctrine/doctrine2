<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Tests\OrmFunctionalTestCase;

class SubselectFetchCollectionTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema([
                $this->_em->getClassMetadata(SubselectFetchOwner::class),
                $this->_em->getClassMetadata(SubselectFetchChild::class),
            ]);
        } catch(\Exception $e) {
        }
    }

    public function testSubselectFetchMode()
    {
        $owner = $this->createOwnerWithChildren(2);
        $owner2 = $this->createOwnerWithChildren(3);

        $this->_em->flush();

        $this->_em->clear();

        $owner = $this->_em->find(SubselectFetchOwner::class, $owner->id);

        $afterQueryCount = $this->getCurrentQueryCount();
        $this->assertCount(2, $owner->children);
        $anotherQueryCount = $this->getCurrentQueryCount();

        $this->assertEquals($anotherQueryCount, $afterQueryCount);

        $this->assertCount(3, $this->_em->find(SubselectFetchOwner::class, $owner2->id)->children);

        $this->_em->clear();

        $beforeQueryCount = $this->getCurrentQueryCount();
        $owners = $this->_em->getRepository(SubselectFetchOwner::class)->findAll();
        $anotherQueryCount = $this->getCurrentQueryCount();

        // the findAll() + 1 subselect loading both collections of the two returned $owners
        $this->assertEquals($beforeQueryCount + 2, $anotherQueryCount);

        $this->assertCount(2, $owners[0]->children);
        $this->assertCount(3, $owners[1]->children);

        // both collections are already initialized and count'ing them does not make a difference in total query count
        $this->assertEquals($anotherQueryCount, $this->getCurrentQueryCount());
    }

    /**
     * @return SubselectFetchOwner
     * @throws \Doctrine\ORM\ORMException
     */
    protected function createOwnerWithChildren(int $children): SubselectFetchOwner
    {
        $owner = new SubselectFetchOwner();
        $this->_em->persist($owner);

        for ($i = 0; $i < $children; $i++) {
            $child = new SubselectFetchChild();
            $child->owner = $owner;

            $owner->children->add($child);

            $this->_em->persist($child);
        }

        return $owner;
    }
}

/**
 * @Entity
 */
class SubselectFetchOwner
{
    /** @Id @Column(type="integer") @GeneratedValue() */
    public $id;

    /**
     * @var ArrayCollection
     *
     * @OneToMany(targetEntity="SubselectFetchChild", mappedBy="owner", fetch="SUBSELECT")
     */
    public $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}

/**
 * @Entity
 */
class SubselectFetchChild
{
    /** @Id @Column(type="integer") @GeneratedValue() */
    public $id;

    /**
     * @ManyToOne(targetEntity="SubselectFetchOwner", inversedBy="children")
     *
     * @var SubselectFetchOwner
     */
    public $owner;
}