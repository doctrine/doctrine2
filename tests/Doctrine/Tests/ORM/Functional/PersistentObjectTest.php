<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Persistence\PersistentObject;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * Test that Doctrine ORM correctly works with the ObjectManagerAware and PersistentObject
 * classes from Common.
 *
 * @group DDC-1448
 */
class PersistentObjectTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(PersistentEntity::class),
                ]
            );
        } catch (Exception $e) {
        }

        PersistentObject::setObjectManager($this->_em);
    }

    public function testPersist(): void
    {
        $entity = new PersistentEntity();
        $entity->setName('test');

        $this->_em->persist($entity);
        $this->_em->flush();

        $this->addToAssertionCount(1);
    }

    public function testFind(): void
    {
        $entity = new PersistentEntity();
        $entity->setName('test');

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $entity = $this->_em->find(PersistentEntity::class, $entity->getId());

        $this->assertEquals('test', $entity->getName());
        $entity->setName('foobar');

        $this->_em->flush();
    }

    public function testGetReference(): void
    {
        $entity = new PersistentEntity();
        $entity->setName('test');

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $entity = $this->_em->getReference(PersistentEntity::class, $entity->getId());

        $this->assertEquals('test', $entity->getName());
    }

    public function testSetAssociation(): void
    {
        $entity = new PersistentEntity();
        $entity->setName('test');
        $entity->setParent($entity);

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $entity = $this->_em->getReference(PersistentEntity::class, $entity->getId());
        $this->assertSame($entity, $entity->getParent());
    }
}

/**
 * @Entity
 */
class PersistentEntity extends PersistentObject
{
    /**
     * @Id @Column(type="integer") @GeneratedValue
     * @var int
     */
    protected $id;

    /**
     * @Column(type="string")
     * @var string
     */
    protected $name;

    /**
     * @ManyToOne(targetEntity="PersistentEntity")
     * @var PersistentEntity
     */
    protected $parent;
}
