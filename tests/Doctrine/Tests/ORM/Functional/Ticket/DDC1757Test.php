<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

class DDC1757Test extends OrmFunctionalTestCase
{
    public function testFailingCase(): void
    {
        $qb = $this->_em->createQueryBuilder();
        assert($qb instanceof QueryBuilder);

        $qb->select('_a')
            ->from(DDC1757A::class, '_a')
            ->from(DDC1757B::class, '_b')
            ->join('_b.c', '_c')
            ->join('_c.d', '_d');

        $q   = $qb->getQuery();
        $dql = $q->getDQL();

        // Show difference between expected and actual queries on error
        self::assertEquals(
            'SELECT _a FROM ' . __NAMESPACE__ . '\DDC1757A _a, ' . __NAMESPACE__ . '\DDC1757B _b INNER JOIN _b.c _c INNER JOIN _c.d _d',
            $dql,
            'Wrong DQL query'
        );
    }
}

/**
 * @Entity
 */
class DDC1757A
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;
}

/**
 * @Entity
 */
class DDC1757B
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var DDC1757C
     * @OneToOne(targetEntity="DDC1757C")
     */
    private $c;
}

/**
 * @Entity
 */
class DDC1757C
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var DDC1757D
     * @OneToOne(targetEntity="DDC1757D")
     */
    private $d;
}

/**
 * @Entity
 */
class DDC1757D
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}
