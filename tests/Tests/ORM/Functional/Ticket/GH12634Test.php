<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function sort;

use const SORT_STRING;

/**
 * Bulk DQL UPDATE and DELETE statements on entities of a class table inheritance hierarchy keep the
 * identifiers of the affected rows in a temporary table. The columns of that table have to be declared
 * like the mapped identifier columns of the root table:
 *
 * - DBAL 4 refuses to declare a VARCHAR column without a length on MySQL, MariaDB and SQL Server.
 * - MySQL and MariaDB refuse to compare string columns of different collations, and the primary key of a
 *   case insensitive temporary table cannot hold identifiers that differ by case only, which the tables
 *   of the hierarchy can when they are case sensitive.
 */
#[Group('GH-12634')]
class GH12634Test extends OrmFunctionalTestCase
{
    private const ID_1 = '11111111-1111-4111-8111-111111111111';
    private const ID_2 = '22222222-2222-4222-8222-222222222222';
    private const ID_3 = '33333333-3333-4333-8333-333333333333';
    private const ID_4 = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH12634FixedStringIdRoot::class,
            GH12634FixedStringIdChild::class,
            GH12634StringIdRoot::class,
            GH12634StringIdChild::class,
            GH12634CaseSensitiveIdRoot::class,
            GH12634CaseSensitiveIdChild::class,
            GH12634CollectionOwner::class,
            GH12634CollectionRoot::class,
            GH12634CollectionChild::class,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = $this->_em->getConnection();

        // The rows of the child tables are removed by the ON DELETE CASCADE of their foreign keys.
        $connection->executeStatement('DELETE FROM gh12634_fixed_root');
        $connection->executeStatement('DELETE FROM gh12634_plain_root');
        $connection->executeStatement('DELETE FROM gh12634_bin_root');
        $connection->executeStatement('DELETE FROM gh12634_collection_root');
        $connection->executeStatement('DELETE FROM gh12634_collection_owner');

