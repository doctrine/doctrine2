<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Replacing an element of a collection or a to-one reference that is subject
 * to orphan removal, where the new element collides with the removed one on a
 * unique constraint, must be possible in a single flush() (#6776).
 */
class GH6776Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GH6776Item::$postRemoveCount    = 0;
        GH6776Address::$postRemoveCount = 0;

        $this->createSchemaForModels(
            GH6776Cart::class,
            GH6776Item::class,
            GH6776User::class,
            GH6776Address::class,
        );
    }

    public function testReplaceCollectionElementWithSameUniqueValueInSingleFlush(): void
    {
        $cart = new GH6776Cart();

        $oldItem       = new GH6776Item('v');
        $oldItem->cart = $cart;
        $cart->items->add($oldItem);

        $this->_em->persist($cart);
        $this->_em->flush();

        $oldItemId = $oldItem->id;

        $newItem       = new GH6776Item('v');
        $newItem->cart = $cart;
        $cart->items->removeElement($oldItem);
        $cart->items->add($newItem);

        $this->_em->flush();

        $this->_em->clear();

        $remainingItems = $this->_em->getRepository(GH6776Item::class)->findBy(['f' => 'v']);

        self::assertCount(1, $remainingItems);
        self::assertSame($newItem->id, $remainingItems[0]->id);
        self::assertNull($this->_em->find(GH6776Item::class, $oldItemId));
        self::assertSame(1, GH6776Item::$postRemoveCount);
    }

    public function testReplaceOneToOneReferenceWithSameUniqueValueInSingleFlush(): void
    {
        $user = new GH6776User();

        $oldAddress       = new GH6776Address();
        $oldAddress->user = $user;
        $user->address    = $oldAddress;

        $this->_em->persist($user);
        $this->_em->flush();

        $oldAddressId = $oldAddress->id;

        $newAddress       = new GH6776Address();
        $newAddress->user = $user;
        $user->address    = $newAddress;

        $this->_em->flush();

        $this->_em->clear();

        $remainingAddresses = $this->_em->getRepository(GH6776Address::class)->findAll();

        self::assertCount(1, $remainingAddresses);
        self::assertSame($newAddress->id, $remainingAddresses[0]->id);
        self::assertSame($user->id, $remainingAddresses[0]->user->id);
        self::assertNull($this->_em->find(GH6776Address::class, $oldAddressId));
        self::assertSame(1, GH6776Address::$postRemoveCount);
    }
}

#[ORM\Entity]
class GH6776Cart
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH6776Item> */
    #[ORM\OneToMany(targetEntity: GH6776Item::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true)]
    public Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class GH6776Item
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(unique: true)]
    public string $f;

    #[ORM\ManyToOne(targetEntity: GH6776Cart::class, inversedBy: 'items')]
    public GH6776Cart|null $cart = null;

    public static int $postRemoveCount = 0;

    public function __construct(string $f)
    {
        $this->f = $f;
    }

    #[ORM\PostRemove]
    public function countRemoval(): void
    {
        self::$postRemoveCount++;
    }
}

#[ORM\Entity]
class GH6776User
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\OneToOne(targetEntity: GH6776Address::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    public GH6776Address|null $address = null;
}

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class GH6776Address
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\OneToOne(targetEntity: GH6776User::class, inversedBy: 'address')]
    #[ORM\JoinColumn(nullable: false)]
    public GH6776User|null $user = null;

    public static int $postRemoveCount = 0;

    #[ORM\PostRemove]
    public function countRemoval(): void
    {
        self::$postRemoveCount++;
    }
}
