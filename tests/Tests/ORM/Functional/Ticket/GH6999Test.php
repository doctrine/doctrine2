<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function range;
use function sort;

use const SORT_STRING;

#[Group('GH-6999')]
class GH6999Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            GH6999User::class,
            GH6999Access::class,
            GH6999Order::class,
            GH6999OrderLine::class,
            GH6999Zoo::class,
            GH6999Animal::class,
            GH6999Cat::class,
            GH6999Dog::class,
            GH6999Library::class,
            GH6999Medium::class,
            GH6999Book::class,
            GH6999Article::class,
            GH6999Tag::class,
        );
    }

    protected function tearDown(): void
    {
        $conn = $this->_em->getConnection();

        $conn->executeStatement('DELETE FROM gh6999_article_tag');
        $conn->executeStatement('DELETE FROM GH6999Tag');
        $conn->executeStatement('DELETE FROM GH6999Book');
        $conn->executeStatement('DELETE FROM GH6999Article');
        $conn->executeStatement('DELETE FROM GH6999Medium');
        $conn->executeStatement('DELETE FROM GH6999Library');
        $conn->executeStatement('DELETE FROM GH6999Animal');
        $conn->executeStatement('DELETE FROM GH6999Zoo');
        $conn->executeStatement('DELETE FROM GH6999OrderLine');
        $conn->executeStatement('DELETE FROM gh6999_order');
        $conn->executeStatement('DELETE FROM GH6999Access');
        $conn->executeStatement('DELETE FROM GH6999User');

        parent::tearDown();
    }

    public function testReplacingCollectionWithSameElementsDoesNotRemoveThemAsOrphans(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $user           = $this->findUser($userId);
        $user->accesses = new ArrayCollection($user->accesses->toArray());
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['a', 'b'], $this->findAccessNames());
    }

    public function testReplacingCollectionWithSubsetRemovesOnlyLeftOutElements(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b', 'c');

        $user           = $this->findUser($userId);
        $user->accesses = $user->accesses->filter(static fn (GH6999Access $access): bool => $access->name !== 'b');
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['a', 'c'], $this->findAccessNames());
        self::assertCount(2, $this->findUser($userId)->accesses);
    }

    public function testReplacingCollectionWithKeptAndNewElementReusingUniqueValueOfRemovedElement(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $user = $this->findUser($userId);
        $kept = $user->accesses->filter(static fn (GH6999Access $access): bool => $access->name === 'a')->first();
        self::assertInstanceOf(GH6999Access::class, $kept);

        $user->accesses = new ArrayCollection([$kept, new GH6999Access($user, 'b')]);
        $this->_em->flush();
        $keptId = $kept->id;
        $this->_em->clear();

        self::assertSame(['a', 'b'], $this->findAccessNames());
        self::assertNotNull($this->_em->find(GH6999Access::class, $keptId));
    }

    public function testReplacingCollectionWithOnlyNewElementsRemovesAllPreviousElements(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $user           = $this->findUser($userId);
        $user->accesses = new ArrayCollection([new GH6999Access($user, 'a'), new GH6999Access($user, 'c')]);
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['a', 'c'], $this->findAccessNames());
    }

    public function testReplacingCollectionWithEmptyCollectionRemovesAllElements(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $user           = $this->findUser($userId);
        $user->accesses = new ArrayCollection();
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame([], $this->findAccessNames());
    }

    public function testReplacingUninitializedCollectionKeepsSeparatelyLoadedElement(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $kept = $this->_em->getRepository(GH6999Access::class)->findOneBy(['name' => 'b']);
        self::assertInstanceOf(GH6999Access::class, $kept);
        $user = $kept->user;
        self::assertFalse($user->accesses->isInitialized());

        $user->accesses = new ArrayCollection([$kept]);
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['b'], $this->findAccessNames());
        self::assertSame($userId, $this->findUser($userId)->id);
    }

    public function testReplacingCollectionCancelsScheduledOrphanRemovalOfKeptElement(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $user     = $this->findUser($userId);
        $elements = $user->accesses->toArray();
        $user->accesses->removeElement($elements[0]);
        $user->accesses = new ArrayCollection($elements);
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['a', 'b'], $this->findAccessNames());
    }

    public function testExplicitlyRemovedElementOfReplacingCollectionIsDeleted(): void
    {
        $userId = $this->createUserWithAccesses('a', 'b');

        $user     = $this->findUser($userId);
        $elements = $user->accesses->toArray();
        $this->_em->remove($elements[0]);
        $user->accesses = new ArrayCollection($elements);
        $this->_em->flush();
        $this->_em->clear();

        self::assertSame(['b'], $this->findAccessNames());
    }

    public function testReplacingCollectionKeepsMoreElementsThanFitIntoOneInList(): void
    {
        $names  = [];
        $userId = $this->createUserWithAccesses(...range(0, 1001));

        $user     = $this->findUser($userId);
        $elements = $user->accesses->toArray();
        self::assertCount(1002, $elements);

        foreach ($elements as $access) {
            if ($access->name !== '1001') {
                $names[] = $access->name;
            }
        }

        $user->accesses = $user->accesses->filter(static fn (GH6999Access $access): bool => $access->name !== '1001');
        $this->_em->flush();
        $this->_em->clear();

        sort($names, SORT_STRING);

        self::assertSame($names, $this->findAccessNames());
    }

    public function testReplacingCollectionOfElementsWithCompositeIdentifier(): void
    {
        $order = new GH6999Order();
        $order->lines->add(new GH6999OrderLine($order, 1));
        $order->lines->add(new GH6999OrderLine($order, 2));
        $order->lines->add(new GH6999OrderLine($order, 3));
        $this->_em->persist($order);
        $this->_em->flush();
        $orderId = $order->id;
        $this->_em->clear();

        $order        = $this->_em->find(GH6999Order::class, $orderId);
        $order->lines = $order->lines->filter(static fn (GH6999OrderLine $line): bool => $line->position !== 2);
        $order->lines->add(new GH6999OrderLine($order, 4));
        $this->_em->flush();
        $this->_em->clear();

        $positions = $this->_em->createQuery('SELECT l.position FROM ' . GH6999OrderLine::class . ' l ORDER BY l.position')
            ->getSingleColumnResult();

        self::assertSame([1, 3, 4], $positions);
    }

    public function testReplacingCollectionOfSingleTableInheritanceElements(): void
    {
        $zoo = new GH6999Zoo();
        $zoo->animals->add(new GH6999Cat($zoo, 'tom'));
        $zoo->animals->add(new GH6999Dog($zoo, 'rex'));
        $zoo->animals->add(new GH6999Cat($zoo, 'garfield'));
        $this->_em->persist($zoo);
        $this->_em->flush();
        $zooId = $zoo->id;
        $this->_em->clear();

        $zoo          = $this->_em->find(GH6999Zoo::class, $zooId);
        $zoo->animals = $zoo->animals->filter(static fn (GH6999Animal $animal): bool => $animal->name !== 'garfield');
        $this->_em->flush();
        $this->_em->clear();

        $names = $this->_em->createQuery('SELECT a.name FROM ' . GH6999Animal::class . ' a ORDER BY a.name')
            ->getSingleColumnResult();

        self::assertSame(['rex', 'tom'], $names);
    }

    public function testReplacingCollectionOfJoinedInheritanceElements(): void
    {
        $library = new GH6999Library();
        $library->media->add(new GH6999Book($library, 'one'));
        $library->media->add(new GH6999Book($library, 'two'));
        $library->media->add(new GH6999Article($library, 'three'));
        $this->_em->persist($library);
        $this->_em->flush();
        $libraryId = $library->id;
        $this->_em->clear();

        $library        = $this->_em->find(GH6999Library::class, $libraryId);
        $library->media = $library->media->filter(static fn (GH6999Medium $medium): bool => $medium->title !== 'two');
        $this->_em->flush();
        $this->_em->clear();

        $titles = $this->_em->createQuery('SELECT m.title FROM ' . GH6999Medium::class . ' m ORDER BY m.title')
            ->getSingleColumnResult();
        self::assertSame(['one', 'three'], $titles);

        $bookTitles = $this->_em->createQuery('SELECT b.title FROM ' . GH6999Book::class . ' b')
            ->getSingleColumnResult();
        self::assertSame(['one'], $bookTitles);
    }

    public function testReplacingManyToManyCollectionCancelsScheduledOrphanRemovalOfKeptElement(): void
    {
        $article = new GH6999Article(null, 'article');
        $article->tags->add(new GH6999Tag('a'));
        $article->tags->add(new GH6999Tag('b'));
        $this->_em->persist($article);
        $this->_em->flush();
        $articleId = $article->id;
        $this->_em->clear();

        $article  = $this->_em->find(GH6999Article::class, $articleId);
        $elements = $article->tags->toArray();
        $article->tags->removeElement($elements[0]);
        $article->tags = new ArrayCollection($elements);
        $this->_em->flush();
        $this->_em->clear();

        $article = $this->_em->find(GH6999Article::class, $articleId);
        self::assertCount(2, $article->tags);
    }

    private function createUserWithAccesses(string|int ...$names): int
    {
        $user = new GH6999User();

        foreach ($names as $name) {
            $user->accesses->add(new GH6999Access($user, (string) $name));
        }

        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($user->id);

        return $user->id;
    }

    private function findUser(int $id): GH6999User
    {
        $user = $this->_em->find(GH6999User::class, $id);
        self::assertInstanceOf(GH6999User::class, $user);

        return $user;
    }

    /** @return list<string> */
    private function findAccessNames(): array
    {
        return $this->_em->createQuery('SELECT a.name FROM ' . GH6999Access::class . ' a ORDER BY a.name')
            ->getSingleColumnResult();
    }
}

