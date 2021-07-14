<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function count;

class DDC729Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $schemaTool = new SchemaTool($this->_em);
            $schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC729A::class),
                    $this->_em->getClassMetadata(DDC729B::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testMergeManyToMany(): void
    {
        $a            = new DDC729A();
        $b            = new DDC729B();
        $a->related[] = $b;

        $this->_em->persist($a);
        $this->_em->persist($b);
        $this->_em->flush();
        $this->_em->clear();
        $aId = $a->id;

        $a     = new DDC729A();
        $a->id = $aId;

        $this->assertInstanceOf(ArrayCollection::class, $a->related);

        $a = $this->_em->merge($a);

        $this->assertInstanceOf(PersistentCollection::class, $a->related);

        $this->assertFalse($a->related->isInitialized(), 'Collection should not be marked initialized.');
        $this->assertFalse($a->related->isDirty(), 'Collection should not be marked as dirty.');

        $this->_em->flush();
        $this->_em->clear();

        $a = $this->_em->find(DDC729A::class, $aId);
        $this->assertEquals(1, count($a->related));
    }

    public function testUnidirectionalMergeManyToMany(): void
    {
        $a            = new DDC729A();
        $b1           = new DDC729B();
        $b2           = new DDC729B();
        $a->related[] = $b1;

        $this->_em->persist($a);
        $this->_em->persist($b1);
        $this->_em->persist($b2);
        $this->_em->flush();
        $this->_em->clear();
        $aId = $a->id;

        $a     = new DDC729A();
        $a->id = $aId;

        $a = $this->_em->merge($a);

        $a->related->set(0, $this->_em->merge($b1));

        $a->related->set(1, $this->_em->merge($b2));

        $this->_em->flush();
        $this->_em->clear();

        $a = $this->_em->find(DDC729A::class, $aId);
        $this->assertEquals(2, count($a->related));
    }

    public function testBidirectionalMergeManyToMany(): void
    {
        $a            = new DDC729A();
        $b1           = new DDC729B();
        $b2           = new DDC729B();
        $a->related[] = $b1;

        $this->_em->persist($a);
        $this->_em->persist($b1);
        $this->_em->persist($b2);
        $this->_em->flush();
        $this->_em->clear();
        $aId = $a->id;

        $a     = new DDC729A();
        $a->id = $aId;

        $a = $this->_em->merge($a);

        $a->related->set(0, $this->_em->merge($b1));
        $b1->related->set(0, $a);

        $a->related->set(1, $this->_em->merge($b2));
        $b2->related->set(0, $a);

        $this->_em->flush();
        $this->_em->clear();

        $a = $this->_em->find(DDC729A::class, $aId);
        $this->assertEquals(2, count($a->related));
    }

    public function testBidirectionalMultiMergeManyToMany(): void
    {
        $a            = new DDC729A();
        $b1           = new DDC729B();
        $b2           = new DDC729B();
        $a->related[] = $b1;

        $this->_em->persist($a);
        $this->_em->persist($b1);
        $this->_em->persist($b2);
        $this->_em->flush();
        $this->_em->clear();
        $aId = $a->id;

        $a     = new DDC729A();
        $a->id = $aId;

        $a = $this->_em->merge($a);

        $a->related->set(0, $this->_em->merge($b1));
        $b1->related->set(0, $this->_em->merge($a));

        $a->related->set(1, $this->_em->merge($b2));
        $b2->related->set(0, $this->_em->merge($a));

        $this->_em->flush();
        $this->_em->clear();

        $a = $this->_em->find(DDC729A::class, $aId);
        $this->assertEquals(2, count($a->related));
    }
}

/**
 * @Entity
 */
class DDC729A
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC729B>
     * @ManyToMany(targetEntity="DDC729B", inversedBy="related")
     */
    public $related;

    public function __construct()
    {
        $this->related = new ArrayCollection();
    }
}

/**
 * @Entity
 */
class DDC729B
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC729B>
     * @ManyToMany(targetEntity="DDC729B", mappedBy="related")
     */
    public $related;

    public function __construct()
    {
        $this->related = new ArrayCollection();
    }
}
