<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH10852Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH10852Card::class,
            GH10852Hand::class,
        ]);

        $card = new GH10852Card(GH10852Suit::Clubs, 'clubs');

        $this->_em->persist($card);
        $this->_em->persist(new GH10852Hand(1, $card));
        $this->_em->flush();
        $this->_em->clear();
    }

    protected function tearDown(): void
    {
        $conn = $this->_em->getConnection();

        $conn->executeStatement('DELETE FROM GH10852Hand');
        $conn->executeStatement('DELETE FROM GH10852Card');

        parent::tearDown();
    }

    public function testRehydratingAManagedEntityKeepsItsEnumIdentifierFlattened(): void
    {
        $card = $this->_em->find(GH10852Card::class, GH10852Suit::Clubs);

        self::assertSame(['suit' => 'C'], $this->_em->getUnitOfWork()->getEntityIdentifier($card));

        $this->rehydrate($card);

        self::assertSame(['suit' => 'C'], $this->_em->getUnitOfWork()->getEntityIdentifier($card));
    }

    public function testRehydratedEntityWithEnumIdentifierCanBeBoundAsQueryParameter(): void
    {
        $card = $this->_em->find(GH10852Card::class, GH10852Suit::Clubs);

        $this->rehydrate($card);

        self::assertCount(1, $this->handsHolding($card));
    }

    public function testInitializingALazyReferenceKeepsItsEnumIdentifierFlattened(): void
    {
        $card = $this->_em->find(GH10852Hand::class, 1)->card;

        self::assertSame(['suit' => 'C'], $this->_em->getUnitOfWork()->getEntityIdentifier($card));

        // Reading anything but the identifier initializes the reference, which re-hydrates
        // the entity through the very same refresh hints.
        self::assertSame('clubs', $card->label);

        self::assertSame(['suit' => 'C'], $this->_em->getUnitOfWork()->getEntityIdentifier($card));
        self::assertCount(1, $this->handsHolding($card));
    }

    /**
     * Reloads an instance the UnitOfWork already manages, the way the ORM does it
     * internally when it initializes a lazy reference.
     */
    private function rehydrate(GH10852Card $card): void
    {
        $this->_em->createQuery('SELECT c FROM ' . GH10852Card::class . ' c')
            ->setHint(Query::HINT_REFRESH, true)
            ->setHint(Query::HINT_REFRESH_ENTITY, $card)
            ->getSingleResult();
    }

    /** @return GH10852Hand[] */
    private function handsHolding(GH10852Card $card): array
    {
        return $this->_em->createQuery('SELECT h FROM ' . GH10852Hand::class . ' h WHERE h.card = :card')
            ->setParameter('card', $card)
            ->getResult();
    }
}

enum GH10852Suit: string
{
    case Hearts   = 'H';
    case Diamonds = 'D';
    case Clubs    = 'C';
    case Spades   = 'S';
}

#[Entity]
class GH10852Card
{
    public function __construct(
        #[Id]
        #[Column(length: 1, enumType: GH10852Suit::class)]
        public GH10852Suit $suit,
        #[Column]
        public string $label,
    ) {
    }
}

#[Entity]
class GH10852Hand
{
    public function __construct(
        #[Id]
        #[Column]
        public int $id,
        #[ManyToOne(targetEntity: GH10852Card::class)]
        #[JoinColumn(referencedColumnName: 'suit')]
        public GH10852Card $card,
    ) {
    }
}
