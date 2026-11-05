<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An entity whose identifier is an association to an entity that also has an
 * association as identifier ("identity through foreign entities", two or more levels).
 */
#[Group('GH10514')]
#[Group('GH12417')]
class GH10514Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            GH10514A::class,
            GH10514B::class,
            GH10514C::class,
            GH10514Root::class,
            GH10514Level1::class,
            GH10514Level2::class,
            GH10514Item::class,
            GH10514Parent::class,
            GH10514Child::class,
            GH10514Owner::class,
            GH10514Versioned::class,
            GH10514VersionedChild::class,
        );
    }

    protected function tearDown(): void
    {
        $conn = static::$sharedConn;
        $conn->executeStatement('DELETE FROM GH10514VersionedChild');
        $conn->executeStatement('DELETE FROM GH10514Versioned');
        $conn->executeStatement('DELETE FROM GH10514Owner');
        $conn->executeStatement('DELETE FROM GH10514Child');
        $conn->executeStatement('DELETE FROM GH10514Parent');
        $conn->executeStatement('DELETE FROM GH10514Item');
        $conn->executeStatement('DELETE FROM GH10514Level2');
        $conn->executeStatement('DELETE FROM GH10514Level1');
        $conn->executeStatement('DELETE FROM GH10514Root');
        $conn->executeStatement('UPDATE GH10514B SET c_id = NULL');
        $conn->executeStatement('DELETE FROM GH10514C');
        $conn->executeStatement('DELETE FROM GH10514B');
        $conn->executeStatement('DELETE FROM GH10514A');

        parent::tearDown();
    }

    public function testFindEntityWhoseToOneTargetHasADerivedIdentityTwoLevelsDeep(): void
    {
        $conn = $this->_em->getConnection();
        $conn->insert('GH10514A', ['id' => 1]);
        $conn->insert('GH10514B', ['id' => 1, 'c_id' => null]);
        $conn->insert('GH10514C', ['id' => 1]);
        $conn->update('GH10514B', ['c_id' => 1], ['id' => 1]);

        $b = $this->_em->find(GH10514B::class, 1);

        self::assertInstanceOf(GH10514B::class, $b);
        self::assertInstanceOf(GH10514C::class, $b->c);
        self::assertSame($b, $b->c->b);
    }

    public function testPersistAndLazyLoadAChainOfDerivedIdentities(): void
    {
        $root   = new GH10514Root();
        $level1 = new GH10514Level1($root);
        $level2 = new GH10514Level2($level1, 'level two');

        $this->_em->persist($root);
        $this->_em->persist($level1);
        $this->_em->persist($level2);
        $this->_em->persist(new GH10514Item($level2));
        $this->_em->flush();
        $this->_em->clear();

        $items = $this->_em->getRepository(GH10514Item::class)->findAll();

        self::assertCount(1, $items);
        self::assertSame('level two', $items[0]->level2->name);
    }

    public function testLoadingThroughEveryPathGivesTheSameInstance(): void
    {
        $this->insertChain();

        $item   = $this->_em->find(GH10514Item::class, 1);
        $level2 = $this->_em->find(GH10514Level2::class, 1);
        $level1 = $this->_em->find(GH10514Level1::class, 1);

        self::assertSame($level2, $item->level2);
        self::assertSame($level2, $this->_em->getReference(GH10514Level2::class, 1));
        self::assertSame($level2, $this->_em->find(GH10514Level2::class, $level1));
        self::assertSame($level1, $level2->level1);
        self::assertSame($level1, $this->_em->getReference(GH10514Level1::class, $this->_em->getReference(GH10514Root::class, 1)));
        self::assertSame('level two', $level2->name);
    }

    public function testFlushAfterLazyLoadingUpdatesTheExistingRows(): void
    {
        $this->insertChain();

        $item               = $this->_em->find(GH10514Item::class, 1);
        $item->level2->name = 'changed';
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame('changed', $this->_em->find(GH10514Level2::class, 1)->name);
        self::assertSame(1, (int) $this->_em->getConnection()->fetchOne('SELECT COUNT(*) FROM GH10514Level2'));
    }

    public function testGetReferenceWithTheIdentifierValueOfAnUnloadedEntity(): void
    {
        $this->insertChain();

        $level2 = $this->_em->getReference(GH10514Level2::class, 1);
        $level1 = $this->_em->getReference(GH10514Level1::class, 1);

        self::assertSame($level1, $level2->level1);
        self::assertSame(1, $level1->root->id);
        self::assertSame('level two', $level2->name);
    }

    public function testFlattenIdentifierWhoseAssociatedEntityIsManaged(): void
    {
        $this->insertChain();

        $level1    = $this->_em->find(GH10514Level1::class, 1);
        $flattener = new IdentifierFlattener($this->_em->getUnitOfWork(), $this->_em->getMetadataFactory());

        self::assertSame(
            ['level1' => '1'],
            $flattener->flattenIdentifier($this->_em->getClassMetadata(GH10514Level2::class), ['level1' => $level1]),
        );
    }

    public function testGetReferenceDoesNotReadAFieldAsTheJoinColumnOfTheSameName(): void
    {
        // The identifier field "id" is stored in "local_id", and the join column of "parent" is named "id"
        $parent7  = new GH10514Parent(7);
        $parent42 = new GH10514Parent(42);
        $child7   = new GH10514Child(7, $parent7);
        $child42  = new GH10514Child(7, $parent42);
        $this->_em->persist($parent7);
        $this->_em->persist($parent42);
        $this->_em->persist($child7);
        $this->_em->persist($child42);
        $this->_em->flush();

        self::assertSame($child42, $this->_em->getReference(GH10514Child::class, ['id' => 7, 'parent' => 42]));
    }

    public function testUpdateOfAVersionedJoinedEntityWithADerivedIdentity(): void
    {
        $owner = new GH10514Owner(1);
        $child = new GH10514VersionedChild($owner, 'a');
        $this->_em->persist($owner);
        $this->_em->persist($child);
        $this->_em->flush();

        $child->name = 'b';
        $this->_em->flush();

        self::assertSame(2, $child->version);
    }

    private function insertChain(): void
    {
        $conn = $this->_em->getConnection();
        $conn->insert('GH10514Root', ['id' => 1]);
        $conn->insert('GH10514Level1', ['level1_id' => 1]);
        $conn->insert('GH10514Level2', ['level2_id' => 1, 'name' => 'level two']);
        $conn->insert('GH10514Item', ['id' => 1, 'level2_id' => 1]);
    }
}

