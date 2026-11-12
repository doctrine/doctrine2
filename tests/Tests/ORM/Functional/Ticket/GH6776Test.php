<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function assert;

#[Group('GH6776')]
#[Group('non-cacheable')]
class GH6776Test extends OrmFunctionalTestCase
{
    private GH6776EventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            GH6776Cart::class,
            GH6776Item::class,
            GH6776User::class,
            GH6776Address::class,
            GH6776Tag::class,
            GH6776SpecialTag::class,
            GH6776Shelf::class,
            GH6776Holder::class,
            GH6776Note::class,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (['gh6776_holder', 'gh6776_shelf_tag', 'gh6776_shelf', 'gh6776_tag', 'gh6776_address', 'gh6776_user', 'gh6776_note', 'gh6776_item', 'gh6776_cart'] as $table) {
            static::$sharedConn->executeStatement('DELETE FROM ' . $table);
        }
    }

    /** @return iterable<string, array{Closure(GH6776Cart, GH6776Item): void}> */
    public static function provideCollectionReplacements(): iterable
    {
        yield 'removeElement() and add()' => [
            static function (GH6776Cart $cart, GH6776Item $new): void {
                $cart->items->removeElement($cart->items->get('work'));
                $cart->items->add($new);
            },
        ];

        yield 'remove() and set() under the same key' => [
            static function (GH6776Cart $cart, GH6776Item $new): void {
                $cart->items->remove('work');
                $cart->items->set('work', $new);
            },
        ];

        yield 'bare set() over an existing key' => [
            static function (GH6776Cart $cart, GH6776Item $new): void {
                $cart->items->set('work', $new);
            },
        ];

        yield 'clear() and add()' => [
            static function (GH6776Cart $cart, GH6776Item $new): void {
                $cart->items->clear();
                $cart->items->add($new);
            },
        ];
    }

    /** @param Closure(GH6776Cart, GH6776Item): void $replace */
    #[DataProvider('provideCollectionReplacements')]
    public function testReplacingCollectionElementInSingleFlush(Closure $replace): void
    {
        $cart = new GH6776Cart();
        $old  = new GH6776Item('work', $cart);
        $cart->items->add($old);
        $this->_em->persist($cart);
        $this->_em->flush();
        $this->_em->clear();

        $oldId = $old->id;
        $cart  = $this->_em->find(GH6776Cart::class, $cart->id);
        $new   = new GH6776Item('work', $cart);
        $replace($cart, $new);
        $this->_em->flush();
        $this->_em->clear();

        $cart = $this->_em->find(GH6776Cart::class, $cart->id);
        self::assertCount(1, $cart->items);
        self::assertSame($new->id, $cart->items->get('work')->id);
        self::assertNull($this->_em->find(GH6776Item::class, $oldId));
    }

    public function testReplacingOrphanWhoseUniqueFieldChangedBeforeRemoval(): void
    {
        $cart = new GH6776Cart();
        $cart->items->add(new GH6776Item('work', $cart));
        $this->_em->persist($cart);
        $this->_em->flush();
        $this->_em->clear();

        $cart      = $this->_em->find(GH6776Cart::class, $cart->id);
        $old       = $cart->items->get('work');
        $oldId     = $old->id;
        $old->code = 'renamed';
        $cart->items->removeElement($old);
        $new = new GH6776Item('work', $cart);
        $cart->items->add($new);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH6776Item::class, $oldId));
        self::assertNotNull($this->_em->find(GH6776Item::class, $new->id));
    }

    public function testReplacingOrphanWithCascadeRemovedChildren(): void
    {
        $cart = new GH6776Cart();
        $old  = new GH6776Item('work', $cart);
        $old->notes->add(new GH6776Note($old));
        $cart->items->add($old);
        $this->_em->persist($cart);
        $this->_em->flush();
        $this->_em->clear();

        $oldId = $old->id;
        $cart  = $this->_em->find(GH6776Cart::class, $cart->id);
        $cart->items->removeElement($cart->items->get('work'));
        $new = new GH6776Item('work', $cart);
        $cart->items->add($new);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH6776Item::class, $oldId));
        self::assertNotNull($this->_em->find(GH6776Item::class, $new->id));
    }

    /** @return iterable<string, array{bool}> */
    public static function provideOneToOneReplacements(): iterable
    {
        yield 'orphan removal' => [false];
        yield 'explicit remove()' => [true];
    }

    #[DataProvider('provideOneToOneReplacements')]
    public function testReplacingOneToOneWithUniqueJoinColumnInSingleFlush(bool $explicitRemove): void
    {
        $user          = new GH6776User();
        $old           = new GH6776Address($user);
        $user->address = $old;
        $this->_em->persist($user);
        $this->_em->flush();

        $oldId = $old->id;
        if ($explicitRemove) {
            $this->_em->remove($old);
        }

        $new           = new GH6776Address($user);
        $user->address = $new;
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH6776Address::class, $oldId));
        self::assertSame($new->id, $this->_em->find(GH6776User::class, $user->id)->address->id);
    }

    public function testOnlyCollidingDeletionRunsBeforeInsertions(): void
    {
        $colliding = new GH6776Tag('shared');
        $lonely    = new GH6776Tag('lonely');
        $updated   = new GH6776Tag('updated');
        $this->_em->persist($colliding);
        $this->_em->persist($lonely);
        $this->_em->persist($updated);
        $this->_em->flush();

        $this->recordEvents();

        $this->_em->remove($colliding);
        $this->_em->remove($lonely);
        $this->_em->persist(new GH6776Tag('shared'));
        $updated->label = 'changed';
        $this->_em->flush();

        self::assertSame(
            [
                'postRemove:shared id=null scheduled=false',
                'postPersist:shared',
                'preUpdate:updated',
                'postUpdate:updated',
                'postRemove:lonely id=null scheduled=false',
            ],
            $this->recorder->events,
        );
    }

    public function testCollisionIsDetectedOnTheDatabaseRowOfTheDeletion(): void
    {
        $old = new GH6776Tag('k');
        $this->_em->persist($old);
        $this->_em->flush();

        $oldId     = $old->id;
        $old->name = 'changed before remove';
        $this->_em->remove($old);
        $new = new GH6776Tag('k');
        $this->_em->persist($new);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH6776Tag::class, $oldId));
        self::assertNotNull($this->_em->find(GH6776Tag::class, $new->id));
    }

    public function testExecutedCollectionDeletionDoesNotBlockEarlyDeletion(): void
    {
        $old   = new GH6776Tag('t');
        $shelf = new GH6776Shelf('s');
        $shelf->tags->add($old);
        $this->_em->persist($old);
        $this->_em->persist($shelf);
        $this->_em->flush();

        $oldId = $old->id;
        $shelf->tags->clear();
        $this->_em->remove($old);
        $new = new GH6776Tag('t');
        $this->_em->persist($new);
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH6776Tag::class, $oldId));
        self::assertNotNull($this->_em->find(GH6776Tag::class, $new->id));
    }

    /** @return iterable<string, array{Closure(EntityManagerInterface): void}> */
    public static function provideUnsafeEarlyDeletions(): iterable
    {
        yield 'referenced by the original value of a pending update' => [
            static function (EntityManagerInterface $em): void {
                $old    = new GH6776Tag('t');
                $holder = new GH6776Holder($old);
                $em->persist($old);
                $em->persist($holder);
                $em->flush();

                $new = new GH6776Tag('t');
                $em->remove($old);
                $em->persist($new);
                $holder->tag = $new;
            },
        ];

        yield 'element of a pending many-to-many update' => [
            static function (EntityManagerInterface $em): void {
                $old   = new GH6776Tag('t');
                $shelf = new GH6776Shelf('s');
                $shelf->tags->add($old);
                $em->persist($old);
                $em->persist($shelf);
                $em->flush();

                $new = new GH6776Tag('t');
                $em->remove($old);
                $em->persist($new);
                $shelf->tags->removeElement($old);
                $shelf->tags->add($new);
            },
        ];

        yield 'subclass element of a pending many-to-many update' => [
            static function (EntityManagerInterface $em): void {
                $old   = new GH6776SpecialTag('t');
                $shelf = new GH6776Shelf('s');
                $shelf->tags->add($old);
                $em->persist($old);
                $em->persist($shelf);
                $em->flush();

                $em->remove($old);
                $em->persist(new GH6776SpecialTag('t'));
                $shelf->tags->removeElement($old);
            },
        ];

        yield 'owner of a pending many-to-many update' => [
            static function (EntityManagerInterface $em): void {
                $tag   = new GH6776Tag('t');
                $shelf = new GH6776Shelf('s');
                $shelf->tags->add($tag);
                $em->persist($tag);
                $em->persist($shelf);
                $em->flush();

                $em->persist(new GH6776Shelf('s'));
                $shelf->tags->removeElement($tag);
                $em->getUnitOfWork()->scheduleOrphanRemoval($shelf);
            },
        ];
    }

    /** @param Closure(EntityManagerInterface): void $scenario */
    #[DataProvider('provideUnsafeEarlyDeletions')]
    public function testUnsafeEarlyDeletionKeepsCommitOrder(Closure $scenario): void
    {
        $scenario($this->_em);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->_em->flush();
    }

    private function recordEvents(): void
    {
        $this->recorder = new GH6776EventRecorder();
        $this->_em->getEventManager()->addEventListener(
            [Events::postRemove, Events::postPersist, Events::preUpdate, Events::postUpdate],
            $this->recorder,
        );
    }
}

