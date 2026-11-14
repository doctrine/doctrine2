<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_keys;
use function spl_object_id;

/**
 * The scheduled-deletion picture of the UnitOfWork has to equal the flush
 * plan (#5615). An element leaving an orphanRemoval collection is already
 * planned for deletion, so the deletion getters answer for it in the
 * before-flush window; an element planned by several maps at once (a
 * materialized orphan inside the onFlush window, a wholesale-discarded
 * element) stays in the picture exactly once; a cancellation through
 * re-adding cancels the picture while persist() without a re-add does not;
 * the picture is empty again after the flush; and without orphanRemoval, or
 * while the element keeps its membership, nothing is scheduled at all.
 */
class GH5615Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema([
            GH5615User::class,
            GH5615Comment::class,
            GH5615UserNoOrphanRemoval::class,
            GH5615CommentNoOrphanRemoval::class,
        ]);
    }

    #[Group('gh5615-before-flush-mutation-orphan')]
    public function testBeforeFlushMutationOrphanIsScheduledForDelete(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('first');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $commentId = $comment->id;

        // Orphan the element; no flush yet.
        $user->comments->removeElement($comment);

        $uow = $this->_em->getUnitOfWork();

        self::assertTrue($uow->isScheduledForDelete($comment));

        // And the flush indeed deletes the row.
        $this->_em->flush();
        $this->_em->clear();

        self::assertNull($this->_em->find(GH5615Comment::class, $commentId));
    }

    #[Group('gh5615-before-flush-list-getter')]
    public function testBeforeFlushListGetterIncludesMutationOrphan(): void
    {
        $user     = new GH5615User();
        $orphaned = new GH5615Comment('orphaned');
        $removed  = new GH5615Comment('removed');
        $user->comments->add($orphaned);
        $user->comments->add($removed);
        $orphaned->user = $user;
        $removed->user  = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        // Orphan one element and explicitly remove the other; no flush
        // in between, so both plan members are visible at once.
        $user->comments->removeElement($orphaned);
        $this->_em->remove($removed);

        $deletions = $this->_em->getUnitOfWork()->getScheduledEntityDeletions();

        self::assertArrayHasKey(spl_object_id($orphaned), $deletions);
        self::assertSame($orphaned, $deletions[spl_object_id($orphaned)]);
        self::assertCount(2, $deletions);

        // Explicitly removed entities keep their place ahead of the
        // mutation orphans in the returned plan (union order).
        self::assertSame(
            [spl_object_id($removed), spl_object_id($orphaned)],
            array_keys($deletions),
        );
    }

    #[Group('gh5615-readd-cancel')]
    public function testReAddedElementIsNoLongerScheduledForDelete(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('first');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $commentId = $comment->id;

        $uow = $this->_em->getUnitOfWork();

        $user->comments->removeElement($comment);

        self::assertTrue($uow->isScheduledForDelete($comment));

        // Re-adding the element cancels the orphan removal.
        $user->comments->add($comment);

        self::assertFalse($uow->isScheduledForDelete($comment));

        $this->_em->flush();
        $this->_em->clear();

        // The row survives the flush.
        self::assertNotNull($this->_em->find(GH5615Comment::class, $commentId));
    }

    #[Group('gh5615-persist-no-cancel')]
    public function testPersistWithoutReAddKeepsOrphanScheduledForDelete(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('first');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $commentId = $comment->id;

        $uow = $this->_em->getUnitOfWork();

        $user->comments->removeElement($comment);

        self::assertTrue($uow->isScheduledForDelete($comment));

        // persist() does not cancel an orphan removal planned by a mutation.
        $this->_em->persist($comment);

        self::assertTrue($uow->isScheduledForDelete($comment));

        $this->_em->flush();
        $this->_em->clear();

        // The planned removal is executed.
        self::assertNull($this->_em->find(GH5615Comment::class, $commentId));
    }

    /**
     * The second level cache collection hydrator cannot resolve the
     * identifier of an explicitly removed element still held by the
     * collection, so this scenario runs outside the cache-able profile.
     */
    #[Group('gh5615-onflush-window')]
    #[Group('non-cacheable')]
    public function testOnFlushWindowCountsEachPlannedDeletionOnce(): void
    {
        $user     = new GH5615User();
        $orphaned = new GH5615Comment('orphaned');
        $removed  = new GH5615Comment('removed');
        $user->comments->add($orphaned);
        $orphaned->user = $user;
        $user->comments->add($removed);
        $removed->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        // A mutation orphan and an explicit remove are both planned.
        $user->comments->removeElement($orphaned);
        $this->_em->remove($removed);

        $listener = new GH5615OnFlushSnapshotListener($orphaned, $removed);
        $this->_em->getEventManager()->addEventListener(Events::onFlush, $listener);

        $this->_em->flush();

        // Inside the onFlush window both entities are in the picture: the
        // materialized orphan holds a membership in two maps at once, the
        // explicitly removed entity in one — each counted exactly once.
        self::assertTrue($listener->scheduled[spl_object_id($orphaned)]);
        self::assertTrue($listener->scheduled[spl_object_id($removed)]);
        self::assertCount(1, array_keys($listener->deletions, $orphaned, true));
        self::assertCount(1, array_keys($listener->deletions, $removed, true));
    }

    #[Group('gh5615-wholesale-picture')]
    public function testWholesaleReplacementPictureCountsDiscardedElementOnce(): void
    {
        $user    = new GH5615User();
        $dropped = new GH5615Comment('dropped');
        $kept    = new GH5615Comment('kept');
        $user->comments->add($dropped);
        $dropped->user = $user;
        $user->comments->add($kept);
        $kept->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $userId    = $user->id;
        $droppedId = $dropped->id;
        $keptId    = $kept->id;

        $this->_em->clear();
        $user    = $this->_em->find(GH5615User::class, $userId);
        $dropped = $this->_em->find(GH5615Comment::class, $droppedId);
        $kept    = $this->_em->find(GH5615Comment::class, $keptId);

        $new       = new GH5615Comment('new');
        $new->user = $user;

        $listener = new GH5615OnFlushSnapshotListener($dropped);
        $this->_em->getEventManager()->addEventListener(Events::onFlush, $listener);

        $user->comments = new ArrayCollection([$kept, $new]);

        $this->_em->flush();
        $this->_em->clear();

        // The wholesale-discarded element is planned by three maps at once
        // and still appears in the picture exactly once.
        self::assertCount(1, array_keys($listener->deletions, $dropped, true));

        self::assertNull($this->_em->find(GH5615Comment::class, $droppedId));
        self::assertNotNull($this->_em->find(GH5615Comment::class, $keptId));
        self::assertNotNull($this->_em->find(GH5615Comment::class, $new->id));
    }

    #[Group('gh5615-post-flush-empty')]
    public function testPostFlushDeletionPictureIsEmptyForFlushedOrphan(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('first');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $user->comments->removeElement($comment);

        $this->_em->flush();

        $uow = $this->_em->getUnitOfWork();

        self::assertFalse($uow->isScheduledForDelete($comment));
        self::assertArrayNotHasKey(spl_object_id($comment), $uow->getScheduledEntityDeletions());
    }

    #[Group('gh5615-contains-shift')]
    public function testContainsReturnsFalseForBeforeFlushOrphan(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('first');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        // Orphan the element; no flush yet.
        $user->comments->removeElement($comment);

        self::assertFalse($this->_em->contains($comment));
    }

    #[Group('gh5615-no-orphan-removal')]
    public function testNoOrphanRemovalSchedulesNothingForRemovedElement(): void
    {
        $user    = new GH5615UserNoOrphanRemoval();
        $comment = new GH5615CommentNoOrphanRemoval('plain');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $user->comments->removeElement($comment);

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForDelete($comment));
    }

    #[Group('gh5615-membership-guard-kept')]
    public function testMembershipGuardKeepsStillMemberElementUnscheduled(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('kept-member');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        $userId    = $user->id;
        $commentId = $comment->id;

        $this->_em->clear();
        $user    = $this->_em->find(GH5615User::class, $userId);
        $comment = $user->comments->first();

        // first() initializes the collection, so the duplicated add() puts
        // the same element under a second key ...
        $user->comments->add($comment);

        // ... and removing the first occurrence leaves the element a member.
        $user->comments->remove(0);

        self::assertFalse($this->_em->getUnitOfWork()->isScheduledForDelete($comment));

        $this->_em->flush();
        $this->_em->clear();

        // The member survives the flush.
        self::assertNotNull($this->_em->find(GH5615Comment::class, $commentId));
    }

    #[Group('gh5615-isentityscheduled')]
    public function testIsEntityScheduledSeesOrphanRemovals(): void
    {
        $user    = new GH5615User();
        $comment = new GH5615Comment('first');
        $user->comments->add($comment);
        $comment->user = $user;

        $this->_em->persist($user);
        $this->_em->flush();

        // Orphan the element; no flush yet.
        $user->comments->removeElement($comment);

        self::assertTrue($this->_em->getUnitOfWork()->isEntityScheduled($comment));
    }
}

