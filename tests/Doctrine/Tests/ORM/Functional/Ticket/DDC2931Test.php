<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-2931
 */
class DDC2931Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC2931User::class),
                ]
            );
        } catch (Exception $e) {
            // no action needed - schema seems to be already in place
        }
    }

    public function testIssue(): void
    {
        $first  = new DDC2931User();
        $second = new DDC2931User();
        $third  = new DDC2931User();

        $second->parent = $first;
        $third->parent  = $second;

        $this->_em->persist($first);
        $this->_em->persist($second);
        $this->_em->persist($third);

        $this->_em->flush();
        $this->_em->clear();

        $second = $this->_em->find(DDC2931User::class, $second->id);

        $this->assertSame(2, $second->getRank());
    }

    public function testFetchJoinedEntitiesCanBeRefreshed(): void
    {
        $first  = new DDC2931User();
        $second = new DDC2931User();
        $third  = new DDC2931User();

        $second->parent = $first;
        $third->parent  = $second;

        $first->value  = 1;
        $second->value = 2;
        $third->value  = 3;

        $this->_em->persist($first);
        $this->_em->persist($second);
        $this->_em->persist($third);

        $this->_em->flush();

        $first->value  = 4;
        $second->value = 5;
        $third->value  = 6;

        $refreshedSecond = $this
            ->_em
            ->createQuery(
                'SELECT e, p, c FROM '
                . __NAMESPACE__ . '\\DDC2931User e LEFT JOIN e.parent p LEFT JOIN e.child c WHERE e = :id'
            )
            ->setParameter('id', $second)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $this->assertCount(1, $refreshedSecond);
        $this->assertSame(1, $first->value);
        $this->assertSame(2, $second->value);
        $this->assertSame(3, $third->value);
    }
}


/** @Entity */
class DDC2931User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var DDC2931User
     * @OneToOne(targetEntity="DDC2931User", inversedBy="child")
     */
    public $parent;

    /**
     * @var DDC2931User
     * @OneToOne(targetEntity="DDC2931User", mappedBy="parent")
     */
    public $child;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $value = 0;

    /**
     * Return Rank recursively
     * My rank is 1 + rank of my parent
     */
    public function getRank(): int
    {
        return 1 + ($this->parent ? $this->parent->getRank() : 0);
    }

    public function __wakeup(): void
    {
    }
}
