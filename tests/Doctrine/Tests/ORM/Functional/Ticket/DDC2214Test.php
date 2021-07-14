<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;
use function end;

/**
 * Verifies that the type of parameters being bound to an SQL query is the same
 * of the identifier of the entities used as parameters in the DQL query, even
 * if the bound objects are proxies.
 *
 * @group DDC-2214
 */
class DDC2214Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2214Foo::class),
                $this->_em->getClassMetadata(DDC2214Bar::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $foo = new DDC2214Foo();
        $bar = new DDC2214Bar();

        $foo->bar = $bar;

        $this->_em->persist($foo);
        $this->_em->persist($bar);
        $this->_em->flush();
        $this->_em->clear();

        $foo = $this->_em->find(DDC2214Foo::class, $foo->id);
        assert($foo instanceof DDC2214Foo);
        $bar = $foo->bar;

        $logger = $this->_em->getConnection()->getConfiguration()->getSQLLogger();

        $related = $this
            ->_em
            ->createQuery('SELECT b FROM ' . __NAMESPACE__ . '\DDC2214Bar b WHERE b.id IN(:ids)')
            ->setParameter('ids', [$bar])
            ->getResult();

        $query = end($logger->queries);

        $this->assertEquals(Connection::PARAM_INT_ARRAY, $query['types'][0]);
    }
}

/** @Entity */
class DDC2214Foo
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var DDC2214Bar
     * @ManyToOne(targetEntity="DDC2214Bar")
     */
    public $bar;
}

/** @Entity */
class DDC2214Bar
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}