class GH5615OnFlushSnapshotListener
{
    /** @var array<int, object> */
    public array $deletions = [];

    /** @var array<int, bool> */
    public array $scheduled = [];

    /** @var list<object> */
    private readonly array $watched;

    public function __construct(object ...$watched)
    {
        $this->watched = $watched;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $this->deletions = $uow->getScheduledEntityDeletions();

        foreach ($this->watched as $entity) {
            $this->scheduled[spl_object_id($entity)] = $uow->isScheduledForDelete($entity);
        }
    }
}

#[ORM\Entity]
class GH5615User
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH5615Comment> */
    #[ORM\OneToMany(
        targetEntity: GH5615Comment::class,
        mappedBy: 'user',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    public Collection $comments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH5615Comment
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $text;

    #[ORM\ManyToOne(targetEntity: GH5615User::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    public GH5615User|null $user = null;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}

#[ORM\Entity]
class GH5615UserNoOrphanRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, GH5615CommentNoOrphanRemoval> */
    #[ORM\OneToMany(
        targetEntity: GH5615CommentNoOrphanRemoval::class,
        mappedBy: 'user',
        cascade: ['persist'],
    )]
    public Collection $comments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
    }
}

#[ORM\Entity]
class GH5615CommentNoOrphanRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    public string $text;

    #[ORM\ManyToOne(targetEntity: GH5615UserNoOrphanRemoval::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    public GH5615UserNoOrphanRemoval|null $user = null;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}
