<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Persistence\PersistentObject;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class PersistentCollectionTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(PersistentCollectionHolder::class),
                    $this->_em->getClassMetadata(PersistentCollectionContent::class),
                ]
            );
        } catch (Exception $e) {
        }

        PersistentObject::setObjectManager($this->_em);
    }

    public function testPersist(): void
    {
        $collectionHolder = new PersistentCollectionHolder();
        $content          = new PersistentCollectionContent('first element');
        $collectionHolder->addElement($content);

        $this->_em->persist($collectionHolder);
        $this->_em->flush();
        $this->_em->clear();

        $collectionHolder = $this->_em->find(PersistentCollectionHolder::class, $collectionHolder->getId());
        $collectionHolder->getCollection();

        $content = new PersistentCollectionContent('second element');
        $collectionHolder->addElement($content);

        $this->assertEquals(2, $collectionHolder->getCollection()->count());
    }

    /**
     * Tests that PersistentCollection::isEmpty() does not initialize the collection when FETCH_EXTRA_LAZY is used.
     */
    public function testExtraLazyIsEmptyDoesNotInitializeCollection(): void
    {
        $collectionHolder = new PersistentCollectionHolder();

        $this->_em->persist($collectionHolder);
        $this->_em->flush();
        $this->_em->clear();

        $collectionHolder = $this->_em->find(PersistentCollectionHolder::class, $collectionHolder->getId());
        $collection       = $collectionHolder->getRawCollection();

        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->isInitialized());

        $collectionHolder->addElement(new PersistentCollectionContent());

        $this->_em->flush();
        $this->_em->clear();

        $collectionHolder = $this->_em->find(PersistentCollectionHolder::class, $collectionHolder->getId());
        $collection       = $collectionHolder->getRawCollection();

        $this->assertFalse($collection->isEmpty());
        $this->assertFalse($collection->isInitialized());
    }

    /**
     * @group #1206
     * @group DDC-3430
     */
    public function testMatchingDoesNotModifyTheGivenCriteria(): void
    {
        $collectionHolder = new PersistentCollectionHolder();

        $this->_em->persist($collectionHolder);
        $this->_em->flush();
        $this->_em->clear();

        $criteria = new Criteria();

        $collectionHolder = $this->_em->find(PersistentCollectionHolder::class, $collectionHolder->getId());
        $collectionHolder->getCollection()->matching($criteria);

        $this->assertEmpty($criteria->getWhereExpression());
        $this->assertEmpty($criteria->getFirstResult());
        $this->assertEmpty($criteria->getMaxResults());
        $this->assertEmpty($criteria->getOrderings());
    }
}

/**
 * @Entity
 */
class PersistentCollectionHolder extends PersistentObject
{
    /**
     * @Id @Column(type="integer") @GeneratedValue
     * @var int
     */
    protected $id;

    /**
     * @var Collection
     * @ManyToMany(targetEntity="PersistentCollectionContent", cascade={"all"}, fetch="EXTRA_LAZY")
     */
    protected $collection;

    public function __construct()
    {
        $this->collection = new ArrayCollection();
    }

    public function addElement(PersistentCollectionContent $element): void
    {
        $this->collection->add($element);
    }

    /**
     * @return Collection
     */
    public function getCollection(): Collection
    {
        return clone $this->collection;
    }

    /**
     * @return Collection
     */
    public function getRawCollection(): Collection
    {
        return $this->collection;
    }
}

/**
 * @Entity
 */
class PersistentCollectionContent extends PersistentObject
{
    /**
     * @Id @Column(type="integer") @GeneratedValue
     * @var int
     */
    protected $id;
}