        parent::tearDown();
    }

    public function testBulkUpdateWithFixedLengthStringIdentifier(): void
    {
        $this->persistAndClear(
            new GH12634FixedStringIdRoot(self::ID_1),
            new GH12634FixedStringIdChild(self::ID_2, 'match'),
            new GH12634FixedStringIdChild(self::ID_3, 'match'),
            new GH12634FixedStringIdChild(self::ID_4, 'other'),
        );

        $updated = $this->_em
            ->createQuery('UPDATE ' . GH12634FixedStringIdChild::class . ' c SET c.status = 3 WHERE c.label = :label')
            ->setParameter('label', 'match')
            ->execute();

        self::assertSame(2, $updated);
        self::assertSame(2, $this->countRows(GH12634FixedStringIdRoot::class, 'r.status = 3'));
        self::assertSame(4, $this->countRows(GH12634FixedStringIdRoot::class));
    }

    public function testBulkDeleteWithFixedLengthStringIdentifier(): void
    {
        $this->persistAndClear(
            new GH12634FixedStringIdRoot(self::ID_1),
            new GH12634FixedStringIdChild(self::ID_2, 'match'),
            new GH12634FixedStringIdChild(self::ID_3, 'match'),
            new GH12634FixedStringIdChild(self::ID_4, 'other'),
        );

        $deleted = $this->_em
            ->createQuery('DELETE ' . GH12634FixedStringIdChild::class . ' c WHERE c.label = :label')
            ->setParameter('label', 'match')
            ->execute();

        self::assertSame(2, $deleted);
        self::assertSame(2, $this->countRows(GH12634FixedStringIdRoot::class));
        self::assertSame(1, $this->countRows(GH12634FixedStringIdChild::class));
    }

    public function testBulkUpdateWithStringIdentifierWithoutLength(): void
    {
        $this->persistAndClear(
            new GH12634StringIdRoot('root'),
            new GH12634StringIdChild('first', 'match'),
            new GH12634StringIdChild('second', 'match'),
            new GH12634StringIdChild('third', 'other'),
        );

        $updated = $this->_em
            ->createQuery('UPDATE ' . GH12634StringIdChild::class . ' c SET c.status = 3 WHERE c.label = :label')
            ->setParameter('label', 'match')
            ->execute();

        self::assertSame(2, $updated);
        self::assertSame(2, $this->countRows(GH12634StringIdRoot::class, 'r.status = 3'));
        self::assertSame(4, $this->countRows(GH12634StringIdRoot::class));
    }

    public function testBulkDeleteWithStringIdentifierWithoutLength(): void
    {
        $this->persistAndClear(
            new GH12634StringIdRoot('root'),
            new GH12634StringIdChild('first', 'match'),
            new GH12634StringIdChild('second', 'match'),
            new GH12634StringIdChild('third', 'other'),
        );

        $deleted = $this->_em
            ->createQuery('DELETE ' . GH12634StringIdChild::class . ' c WHERE c.label = :label')
            ->setParameter('label', 'match')
            ->execute();

        self::assertSame(2, $deleted);
        self::assertSame(2, $this->countRows(GH12634StringIdRoot::class));
        self::assertSame(1, $this->countRows(GH12634StringIdChild::class));
    }

    public function testBulkUpdateOfIdentifiersThatDifferByCaseOnly(): void
    {
        $this->persistCaseSensitiveEntities();

        $updated = $this->_em
            ->createQuery('UPDATE ' . GH12634CaseSensitiveIdChild::class . ' c SET c.status = 3 WHERE c.label = :label')
            ->setParameter('label', 'match')
            ->execute();

        self::assertSame(2, $updated);
        self::assertSame(['ABC', 'abc'], $this->findIds(GH12634CaseSensitiveIdRoot::class, 'r.status = 3'));
    }

    public function testBulkDeleteOfIdentifiersThatDifferByCaseOnly(): void
    {
        $this->persistCaseSensitiveEntities();

        $deleted = $this->_em
            ->createQuery('DELETE ' . GH12634CaseSensitiveIdChild::class . ' c WHERE c.label = :label')
            ->setParameter('label', 'match')
            ->execute();

        self::assertSame(2, $deleted);
        self::assertSame(['xyz'], $this->findIds(GH12634CaseSensitiveIdChild::class));
        self::assertSame(['xyz'], $this->findIds(GH12634CaseSensitiveIdRoot::class));
    }

    public function testDeletionOfReplacedOrphanRemovalCollectionWithFixedLengthStringIdentifier(): void
    {
        $first  = new GH12634CollectionOwner(1);
        $second = new GH12634CollectionOwner(2);

        $this->persistAndClear(
            $first,
            $second,
            new GH12634CollectionRoot(self::ID_1, $first),
            new GH12634CollectionChild(self::ID_2, $first, 'child'),
            new GH12634CollectionChild(self::ID_3, $second, 'child'),
        );

        // De-referencing the persistent collection deletes all of its entities, in the tables of their hierarchy.
        $owner        = $this->_em->find(GH12634CollectionOwner::class, 1);
        $owner->items = new ArrayCollection();
        $this->_em->flush();

        self::assertSame([self::ID_3], $this->findIds(GH12634CollectionRoot::class));
        self::assertSame([self::ID_3], $this->findIds(GH12634CollectionChild::class));
    }

    private function persistCaseSensitiveEntities(): void
    {
        $this->persistAndClear(
            new GH12634CaseSensitiveIdChild('abc', 'match'),
            new GH12634CaseSensitiveIdChild('ABC', 'match'),
            new GH12634CaseSensitiveIdChild('xyz', 'other'),
        );
    }

    private function persistAndClear(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->_em->persist($entity);
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    /** @param class-string $className */
    private function countRows(string $className, string|null $condition = null): int
    {
        $dql = 'SELECT COUNT(r.status) FROM ' . $className . ' r';

        if ($condition !== null) {
            $dql .= ' WHERE ' . $condition;
        }

        return (int) $this->_em->createQuery($dql)->getSingleScalarResult();
    }

    /**
     * @param class-string $className
     *
     * @return list<string> The identifiers, in binary order.
     */
    private function findIds(string $className, string|null $condition = null): array
    {
        $dql = 'SELECT r.id FROM ' . $className . ' r';

        if ($condition !== null) {
            $dql .= ' WHERE ' . $condition;
        }

        $ids = $this->_em->createQuery($dql)->getSingleColumnResult();
        sort($ids, SORT_STRING);

        return $ids;
    }
}

