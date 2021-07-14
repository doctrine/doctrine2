<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-2346
 */
class DDC2346Test extends OrmFunctionalTestCase
{
    /** @var DebugStack */
    protected $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2346Foo::class),
                $this->_em->getClassMetadata(DDC2346Bar::class),
                $this->_em->getClassMetadata(DDC2346Baz::class),
            ]
        );

        $this->logger = new DebugStack();
    }

    /**
     * Verifies that fetching a OneToMany association with fetch="EAGER" does not cause N+1 queries
     */
    public function testIssue(): void
    {
        $foo1 = new DDC2346Foo();
        $foo2 = new DDC2346Foo();

        $baz1 = new DDC2346Baz();
        $baz2 = new DDC2346Baz();

        $baz1->foo = $foo1;
        $baz2->foo = $foo2;

        $foo1->bars[] = $baz1;
        $foo1->bars[] = $baz2;

        $this->_em->persist($foo1);
        $this->_em->persist($foo2);
        $this->_em->persist($baz1);
        $this->_em->persist($baz2);

        $this->_em->flush();
        $this->_em->clear();

        $this->_em->getConnection()->getConfiguration()->setSQLLogger($this->logger);

        $fetchedBazs = $this->_em->getRepository(DDC2346Baz::class)->findAll();

        $this->assertCount(2, $fetchedBazs);
        $this->assertCount(2, $this->logger->queries, 'The total number of executed queries is 2, and not n+1');
    }
}

/** @Entity */
class DDC2346Foo
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC2346Bar[]|Collection
     * @OneToMany(targetEntity="DDC2346Bar", mappedBy="foo")
     */
    public $bars;

    /** Constructor */
    public function __construct()
    {
        $this->bars = new ArrayCollection();
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"bar" = "DDC2346Bar", "baz" = "DDC2346Baz"})
 */
class DDC2346Bar
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC2346Foo
     * @ManyToOne(targetEntity="DDC2346Foo", inversedBy="bars", fetch="EAGER")
     */
    public $foo;
}


/**
 * @Entity
 */
class DDC2346Baz extends DDC2346Bar
{
}