#[ORM\Entity]
class GH10514A
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;
}

#[ORM\Entity]
class GH10514B
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: GH10514A::class)]
    #[ORM\JoinColumn(name: 'id', referencedColumnName: 'id')]
    public GH10514A $id;

    #[ORM\OneToOne(targetEntity: GH10514C::class)]
    #[ORM\JoinColumn(name: 'c_id', referencedColumnName: 'id', nullable: true)]
    public GH10514C|null $c = null;
}

#[ORM\Entity]
class GH10514C
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: GH10514B::class)]
    #[ORM\JoinColumn(name: 'id', referencedColumnName: 'id')]
    public GH10514B $b;
}

#[ORM\Entity]
class GH10514Root
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;
}

#[ORM\Entity]
class GH10514Level1
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: GH10514Root::class)]
        #[ORM\JoinColumn(name: 'level1_id', referencedColumnName: 'id')]
        public GH10514Root $root,
    ) {
    }
}

#[ORM\Entity]
class GH10514Level2
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: GH10514Level1::class)]
        #[ORM\JoinColumn(name: 'level2_id', referencedColumnName: 'level1_id')]
        public GH10514Level1 $level1,
        #[ORM\Column]
        public string $name,
    ) {
    }
}

#[ORM\Entity]
class GH10514Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GH10514Level2::class)]
        #[ORM\JoinColumn(name: 'level2_id', referencedColumnName: 'level2_id')]
        public GH10514Level2 $level2,
    ) {
    }
}

#[ORM\Entity]
class GH10514Parent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
    ) {
    }
}

#[ORM\Entity]
class GH10514Child
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'local_id')]
        public int $id,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: GH10514Parent::class)]
        #[ORM\JoinColumn(name: 'id', referencedColumnName: 'id')]
        public GH10514Parent $parent,
    ) {
    }
}

#[ORM\Entity]
class GH10514Owner
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
    ) {
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap(['child' => GH10514VersionedChild::class])]
abstract class GH10514Versioned
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    public int $version;

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: GH10514Owner::class)]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
        public GH10514Owner $owner,
    ) {
    }
}

#[ORM\Entity]
class GH10514VersionedChild extends GH10514Versioned
{
    public function __construct(
        GH10514Owner $owner,
        #[ORM\Column]
        public string $name,
    ) {
        parent::__construct($owner);
    }
}
