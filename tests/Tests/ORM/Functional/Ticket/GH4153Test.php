<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function count;

/**
 * Replacing an element of a one-to-many collection keyed by `indexBy` must be
 * possible in a single flush() when a composite unique constraint spans the
 * owning join column and the index field (#4153): both a bare set() over an
 * existing key and an explicit remove() followed by set().
 *
 * The pinned semantics: a set() orphans the replaced element only when it
 * leaves the collection entirely; without orphan removal nothing is deleted;
 * and without a collision the replaced row disappears on its own.
 *
 * The scenarios address elements by their index keys after a reload; the
 * second-level-cache hydration of a collection does not preserve those keys,
 * so the class cannot run under the cache profile.
 */
#[Group('non-cacheable')]
class GH4153Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            GH4153Person::class,
            GH4153Email::class,
            GH4153PersonNoOrphanRemoval::class,
            GH4153EmailNoOrphanRemoval::class,
            GH4153PersonNoUnique::class,
            GH4153EmailNoUnique::class,
        );
    }

    #[Group('gh4153-form1-set-replace')]
    public function testBareSetReplacesIndexedElementInSingleFlush(): void
    {
        $person = new GH4153Person();
        $person->emailAddresses->add(new GH4153Email('work', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId   = $person->id;
        $oldEmailId = $person->emailAddresses->get(0)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153Person::class, $personId);

        $newEmail = new GH4153Email('work', $person);

        // A bare set() over the existing 'work' key, without any explicit
        // remove(): the replaced element is orphaned by the replacement.
        $person->emailAddresses->set('work', $newEmail);

        // The replacement carries the same (owner_id, name) pair as the row
        // of the replaced element: its removal has to be executed before the
        // insertion for the flush to satisfy the unique constraint.
        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($newEmail->id);
        self::assertNull($this->_em->find(GH4153Email::class, $oldEmailId));

        $person = $this->_em->find(GH4153Person::class, $personId);

        self::assertCount(1, $person->emailAddresses);
        self::assertSame($newEmail->id, $person->emailAddresses->get('work')->id);
    }

    #[Group('gh4153-form2-remove-set')]
    public function testRemoveAndSetReplaceIndexedElementInSingleFlush(): void
    {
        $person = new GH4153Person();
        $person->emailAddresses->add(new GH4153Email('work', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId   = $person->id;
        $oldEmailId = $person->emailAddresses->get(0)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153Person::class, $personId);

        $newEmail = new GH4153Email('work', $person);

        // The explicit form of the same replacement: remove() followed by
        // set() under the same key in one flush.
        $person->emailAddresses->remove('work');
        $person->emailAddresses->set('work', $newEmail);

        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($newEmail->id);
        self::assertNull($this->_em->find(GH4153Email::class, $oldEmailId));

        $person = $this->_em->find(GH4153Person::class, $personId);

        self::assertCount(1, $person->emailAddresses);
        self::assertSame($newEmail->id, $person->emailAddresses->get('work')->id);
    }

    #[Group('gh4153-set-no-orphan-removal')]
    public function testSetWithoutOrphanRemovalKeepsReplacedElement(): void
    {
        $person = new GH4153PersonNoOrphanRemoval();
        $person->emails->add(new GH4153EmailNoOrphanRemoval('work', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId   = $person->id;
        $oldEmailId = $person->emails->get(0)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153PersonNoOrphanRemoval::class, $personId);

        // Without orphan removal a bare set() does not delete the replaced
        // element: the mapping here carries no unique constraint, so both
        // rows coexist and the old one stays reachable.
        $person->emails->set('work', new GH4153EmailNoOrphanRemoval('work', $person));

        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH4153EmailNoOrphanRemoval::class, $oldEmailId));
        self::assertCount(2, $this->_em->getRepository(GH4153EmailNoOrphanRemoval::class)->findAll());
    }

    #[Group('gh4153-set-new-key')]
    public function testSetUnderNewKeyRemovesNothing(): void
    {
        $person = new GH4153Person();
        $person->emailAddresses->add(new GH4153Email('work', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId   = $person->id;
        $oldEmailId = $person->emailAddresses->get(0)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153Person::class, $personId);

        $newEmail = new GH4153Email('home', $person);

        // A set() under a key that does not exist yet replaces nothing:
        // the collection only grows, no removal is scheduled at all.
        $person->emailAddresses->set('home', $newEmail);

        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($newEmail->id);
        self::assertNotNull($this->_em->find(GH4153Email::class, $oldEmailId));

        $person = $this->_em->find(GH4153Person::class, $personId);

        self::assertCount(2, $person->emailAddresses);
        self::assertSame($oldEmailId, $person->emailAddresses->get('work')->id);
        self::assertSame($newEmail->id, $person->emailAddresses->get('home')->id);
    }

    #[Group('gh4153-key-swap')]
    public function testSwappingKeysThroughSetsKeepsBothElements(): void
    {
        $person = new GH4153Person();
        $person->emailAddresses->add(new GH4153Email('work', $person));
        $person->emailAddresses->add(new GH4153Email('home', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $workId   = $person->emailAddresses->get(0)->id;
        $homeId   = $person->emailAddresses->get(1)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153Person::class, $personId);
        $work   = $person->emailAddresses->get('work');
        $home   = $person->emailAddresses->get('home');

        // Swapping the two keys through set() keeps the net membership of
        // the collection unchanged: neither element may be orphaned by the
        // swap, both have to stay alive.
        $person->emailAddresses->set('work', $home);
        $person->emailAddresses->set('home', $work);

        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($this->_em->find(GH4153Email::class, $workId));
        self::assertNotNull($this->_em->find(GH4153Email::class, $homeId));

        $person = $this->_em->find(GH4153Person::class, $personId);

        self::assertCount(2, $person->emailAddresses);

        // The swap of the array keys leaves the rows untouched: after the
        // reload the keys come from the rows' own name fields again.
        self::assertSame($workId, $person->emailAddresses->get('work')->id);
        self::assertSame($homeId, $person->emailAddresses->get('home')->id);
    }

    #[Group('gh4153-re-home')]
    public function testReHomingElementToAnotherOwnerKeepsItAlive(): void
    {
        $person1 = new GH4153Person();
        $person1->emailAddresses->add(new GH4153Email('work', $person1));
        $person2 = new GH4153Person();

        $this->_em->persist($person1);
        $this->_em->persist($person2);
        $this->_em->flush();

        $emailId   = $person1->emailAddresses->get(0)->id;
        $personId1 = $person1->id;
        $personId2 = $person2->id;

        $this->_em->clear();
        $person1 = $this->_em->find(GH4153Person::class, $personId1);
        $person2 = $this->_em->find(GH4153Person::class, $personId2);
        $email   = $person1->emailAddresses->get('work');

        // Moving the element to another owner through the collections: the
        // remove() from the old collection schedules the orphan removal, the
        // add() into the new one cancels it back: the row changes its owner
        // instead of being deleted.
        $email->owner = $person2;
        $person1->emailAddresses->remove('work');
        $person2->emailAddresses->add($email);

        $this->_em->flush();

        $this->_em->clear();

        $email = $this->_em->find(GH4153Email::class, $emailId);

        self::assertNotNull($email);
        self::assertSame($personId2, $email->owner->id);

        $person1 = $this->_em->find(GH4153Person::class, $personId1);
        $person2 = $this->_em->find(GH4153Person::class, $personId2);

        self::assertCount(0, $person1->emailAddresses);
        self::assertCount(1, $person2->emailAddresses);
        self::assertSame($emailId, $person2->emailAddresses->get('work')->id);
    }

    #[Group('gh4153-set-replace-no-unique')]
    public function testBareSetWithoutUniqueConstraintStillRemovesReplacedElement(): void
    {
        $person = new GH4153PersonNoUnique();
        $person->emails->add(new GH4153EmailNoUnique('work', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId   = $person->id;
        $oldEmailId = $person->emails->get(0)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153PersonNoUnique::class, $personId);

        $newEmail = new GH4153EmailNoUnique('work', $person);

        // The replacement under orphan removal with nothing to collide on:
        // without the orphan scheduling, the row of the replaced element
        // would silently stay next to the new one.
        $person->emails->set('work', $newEmail);

        $this->_em->flush();

        $this->_em->clear();

        self::assertNotNull($newEmail->id);
        self::assertNull($this->_em->find(GH4153EmailNoUnique::class, $oldEmailId));

        $person = $this->_em->find(GH4153PersonNoUnique::class, $personId);

        self::assertCount(1, $person->emails);
        self::assertSame($newEmail->id, $person->emails->get('work')->id);
    }

    #[Group('gh4153-same-key-return')]
    public function testReturningReplacedElementUnderSameKeyRevivesIt(): void
    {
        $person = new GH4153Person();
        $person->emailAddresses->add(new GH4153Email('work', $person));

        $this->_em->persist($person);
        $this->_em->flush();

        $personId = $person->id;
        $emailId  = $person->emailAddresses->get(0)->id;

        $this->_em->clear();
        $person = $this->_em->find(GH4153Person::class, $personId);
        $email  = $person->emailAddresses->get('work');

        $newEmail = new GH4153Email('work', $person);

        $rowsBefore = count($this->_em->getRepository(GH4153Email::class)->findAll());

        // The first set() pushes the original element out of the collection
        // and schedules its orphan removal; the second one brings it back
        // under the same key, and the cancel-orphan-removal pass of that very
        // set() revives it. The never-persisted replacement stays without a
        // row.
        $person->emailAddresses->set('work', $newEmail);
        $person->emailAddresses->set('work', $email);

        $this->_em->flush();

        $this->_em->clear();

        self::assertNull($newEmail->id);
        self::assertNotNull($this->_em->find(GH4153Email::class, $emailId));

        $person = $this->_em->find(GH4153Person::class, $personId);

        self::assertCount(1, $person->emailAddresses);
        self::assertSame($emailId, $person->emailAddresses->get('work')->id);

        // The revived element kept its row, the replacement never got one.
        self::assertCount($rowsBefore, $this->_em->getRepository(GH4153Email::class)->findAll());
    }
}

#[ORM\Entity]
class GH4153Person
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<string, GH4153Email> */
    #[ORM\OneToMany(
        targetEntity: GH4153Email::class,
        mappedBy: 'owner',
        indexBy: 'name',
        cascade: ['all'],
        orphanRemoval: true,
    )]
    public Collection $emailAddresses;

    public function __construct()
    {
        $this->emailAddresses = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'gh4153_owner_name_uniq', columns: ['owner_id', 'name'])]