#[Entity]
#[Table(name: 'gh12634_fixed_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'string')]
#[DiscriminatorMap(['root' => GH12634FixedStringIdRoot::class, 'child' => GH12634FixedStringIdChild::class])]
class GH12634FixedStringIdRoot
{
    #[Column(type: 'integer')]
    public int $status = 1;

    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 36, options: ['fixed' => true])]
        public string $id,
    ) {
    }
}

#[Entity]
#[Table(name: 'gh12634_fixed_child')]
class GH12634FixedStringIdChild extends GH12634FixedStringIdRoot
{
    public function __construct(
        string $id,
        #[Column(type: 'string', length: 20)]
        public string $label,
    ) {
        parent::__construct($id);
    }
}

#[Entity]
#[Table(name: 'gh12634_plain_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'string')]
#[DiscriminatorMap(['root' => GH12634StringIdRoot::class, 'child' => GH12634StringIdChild::class])]
class GH12634StringIdRoot
{
    #[Column(type: 'integer')]
    public int $status = 1;

    public function __construct(
        #[Id]
        #[Column(type: 'string')]
        public string $id,
    ) {
    }
}

#[Entity]
#[Table(name: 'gh12634_plain_child')]
class GH12634StringIdChild extends GH12634StringIdRoot
{
    public function __construct(
        string $id,
        #[Column(type: 'string', length: 20)]
        public string $label,
    ) {
        parent::__construct($id);
    }
}

#[Entity]
#[Table(name: 'gh12634_bin_root', options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'])]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'string')]
#[DiscriminatorMap(['root' => GH12634CaseSensitiveIdRoot::class, 'child' => GH12634CaseSensitiveIdChild::class])]
class GH12634CaseSensitiveIdRoot
{
    #[Column(type: 'integer')]
    public int $status = 1;

    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 16)]
        public string $id,
    ) {
    }
}

#[Entity]
#[Table(name: 'gh12634_bin_child', options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'])]
class GH12634CaseSensitiveIdChild extends GH12634CaseSensitiveIdRoot
{
    public function __construct(
        string $id,
        #[Column(type: 'string', length: 20)]
        public string $label,
    ) {
        parent::__construct($id);
    }
}

#[Entity]
#[Table(name: 'gh12634_collection_owner')]
class GH12634CollectionOwner
{
    /** @var Collection<int, GH12634CollectionRoot> */
    #[OneToMany(targetEntity: GH12634CollectionRoot::class, mappedBy: 'owner', orphanRemoval: true)]
    public Collection $items;

    public function __construct(
        #[Id]
        #[Column(type: 'integer')]
        public int $id,
    ) {
        $this->items = new ArrayCollection();
    }
}

#[Entity]
#[Table(name: 'gh12634_collection_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'string')]
#[DiscriminatorMap(['root' => GH12634CollectionRoot::class, 'child' => GH12634CollectionChild::class])]
class GH12634CollectionRoot
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 36, options: ['fixed' => true])]
        public string $id,
        #[ManyToOne(targetEntity: GH12634CollectionOwner::class, inversedBy: 'items')]
        #[JoinColumn(nullable: false)]
        public GH12634CollectionOwner $owner,
    ) {
    }
}

#[Entity]
#[Table(name: 'gh12634_collection_child')]
class GH12634CollectionChild extends GH12634CollectionRoot
{
    public function __construct(
        string $id,
        GH12634CollectionOwner $owner,
        #[Column(type: 'string', length: 20)]
        public string $label,
    ) {
        parent::__construct($id, $owner);
    }
}
