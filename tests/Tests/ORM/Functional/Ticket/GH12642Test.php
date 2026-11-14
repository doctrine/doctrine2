<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The membership of a one-to-many collection under orphan removal is carried
 * by the association — the owner's field value — and not by the collection
 * instance holding it (#12642). An element that still belongs to the
 * association when an orphan removal would be planned must survive the flush,
 * on both surfaces of that rule.
 *
 * The mutation surface: remove() and removeElement() used to schedule the
 * removed element for orphan removal without re-checking whether it is still
 * a member, so an element kept under a second key (an indexBy collection) or
 * a second occurrence (a duplicated add()) was deleted from the database
 * while remaining in the collection. Removing the last occurrence of an
 * element still has to orphan it, and without orphan removal nothing is
 * scheduled at all; an element re-homed to another owner through the
 * collections keeps surviving the flush.
 *
 * The detection surface: wholesale-replacing the collection field of a
 * managed entity with a plain collection used to de-reference the whole
 * persistent collection and delete every old element by a single set-valued
 * DELETE, elements transferred to the replacing value included. The
 * replacement now re-checks the membership of every old element against the
 * replacing value: transferred elements survive, discarded ones are removed
 * element by element, and a replacement transferring nothing keeps the
 * set-valued DELETE of the baseline. A pending orphan removal scheduled by an
 * earlier mutation is cancelled when the replacing value carries the element
 * back, and the discarded elements are removed before the insertions of the
 * same flush, so a new element may re-acquire the unique value of a
 * discarded one.
 */
class GH12642Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH12642Person::class,
            GH12642Email::class,
            GH12642PersonNoOrphanRemoval::class,
            GH12642EmailNoOrphanRemoval::class,
            GH12642IndexedPerson::class,
            GH12642IndexedEmail::class,
            GH12642UniquePerson::class,
            GH12642UniqueEmail::class,
        ]);
    }

    #[Group('gh12642-mut-duplicate-add-remove')]
    public function testDuplicateAddAndRemoveKeepsElementStillInTheCollection(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('home@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);

        // first() initializes the collection, so the duplicated add() puts
        // the same element under a second key ...
        $email = $person->emails->first();
        $person->emails->add($email);

        // ... and removing the first key leaves the element a member.
        $person->emails->remove(0);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642Email::class, $emailId));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(1, $person->emails);
    }

    #[Group('gh12642-mut-indexby-second-key')]
    public function testRemoveUnderSecondIndexKeyKeepsElement(): void
    {
        $person = new GH12642IndexedPerson();
        $person->emails->add(new GH12642IndexedEmail('home', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642IndexedPerson::class, $personId);
        $email  = $this->_em->find(GH12642IndexedEmail::class, $emailId);

        // The element keeps its membership under the second key 'work' ...
        $person->emails->set('work', $email);

        // ... so removing the 'home' key leaves it a member.
        $person->emails->remove('home');

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642IndexedEmail::class, $emailId));

        $person = $this->_em->find(GH12642IndexedPerson::class, $personId);

        self::assertCount(1, $person->emails);
    }

    #[Group('gh12642-mut-removeelement-repeated')]
    public function testRemoveElementWithDuplicatedMembershipKeepsElement(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('work@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);

        // first() initializes the collection, so the add() leaves the element
        // under a second key as well.
        $email = $person->emails->first();
        $person->emails->add($email);

        // removeElement() drops the first occurrence, the second one keeps
        // the element a member.
        $person->emails->removeElement($email);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642Email::class, $emailId));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(1, $person->emails);
    }

    #[Group('gh12642-mut-last-occurrence')]
    public function testRemovingLastOccurrenceStillOrphansElement(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('first@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);

        // Removing the last occurrence leaves the collection, so the element
        // is orphaned as before.
        $person->emails->remove(0);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH12642Email::class, $emailId));

        // The removeElement() twin of the same boundary.
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('second@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);
        $person->emails->removeElement($person->emails->first());

        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH12642Email::class, $emailId));
    }

    #[Group('gh12642-mut-no-orphan-removal')]
    public function testDuplicateAddAndRemoveWithoutOrphanRemovalChangesNothing(): void
    {
        $person = new GH12642PersonNoOrphanRemoval();
        $person->emails->add(new GH12642EmailNoOrphanRemoval('plain@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642PersonNoOrphanRemoval::class, $personId);

        // The same duplicated membership as under orphan removal.
        $email = $person->emails->first();
        $person->emails->add($email);
        $person->emails->remove(0);

        $this->_em->flush();
        $this->_em->clear();

        // Without orphan removal no removal is planned at all.
        self::assertNotNull($this->_em->find(GH12642EmailNoOrphanRemoval::class, $emailId));
    }

    #[Group('gh12642-mut-rehome')]
    public function testReHomingElementToAnotherOwnerKeepsItAlive(): void
    {
        $first  = new GH12642Person();
        $second = new GH12642Person();
        $email  = new GH12642Email('shared@example.com', $first);
        $first->emails->add($email);

        $this->_em->persist($first);
        $this->_em->persist($second);
        $this->_em->flush();

        $firstId  = $first->id;
        $secondId = $second->id;
        $emailId  = $email->id;

        $this->_em->clear();
        $first  = $this->_em->find(GH12642Person::class, $firstId);
        $second = $this->_em->find(GH12642Person::class, $secondId);
        $email  = $this->_em->find(GH12642Email::class, $emailId);

        // Removing from one owner and adding to the other re-homes the
        // element in a single flush.
        $first->emails->removeElement($email);
        $email->person = $second;
        $second->emails->add($email);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642Email::class, $emailId));

        $second = $this->_em->find(GH12642Person::class, $secondId);

        self::assertCount(1, $second->emails);
    }

    #[Group('gh12642-whl-to-subset')]
    public function testWholesaleReplacementToSubsetKeepsTransferredElement(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('kept@example.com', $person));
        $person->emails->add(new GH12642Email('dropped@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $keptId   = $person->emails->get(0)->id;
        $dropped  = $person->emails->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);
        $kept   = $this->_em->find(GH12642Email::class, $keptId);

        $replacement    = new GH12642Email('replacement@example.com', $person);
        $person->emails = new ArrayCollection([$kept, $replacement]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH12642Email::class, $dropped));
        self::assertNotNull($this->_em->find(GH12642Email::class, $keptId));
        self::assertNotNull($this->_em->find(GH12642Email::class, $replacement->id));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(2, $person->emails);
    }

    #[Group('gh12642-whl-carrying-all')]
    public function testWholesaleReplacementCarryingAllOldElementsKeepsThem(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('one@example.com', $person));
        $person->emails->add(new GH12642Email('two@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $firstId  = $person->emails->get(0)->id;
        $secondId = $person->emails->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);
        $first  = $this->_em->find(GH12642Email::class, $firstId);
        $second = $this->_em->find(GH12642Email::class, $secondId);

        $replacement    = new GH12642Email('three@example.com', $person);
        $person->emails = new ArrayCollection([$first, $second, $replacement]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642Email::class, $firstId));
        self::assertNotNull($this->_em->find(GH12642Email::class, $secondId));
        self::assertNotNull($this->_em->find(GH12642Email::class, $replacement->id));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(3, $person->emails);
    }

    #[Group('gh12642-whl-revival')]
    public function testWholesaleReplacementCancelsPendingOrphanRemoval(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('revived@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emails->first()->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);
        $email  = $this->_em->find(GH12642Email::class, $emailId);

        // The mutation scheduled the element for orphan removal ...
        $person->emails->removeElement($email);

        // ... but the replacing value carries it back into the association.
        $person->emails = new ArrayCollection([$email]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642Email::class, $emailId));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(1, $person->emails);
    }

    #[Group('gh12642-whl-uninitialized-transfer')]
    public function testWholesaleReplacementOfUninitializedCollectionKeepsTransferredElement(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('kept@example.com', $person));
        $person->emails->add(new GH12642Email('dropped@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);

        // The children are loaded by their own query, leaving the collection
        // itself uninitialized; the identity map hands out the same instances.
        $emails = $this->_em->createQuery(
            'SELECT e FROM Doctrine\Tests\ORM\Functional\Ticket\GH12642Email e WHERE e.person = :person',
        )->setParameter('person', $person)
            ->getResult();

        $keptId    = $emails[0]->id;
        $droppedId = $emails[1]->id;

        $person->emails = new ArrayCollection([$emails[0]]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642Email::class, $keptId));
        self::assertNull($this->_em->find(GH12642Email::class, $droppedId));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(1, $person->emails);
    }

    #[Group('gh12642-whl-to-new-only')]
    public function testWholesaleReplacementWithOnlyNewElementsRemovesAllOldOnes(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('one@example.com', $person));
        $person->emails->add(new GH12642Email('two@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $firstId  = $person->emails->get(0)->id;
        $secondId = $person->emails->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);

        // Nothing is transferred, so the whole old collection is removed.
        $replacement    = new GH12642Email('fresh@example.com', $person);
        $person->emails = new ArrayCollection([$replacement]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH12642Email::class, $firstId));
        self::assertNull($this->_em->find(GH12642Email::class, $secondId));
        self::assertNotNull($this->_em->find(GH12642Email::class, $replacement->id));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(1, $person->emails);
    }

    #[Group('gh12642-whl-to-empty')]
    public function testWholesaleReplacementWithEmptyCollectionRemovesAllOldOnes(): void
    {
        $person = new GH12642Person();
        $person->emails->add(new GH12642Email('one@example.com', $person));
        $person->emails->add(new GH12642Email('two@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $firstId  = $person->emails->get(0)->id;
        $secondId = $person->emails->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642Person::class, $personId);

        $person->emails = new ArrayCollection();

        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH12642Email::class, $firstId));
        self::assertNull($this->_em->find(GH12642Email::class, $secondId));

        $person = $this->_em->find(GH12642Person::class, $personId);

        self::assertCount(0, $person->emails);
    }

    #[Group('gh12642-whl-no-orphan-removal')]
    public function testWholesaleReplacementWithoutOrphanRemovalKeepsOldElements(): void
    {
        $person = new GH12642PersonNoOrphanRemoval();
        $person->emails->add(new GH12642EmailNoOrphanRemoval('one@example.com', $person));
        $person->emails->add(new GH12642EmailNoOrphanRemoval('two@example.com', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $firstId  = $person->emails->get(0)->id;
        $secondId = $person->emails->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642PersonNoOrphanRemoval::class, $personId);

        // Without orphan removal the replacement deletes nothing.
        $replacement    = new GH12642EmailNoOrphanRemoval('fresh@example.com', $person);
        $person->emails = new ArrayCollection([$replacement]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642EmailNoOrphanRemoval::class, $firstId));
        self::assertNotNull($this->_em->find(GH12642EmailNoOrphanRemoval::class, $secondId));
        self::assertNotNull($this->_em->find(GH12642EmailNoOrphanRemoval::class, $replacement->id));
    }

    #[Group('gh12642-whl-unique-reacquisition')]
    public function testWholesaleReplacementReAcquiringUniqueValueInSingleFlush(): void
    {
        $person = new GH12642UniquePerson();
        $person->emails->add(new GH12642UniqueEmail('x', $person));
        $person->emails->add(new GH12642UniqueEmail('y', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId  = $person->id;
        $droppedId = $person->emails->get(0)->id;
        $keptId    = $person->emails->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH12642UniquePerson::class, $personId);
        $kept   = $this->_em->find(GH12642UniqueEmail::class, $keptId);

        // The replacement transfers the kept element, drops the holder of
        // the unique value 'x', and its new element re-acquires that value:
        // the dropped element has to be removed before the insertion.
        $replacement    = new GH12642UniqueEmail('x', $person);
        $person->emails = new ArrayCollection([$kept, $replacement]);

        $this->_em->flush();
        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH12642UniqueEmail::class, $keptId));
        self::assertNull($this->_em->find(GH12642UniqueEmail::class, $droppedId));
        self::assertNotNull($this->_em->find(GH12642UniqueEmail::class, $replacement->id));

        $person = $this->_em->find(GH12642UniquePerson::class, $personId);

        self::assertCount(2, $person->emails);
    }
}