#[ORM\Entity]
class GH6999User
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    /** @var Collection<int, GH6999Access> */
    #[ORM\OneToMany(targetEntity: GH6999Access::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $accesses;

    public function __construct()
    {
        $this->accesses = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH6999Access
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GH6999User::class, inversedBy: 'accesses')]
        public GH6999User $user,
        #[ORM\Column(unique: true)]
        public string $name,
    ) {
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6999_order')]
class GH6999Order
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    /** @var Collection<int, GH6999OrderLine> */
    #[ORM\OneToMany(targetEntity: GH6999OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    public Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH6999OrderLine
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: GH6999Order::class, inversedBy: 'lines')]
        public GH6999Order $order,
        #[ORM\Id]
        #[ORM\Column]
        public int $position,
    ) {
    }
}

#[ORM\Entity]
class GH6999Zoo
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    /** @var Collection<int, GH6999Animal> */
    #[ORM\OneToMany(targetEntity: GH6999Animal::class, mappedBy: 'zoo', cascade: ['persist'], orphanRemoval: true)]
    public Collection $animals;

    public function __construct()
    {
        $this->animals = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap(['cat' => GH6999Cat::class, 'dog' => GH6999Dog::class])]
abstract class GH6999Animal
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GH6999Zoo::class, inversedBy: 'animals')]
        public GH6999Zoo $zoo,
        #[ORM\Column]
        public string $name,
    ) {
    }
}

