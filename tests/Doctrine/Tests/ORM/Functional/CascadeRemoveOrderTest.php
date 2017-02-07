<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group CascadeRemoveOrderTest
 */
class CascadeRemoveOrderTest extends OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->schemaTool->createSchema(
            [
            $this->em->getClassMetadata(CascadeRemoveOrderEntityO::class),
            $this->em->getClassMetadata(CascadeRemoveOrderEntityG::class),
            ]
        );
    }

    protected function tearDown()
    {
        parent::tearDown();

        $this->schemaTool->dropSchema(
            [
            $this->em->getClassMetadata(CascadeRemoveOrderEntityO::class),
            $this->em->getClassMetadata(CascadeRemoveOrderEntityG::class),
            ]
        );
    }

    public function testSingle()
    {
        $eO = new CascadeRemoveOrderEntityO();
        $eG = new CascadeRemoveOrderEntityG($eO);

        $this->em->persist($eO);
        $this->em->flush();
        $this->em->clear();

        $eOloaded = $this->em->find(CascadeRemoveOrderEntityO::class, $eO->getId());

        $this->em->remove($eOloaded);
        $this->em->flush();

        $this->assertTrue(true);
    }

    public function testMany()
    {
        $eO  = new CascadeRemoveOrderEntityO();
        $eG1 = new CascadeRemoveOrderEntityG($eO);
        $eG2 = new CascadeRemoveOrderEntityG($eO);
        $eG3 = new CascadeRemoveOrderEntityG($eO);

        $eO->setOneToOneG($eG2);

        $this->em->persist($eO);
        $this->em->flush();
        $this->em->clear();

        $eOloaded = $this->em->find(CascadeRemoveOrderEntityO::class, $eO->getId());

        $this->em->remove($eOloaded);
        $this->em->flush();

        $this->assertTrue(true);
    }
}

/**
 * @Entity
 */
class CascadeRemoveOrderEntityO
{
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @OneToOne(targetEntity="Doctrine\Tests\ORM\Functional\CascadeRemoveOrderEntityG")
     * @JoinColumn(nullable=true, onDelete="SET NULL")
     */
    private $oneToOneG;

    /**
     * @OneToMany(
     *     targetEntity="Doctrine\Tests\ORM\Functional\CascadeRemoveOrderEntityG",
     *     mappedBy="ownerO",
     *     cascade={"persist", "remove"}
     * )
     */
    private $oneToManyG;


    public function __construct()
    {
        $this->oneToManyG = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setOneToOneG(CascadeRemoveOrderEntityG $eG)
    {
        $this->oneToOneG = $eG;
    }

    public function getOneToOneG()
    {
        return $this->oneToOneG;
    }

    public function addOneToManyG(CascadeRemoveOrderEntityG $eG)
    {
        $this->oneToManyG->add($eG);
    }

    public function getOneToManyGs()
    {
        return $this->oneToManyG->toArray();
    }
}

/**
 * @Entity
 */
class CascadeRemoveOrderEntityG
{
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @ManyToOne(
     *     targetEntity="Doctrine\Tests\ORM\Functional\CascadeRemoveOrderEntityO",
     *     inversedBy="oneToMany"
     * )
     */
    private $ownerO;

    public function __construct(CascadeRemoveOrderEntityO $eO, $position=1)
    {
        $this->position = $position;
        $this->ownerO= $eO;
        $this->ownerO->addOneToManyG($this);
    }

    public function getId()
    {
        return $this->id;
    }
}
