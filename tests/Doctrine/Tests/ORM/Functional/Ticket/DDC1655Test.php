<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\PostLoad;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function get_class;

/**
 * @group DDC-1655
 * @group DDC-1640
 * @group DDC-1556
 */
class DDC1655Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1655Foo::class),
                    $this->_em->getClassMetadata(DDC1655Bar::class),
                    $this->_em->getClassMetadata(DDC1655Baz::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testPostLoadOneToManyInheritance(): void
    {
        $cm = $this->_em->getClassMetadata(DDC1655Foo::class);
        $this->assertEquals(['postLoad' => ['postLoad']], $cm->lifecycleCallbacks);

        $cm = $this->_em->getClassMetadata(DDC1655Bar::class);
        $this->assertEquals(['postLoad' => ['postLoad', 'postSubLoaded']], $cm->lifecycleCallbacks);

        $baz      = new DDC1655Baz();
        $foo      = new DDC1655Foo();
        $foo->baz = $baz;
        $bar      = new DDC1655Bar();
        $bar->baz = $baz;

        $this->_em->persist($foo);
        $this->_em->persist($bar);
        $this->_em->persist($baz);
        $this->_em->flush();
        $this->_em->clear();

        $baz = $this->_em->find(get_class($baz), $baz->id);
        foreach ($baz->foos as $foo) {
            $this->assertEquals(1, $foo->loaded, 'should have loaded callback counter incremented for ' . get_class($foo));
        }
    }

    /**
     * Check that post load is not executed several times when the entity
     * is rehydrated again although its already known.
     */
    public function testPostLoadInheritanceChild(): void
    {
        $bar = new DDC1655Bar();

        $this->_em->persist($bar);
        $this->_em->flush();
        $this->_em->clear();

        $bar = $this->_em->find(get_class($bar), $bar->id);
        $this->assertEquals(1, $bar->loaded);
        $this->assertEquals(1, $bar->subLoaded);

        $bar = $this->_em->find(get_class($bar), $bar->id);
        $this->assertEquals(1, $bar->loaded);
        $this->assertEquals(1, $bar->subLoaded);

        $dql = 'SELECT b FROM ' . __NAMESPACE__ . '\DDC1655Bar b WHERE b.id = ?1';
        $bar = $this->_em->createQuery($dql)->setParameter(1, $bar->id)->getSingleResult();

        $this->assertEquals(1, $bar->loaded);
        $this->assertEquals(1, $bar->subLoaded);

        $this->_em->refresh($bar);

        $this->assertEquals(2, $bar->loaded);
        $this->assertEquals(2, $bar->subLoaded);
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({
 *    "foo" = "DDC1655Foo",
 *    "bar" = "DDC1655Bar"
 * })
 * @HasLifecycleCallbacks
 */
class DDC1655Foo
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /** @var int */
    public $loaded = 0;

    /**
     * @var DDC1655Baz
     * @ManyToOne(targetEntity="DDC1655Baz", inversedBy="foos")
     */
    public $baz;

    /**
     * @PostLoad
     */
    public function postLoad(): void
    {
        $this->loaded++;
    }
}

/**
 * @Entity
 * @HasLifecycleCallbacks
 */
class DDC1655Bar extends DDC1655Foo
{
    /** @var int */
    public $subLoaded;

    /**
     * @PostLoad
     */
    public function postSubLoaded(): void
    {
        $this->subLoaded++;
    }
}

/**
 * @Entity
 */
class DDC1655Baz
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @psalm-var Collection<int, DDC1655Foo>
     * @OneToMany(targetEntity="DDC1655Foo", mappedBy="baz")
     */
    public $foos = [];
}