class GH6776EventRecorder
{
    /** @var list<string> */
    public array $events = [];

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $tag = $args->getObject();
        assert($tag instanceof GH6776Tag);

        $this->events[] = 'postRemove:' . $tag->name
            . ' id=' . ($tag->id ?? 'null')
            . ' scheduled=' . ($args->getObjectManager()->getUnitOfWork()->isScheduledForDelete($tag) ? 'true' : 'false');
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->events[] = 'postPersist:' . $this->name($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->events[] = 'preUpdate:' . $this->name($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->events[] = 'postUpdate:' . $this->name($args->getObject());
    }

    private function name(object $tag): string
    {
        assert($tag instanceof GH6776Tag);

        return $tag->name;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_cart')]
class GH6776Cart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    /** @var Collection<string, GH6776Item> */
    #[ORM\OneToMany(targetEntity: GH6776Item::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true, indexBy: 'code')]
    public Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_item')]
#[ORM\UniqueConstraint(name: 'gh6776_item_cart_code', columns: ['cart_id', 'code'])]
class GH6776Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    /** @var Collection<int, GH6776Note> */
    #[ORM\OneToMany(targetEntity: GH6776Note::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $notes;

    public function __construct(
        #[ORM\Column]
        public string $code,
        #[ORM\ManyToOne(inversedBy: 'items')]
        public GH6776Cart $cart,
    ) {
        $this->notes = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_note')]
class GH6776Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'notes')]
        #[ORM\JoinColumn(nullable: false)]
        public GH6776Item $item,
    ) {
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_user')]
class GH6776User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    #[ORM\OneToOne(targetEntity: GH6776Address::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    public GH6776Address|null $address = null;
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_address')]
class GH6776Address
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    public function __construct(
        #[ORM\OneToOne(inversedBy: 'address')]
        #[ORM\JoinColumn(nullable: false)]
        public GH6776User $user,
    ) {
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_tag')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap(['tag' => GH6776Tag::class, 'special' => GH6776SpecialTag::class])]
class GH6776Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    #[ORM\Column]
    public string $label = '';

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $name,
    ) {
    }
}

#[ORM\Entity]
class GH6776SpecialTag extends GH6776Tag
{
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_shelf')]
class GH6776Shelf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    /** @var Collection<int, GH6776Tag> */
    #[ORM\ManyToMany(targetEntity: GH6776Tag::class)]
    #[ORM\JoinTable(name: 'gh6776_shelf_tag')]
    public Collection $tags;

    public function __construct(
        #[ORM\Column(unique: true)]
        public string $name,
    ) {
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh6776_holder')]
class GH6776Holder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        public GH6776Tag $tag,
    ) {
    }
}
