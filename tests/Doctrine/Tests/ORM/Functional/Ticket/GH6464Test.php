<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group GH-6464
 */
class GH6464Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(GH6464Post::class),
            $this->_em->getClassMetadata(GH6464User::class),
            $this->_em->getClassMetadata(GH6464Author::class),
        ]);
    }

    /**
     * Verifies that SqlWalker generates valid SQL for an INNER JOIN to CTI table
     *
     * SqlWalker needs to generate nested INNER JOIN statements, otherwise there would be INNER JOIN
     * statements without an ON clause, which are valid on e.g. MySQL but rejected by PostgreSQL.
     */
    public function testIssue(): void
    {
        $query = $this->_em->createQueryBuilder()
            ->select('p')
            ->from(GH6464Post::class, 'p')
            ->innerJoin(GH6464Author::class, 'a', 'WITH', 'p.authorId = a.id')
            ->getQuery();

        $this->assertDoesNotMatchRegularExpression(
            '/INNER JOIN \w+ \w+ INNER JOIN/',
            $query->getSQL(),
            'As of GH-6464, every INNER JOIN should have an ON clause, which is missing here'
        );

        // Query shouldn't yield a result, yet it shouldn't crash (anymore)
        $this->assertEquals([], $query->getResult());
    }
}

/** @Entity */
class GH6464Post
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $authorId;
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"author" = "GH6464Author"})
 */
abstract class GH6464User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/** @Entity */
class GH6464Author extends GH6464User
{
}
