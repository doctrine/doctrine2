<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Removing an entity through an explicit remove() and inserting a new entity
 * of the same class that collides with it on a metadata-declared unique
 * constraint must be possible in a single flush() (#5109).
 */
class GH5109Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GH5109Article::$events  = [];
        GH5109CartItem::$events = [];

        $this->createSchemaForModels(
            GH5109Article::class,
            GH5109Holder::class,
            GH5109Shelf::class,
            GH5109Cart::class,
            GH5109CartItem::class,
        );
    }

    #[Group('gh5109-repro')]
    public function testRemoveAndReinsertWithSameUniqueValueInSingleFlush(): void
    {
        $oldArticle = new GH5109Article('v');
        $this->_em->persist($oldArticle);
        $this->_em->flush();

        $oldArticleId          = $oldArticle->id;
        GH5109Article::$events = [];

        $newArticle = new GH5109Article('v');

        $this->_em->remove($oldArticle);
        $this->_em->persist($newArticle);

        $this->_em->flush();

        $this->_em->clear();

        $remainingArticles = $this->_em->getRepository(GH5109Article::class)->findBy(['title' => 'v']);

        self::assertCount(1, $remainingArticles);
        self::assertSame($newArticle->id, $remainingArticles[0]->id);
        self::assertNull($this->_em->find(GH5109Article::class, $oldArticleId));
        self::assertSame(['remove', 'insert'], GH5109Article::$events);
    }

    #[Group('gh5109-fk-ref')]
    public function testCollidingRemovalUnderForeignKeyReferenceKeepsBaselineOrder(): void
    {
        // The unique value is distinct from the values used by the other
        // scenarios: the functional harness shares the database between the
        // tests of this class, so the setup rows must not collide.
        $oldArticle      = new GH5109Article('fk');
        $holder          = new GH5109Holder();
        $holder->article = $oldArticle;

        $this->_em->persist($oldArticle);
        $this->_em->persist($holder);
        $this->_em->flush();

        $newArticle = new GH5109Article('fk');

        $this->_em->remove($oldArticle);
        $this->_em->persist($newArticle);

        // The pending update still carries the old article as its original
        // foreign key value: removing it before the insertions would break
        // foreign key soundness, so the removal stays on the baseline commit
        // order and the flush fails on the unique constraint.
        $holder->article = $newArticle;

        $this->expectException(UniqueConstraintViolationException::class);
        $this->_em->flush();
    }

    #[Group('gh5109-m2m')]
    public function testCollidingRemovalAsPendingManyToManyElementKeepsBaselineOrder(): void
    {
        $oldArticle = new GH5109Article('m2m');
        $shelf      = new GH5109Shelf();
        $shelf->articles->add($oldArticle);

        $this->_em->persist($oldArticle);
        $this->_em->persist($shelf);
        $this->_em->flush();

        $newArticle = new GH5109Article('m2m');

        $this->_em->remove($oldArticle);
        $this->_em->persist($newArticle);

        // The pending collection update deletes the join row of the old
        // article only after the insertions: an early deletion would leave a
        // live join row behind, so the removal stays on the baseline commit
        // order and the flush fails on the unique constraint.
        $shelf->articles->removeElement($oldArticle);
        $shelf->articles->add($newArticle);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->_em->flush();
    }

    #[Group('gh5109-no-collision')]
    public function testRemovalWithoutCollisionKeepsBaselineOrder(): void
    {
        $oldArticle = new GH5109Article('stale');
        $this->_em->persist($oldArticle);
        $this->_em->flush();

        $oldArticleId          = $oldArticle->id;
        GH5109Article::$events = [];

        $newArticle = new GH5109Article('fresh');

        $this->_em->remove($oldArticle);
        $this->_em->persist($newArticle);

        $this->_em->flush();

        $this->_em->clear();

        $remainingArticles = $this->_em->getRepository(GH5109Article::class)->findBy(['title' => 'fresh']);

        self::assertCount(1, $remainingArticles);
        self::assertSame($newArticle->id, $remainingArticles[0]->id);
        self::assertNull($this->_em->find(GH5109Article::class, $oldArticleId));
        self::assertSame(['insert', 'remove'], GH5109Article::$events);
    }

    #[Group('gh5109-mixed-provenance')]
    public function testExplicitRemoveOfEntityAlsoManagedByOrphanRemovalRemovedExactlyOnce(): void
    {
        $cart = new GH5109Cart();

        $oldItem       = new GH5109CartItem('orphan');
        $oldItem->cart = $cart;
        $cart->items->add($oldItem);

        $this->_em->persist($cart);
        $this->_em->flush();

        GH5109CartItem::$events = [];

        $newItem       = new GH5109CartItem('orphan');
        $newItem->cart = $cart;

        // The removal is scheduled explicitly, before the orphan removal of
        // the same entity is materialized: it is executed early, exactly once.
        $this->_em->remove($oldItem);
        $cart->items->removeElement($oldItem);
        $cart->items->add($newItem);

        $this->_em->flush();

        $this->_em->clear();

        $remainingItems = $this->_em->getRepository(GH5109CartItem::class)->findBy(['label' => 'orphan']);

        self::assertCount(1, $remainingItems);
        self::assertSame($newItem->id, $remainingItems[0]->id);
        self::assertSame(['remove', 'insert'], GH5109CartItem::$events);
    }
}

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class GH5109Article
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(unique: true)]
    public string $title;

    /** @var list<string> */
    public static array $events = [];

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    #[ORM\PostRemove]
    public function logRemove(): void
    {
        self::$events[] = 'remove';
    }

    #[ORM\PostPersist]
    public function logInsert(): void
    {
        self::$events[] = 'insert';
    }
}

#[ORM\Entity]
class GH5109Holder
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\ManyToOne(targetEntity: GH5109Article::class)]
    #[ORM\JoinColumn(nullable: false)]
    public GH5109Article|null $article = null;
}

#[ORM\Entity]
class GH5109Shelf
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH5109Article> */
    #[ORM\ManyToMany(targetEntity: GH5109Article::class)]
    #[ORM\JoinTable('gh5109_shelf_article')]
    public Collection $articles;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH5109Cart
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH5109CartItem> */
    #[ORM\OneToMany(targetEntity: GH5109CartItem::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true)]
    public Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class GH5109CartItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(unique: true)]
    public string $label;

    #[ORM\ManyToOne(targetEntity: GH5109Cart::class, inversedBy: 'items')]
    public GH5109Cart|null $cart = null;

    /** @var list<string> */
    public static array $events = [];

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    #[ORM\PostRemove]
    public function logRemove(): void
    {
        self::$events[] = 'remove';
    }

    #[ORM\PostPersist]
    public function logInsert(): void
    {
        self::$events[] = 'insert';
    }
}