class GH4153Email
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column]
    public string $name;

    #[ORM\ManyToOne(targetEntity: GH4153Person::class, inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
    public GH4153Person|null $owner = null;

    public function __construct(string $name, GH4153Person|null $owner = null)
    {
        $this->name  = $name;
        $this->owner = $owner;
    }
}

#[ORM\Entity]
class GH4153PersonNoOrphanRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<string, GH4153EmailNoOrphanRemoval> */
    #[ORM\OneToMany(
        targetEntity: GH4153EmailNoOrphanRemoval::class,
        mappedBy: 'owner',
        indexBy: 'name',
        cascade: ['persist'],
    )]
    public Collection $emails;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH4153EmailNoOrphanRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column]
    public string $name;

    #[ORM\ManyToOne(targetEntity: GH4153PersonNoOrphanRemoval::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
    public GH4153PersonNoOrphanRemoval|null $owner = null;

    public function __construct(string $name, GH4153PersonNoOrphanRemoval|null $owner = null)
    {
        $this->name  = $name;
        $this->owner = $owner;
    }
}

/**
 * The replacement-under-orphan-removal fixtures without the unique constraint:
 * with nothing to collide on, the removal of the replaced element has to be
 * observable directly by the absence of its row, not indirectly through a
 * constraint violation.
 */
#[ORM\Entity]
class GH4153PersonNoUnique
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<string, GH4153EmailNoUnique> */
    #[ORM\OneToMany(
        targetEntity: GH4153EmailNoUnique::class,
        mappedBy: 'owner',
        indexBy: 'name',
        cascade: ['all'],
        orphanRemoval: true,
    )]
    public Collection $emails;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH4153EmailNoUnique
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column]
    public string $name;

    #[ORM\ManyToOne(targetEntity: GH4153PersonNoUnique::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
    public GH4153PersonNoUnique|null $owner = null;

    public function __construct(string $name, GH4153PersonNoUnique|null $owner = null)
    {
        $this->name  = $name;
        $this->owner = $owner;
    }
}
