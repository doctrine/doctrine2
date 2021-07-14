<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Logging\EchoSQLLogger;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class DDC832Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if ($platform->getName() === 'oracle') {
            $this->markTestSkipped('Doesnt run on Oracle.');
        }

        $this->_em->getConfiguration()->setSQLLogger(new EchoSQLLogger());

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC832JoinedIndex::class),
                    $this->_em->getClassMetadata(DDC832JoinedTreeIndex::class),
                    $this->_em->getClassMetadata(DDC832Like::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function tearDown(): void
    {
        $platform = $this->_em->getConnection()->getDatabasePlatform();

        $sm = $this->_em->getConnection()->getSchemaManager();
        $sm->dropTable($platform->quoteIdentifier('TREE_INDEX'));
        $sm->dropTable($platform->quoteIdentifier('INDEX'));
        $sm->dropTable($platform->quoteIdentifier('LIKE'));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableBasicUpdate(): void
    {
        $like = new DDC832Like('test');
        $this->_em->persist($like);
        $this->_em->flush();

        $like->word = 'test2';
        $this->_em->flush();
        $this->_em->clear();

        self::assertEquals($like, $this->_em->find(DDC832Like::class, $like->id));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableBasicRemove(): void
    {
        $like = new DDC832Like('test');
        $this->_em->persist($like);
        $this->_em->flush();

        $idToBeRemoved = $like->id;

        $this->_em->remove($like);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(DDC832Like::class, $idToBeRemoved));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableJoinedUpdate(): void
    {
        $index = new DDC832JoinedIndex('test');
        $this->_em->persist($index);
        $this->_em->flush();

        $index->name = 'asdf';
        $this->_em->flush();
        $this->_em->clear();

        self::assertEquals($index, $this->_em->find(DDC832JoinedIndex::class, $index->id));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableJoinedRemove(): void
    {
        $index = new DDC832JoinedIndex('test');
        $this->_em->persist($index);
        $this->_em->flush();

        $idToBeRemoved = $index->id;

        $this->_em->remove($index);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(DDC832JoinedIndex::class, $idToBeRemoved));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableJoinedChildUpdate(): void
    {
        $index = new DDC832JoinedTreeIndex('test', 1, 2);
        $this->_em->persist($index);
        $this->_em->flush();

        $index->name = 'asdf';
        $this->_em->flush();
        $this->_em->clear();

        self::assertEquals($index, $this->_em->find(DDC832JoinedTreeIndex::class, $index->id));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableJoinedChildRemove(): void
    {
        $index = new DDC832JoinedTreeIndex('test', 1, 2);
        $this->_em->persist($index);
        $this->_em->flush();

        $idToBeRemoved = $index->id;

        $this->_em->remove($index);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(DDC832JoinedTreeIndex::class, $idToBeRemoved));
    }
}

/**
 * @Entity
 * @Table(name="`LIKE`")
 */
class DDC832Like
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $word;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    public $version;

    public function __construct(string $word)
    {
        $this->word = $word;
    }
}

/**
 * @Entity
 * @Table(name="`INDEX`")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"like" = "DDC832JoinedIndex", "fuzzy" = "DDC832JoinedTreeIndex"})
 */
class DDC832JoinedIndex
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    public $version;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

/**
 * @Entity
 * @Table(name="`TREE_INDEX`")
 */
class DDC832JoinedTreeIndex extends DDC832JoinedIndex
{
    /**
     * @var int
     * @Column(type="integer")
     */
    public $lft;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $rgt;

    public function __construct(string $name, int $lft, int $rgt)
    {
        $this->name = $name;
        $this->lft  = $lft;
        $this->rgt  = $rgt;
    }
}