#[ORM\Entity]
class GH6999Cat extends GH6999Animal
{
}

#[ORM\Entity]
class GH6999Dog extends GH6999Animal
{
}

#[ORM\Entity]
class GH6999Library
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    /** @var Collection<int, GH6999Medium> */
    #[ORM\OneToMany(targetEntity: GH6999Medium::class, mappedBy: 'library', cascade: ['persist'], orphanRemoval: true)]
    public Collection $media;

    public function __construct()
    {
        $this->media = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap(['book' => GH6999Book::class, 'article' => GH6999Article::class])]
abstract class GH6999Medium
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: GH6999Library::class, inversedBy: 'media')]
        public GH6999Library|null $library,
        #[ORM\Column]
        public string $title,
    ) {
    }
}

#[ORM\Entity]
class GH6999Book extends GH6999Medium
{
}

#[ORM\Entity]
class GH6999Article extends GH6999Medium
{
    /** @var Collection<int, GH6999Tag> */
    #[ORM\ManyToMany(targetEntity: GH6999Tag::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\JoinTable(name: 'gh6999_article_tag')]
    public Collection $tags;

    public function __construct(GH6999Library|null $library, string $title)
    {
        parent::__construct($library, $title);

        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH6999Tag
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    public function __construct(
        #[ORM\Column]
        public string $name,
    ) {
    }
}
