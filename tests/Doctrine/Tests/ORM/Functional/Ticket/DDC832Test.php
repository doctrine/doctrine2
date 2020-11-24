<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

class DDC832Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if ($platform->getName() === 'oracle') {
            $this->markTestSkipped('Doesnt run on Oracle.');
        }

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC832JoinedIndex::class),
                    $this->_em->getClassMetadata(DDC832JoinedTreeIndex::class),
                    $this->_em->getClassMetadata(DDC832Like::class),
                ]
            );
        } catch(\Exception $e) {
        }
    }

    public function tearDown() : void
    {
        /* @var $sm \Doctrine\DBAL\Schema\AbstractSchemaManager */
        $platform = $this->_em->getConnection()->getDatabasePlatform();

        $sm = $this->_em->getConnection()->getSchemaManager();
        $sm->dropTable($platform->quoteIdentifier('TREE_INDEX'));
        $sm->dropTable($platform->quoteIdentifier('INDEX'));
        $sm->dropTable($platform->quoteIdentifier('LIKE'));
    }

    /**
     * @group DDC-832
     */
    public function testQuotedTableBasicUpdate()
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
    public function testQuotedTableBasicRemove()
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
    public function testQuotedTableJoinedUpdate()
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
    public function testQuotedTableJoinedRemove()
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
    public function testQuotedTableJoinedChildUpdate()
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
    public function testQuotedTableJoinedChildRemove()
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
     * @Id @Column(type="integer") @GeneratedValue
     */
    public $id;

    /** @Column(type="string") */
    public $word;

    /**
     * @version
     * @Column(type="integer")
     */
    public $version;

    public function __construct($word)
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
     * @Id @Column(type="integer") @GeneratedValue
     */
    public $id;

    /** @Column(type="string") */
    public $name;

    /**
     * @version
     * @Column(type="integer")
     */
    public $version;

    public function __construct($name)
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
    /** @Column(type="integer") */
    public $lft;

    /** @Column(type="integer") */
    public $rgt;

    public function __construct($name, $lft, $rgt)
    {
        $this->name = $name;
        $this->lft = $lft;
        $this->rgt = $rgt;
    }
}
