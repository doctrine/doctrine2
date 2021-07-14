<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectManagerAware;
use Doctrine\Tests\OrmFunctionalTestCase;

use function get_class;

/**
 * @group DDC-2231
 */
class DDC2231Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2231EntityY::class),
            ]
        );
    }

    public function testInjectObjectManagerInProxyIfInitializedInUow(): void
    {
        $y1 = new DDC2231EntityY();

        $this->_em->persist($y1);

        $this->_em->flush();
        $this->_em->clear();

        $y1ref = $this->_em->getReference(get_class($y1), $y1->id);

        $this->assertInstanceOf(Proxy::class, $y1ref);
        $this->assertFalse($y1ref->__isInitialized__);

        $id = $y1ref->doSomething();

        $this->assertTrue($y1ref->__isInitialized__);
        $this->assertEquals($this->_em, $y1ref->om);
    }
}


/** @Entity @Table(name="ddc2231_y") */
class DDC2231EntityY implements ObjectManagerAware
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /** @var ObjectManager */
    public $om;

    public function injectObjectManager(ObjectManager $objectManager, ClassMetadata $classMetadata): void
    {
        $this->om = $objectManager;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function doSomething(): void
    {
    }
}