#[ORM\Entity]
class GH12642Person
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH12642Email> */
    #[ORM\OneToMany(
        targetEntity: GH12642Email::class,
        mappedBy: 'person',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    public Collection $emails;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH12642Email
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $address;

    #[ORM\ManyToOne(targetEntity: GH12642Person::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id')]
    public GH12642Person|null $person = null;

    public function __construct(string $address, GH12642Person|null $person = null)
    {
        $this->address = $address;
        $this->person  = $person;
    }
}

#[ORM\Entity]
class GH12642PersonNoOrphanRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH12642EmailNoOrphanRemoval> */
    #[ORM\OneToMany(
        targetEntity: GH12642EmailNoOrphanRemoval::class,
        mappedBy: 'person',
        cascade: ['persist'],
    )]
    public Collection $emails;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH12642EmailNoOrphanRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $address;

    #[ORM\ManyToOne(targetEntity: GH12642PersonNoOrphanRemoval::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id')]
    public GH12642PersonNoOrphanRemoval|null $person = null;

    public function __construct(string $address, GH12642PersonNoOrphanRemoval|null $person = null)
    {
        $this->address = $address;
        $this->person  = $person;
    }
}

#[ORM\Entity]
class GH12642IndexedPerson
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<string, GH12642IndexedEmail> */
    #[ORM\OneToMany(
        targetEntity: GH12642IndexedEmail::class,
        mappedBy: 'person',
        indexBy: 'type',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    public Collection $emails;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH12642IndexedEmail
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $type;

    #[ORM\ManyToOne(targetEntity: GH12642IndexedPerson::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id')]
    public GH12642IndexedPerson|null $person = null;

    public function __construct(string $type, GH12642IndexedPerson|null $person = null)
    {
        $this->type   = $type;
        $this->person = $person;
    }
}

#[ORM\Entity]
class GH12642UniquePerson
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH12642UniqueEmail> */
    #[ORM\OneToMany(
        targetEntity: GH12642UniqueEmail::class,
        mappedBy: 'person',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    public Collection $emails;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH12642UniqueEmail
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string', unique: true, length: 100)]
    public string $address;

    #[ORM\ManyToOne(targetEntity: GH12642UniquePerson::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id')]
    public GH12642UniquePerson|null $person = null;

    public function __construct(string $address, GH12642UniquePerson|null $person = null)
    {
        $this->address = $address;
        $this->person  = $person;
    }
}
