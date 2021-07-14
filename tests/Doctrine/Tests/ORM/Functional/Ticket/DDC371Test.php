<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

/**
 * @group DDC-371
 */
class DDC371Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC371Parent::class),
                $this->_em->getClassMetadata(DDC371Child::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $parent           = new DDC371Parent();
        $parent->data     = 'parent';
        $parent->children = new ArrayCollection();

        $child       = new DDC371Child();
        $child->data = 'child';

        $child->parent = $parent;
        $parent->children->add($child);

        $this->_em->persist($parent);
        $this->_em->persist($child);

        $this->_em->flush();
        $this->_em->clear();

        $children = $this->_em->createQuery('select c,p from ' . __NAMESPACE__ . '\DDC371Child c '
                . 'left join c.parent p where c.id = 1 and p.id = 1')
                ->setHint(Query::HINT_REFRESH, true)
                ->getResult();

        $this->assertEquals(1, count($children));
        $this->assertNotInstanceOf(Proxy::class, $children[0]->parent);
        $this->assertFalse($children[0]->parent->children->isInitialized());
        $this->assertEquals(0, $children[0]->parent->children->unwrap()->count());
    }
}

/** @Entity */
class DDC371Child
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;
    /**
     * @var string
     * @Column(type="string")
     */
    public $data;
    /**
     * @var DDC371Parent
     * @ManyToOne(targetEntity="DDC371Parent", inversedBy="children") @JoinColumn(name="parentId")
     */
    public $parent;
}

/** @Entity */
class DDC371Parent
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;
    /**
     * @var string
     * @Column(type="string")
     */
    public $data;

    /**
     * @psalm-var Collection<int, DDC371Child>
     * @OneToMany(targetEntity="DDC371Child", mappedBy="parent")
     */
    public $children;
}
