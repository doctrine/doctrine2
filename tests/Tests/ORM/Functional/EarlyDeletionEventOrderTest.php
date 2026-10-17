<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\DbalTypes\EarlyDeletionFoldType;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function assert;

#[Group('EarlyDeletionEventOrderTest')]
class EarlyDeletionEventOrderTest extends OrmFunctionalTestCase
{
    private EarlyDeletionEventOrderRecorder $recorder;

    protected function setUp(): void
    {
        if (! Type::hasType(EarlyDeletionFoldType::NAME)) {
            Type::addType(EarlyDeletionFoldType::NAME, EarlyDeletionFoldType::class);
        }

        parent::setUp();

        $this->recorder = new EarlyDeletionEventOrderRecorder();
        $this->_em->getEventManager()->addEventListener(
            [
                'preRemove',
                'postRemove',
                'prePersist',
                'postPersist',
                'preUpdate',
                'postUpdate',
                'postFlush',
            ],
            $this->recorder,
        );

        $this->createSchemaForModels(
            EarlyDeletionEventOrderItem::class,
            EarlyDeletionEventOrderFoldItem::class,
            EarlyDeletionEventOrderShelf::class,
            EarlyDeletionEventOrderRootItem::class,
            EarlyDeletionEventOrderSubItem::class,
            EarlyDeletionEventOrderRootShelf::class,
            EarlyDeletionEventOrderComposite::class,
        );
    }

    /**
     * A deletion colliding with a pending insertion runs in the early batch:
     * its postRemove listener fires after the batch's DELETE statements and
     * before the insertions' postPersist listeners, while the deletions
     * without a collision stay in the late batch at the end of the flush.
     * The pre-events stay at the call sites (preRemove at remove(),
     * prePersist at persist()).
     */
    public function testTwoBatchPostRemoveOrder(): void
    {
        $sharedOld = new EarlyDeletionEventOrderItem('shared');
        $lonely    = new EarlyDeletionEventOrderItem('lonely');
        $this->_em->persist($sharedOld);
        $this->_em->persist($lonely);
        $this->_em->flush();

        $this->recorder->log = [];

        $newShared = new EarlyDeletionEventOrderItem('shared');

        $this->recorder->probeCode = 'shared';
        $this->_em->remove($sharedOld);
        $this->_em->remove($lonely);
        $this->_em->persist($newShared);
        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:shared',
                'preRemove:lonely',
                'prePersist:shared',
                'postRemove:shared id=null scheduled=false probeRow=0',
                'postPersist:shared',
                'postRemove:lonely id=null scheduled=false probeRow=1',
                'postFlush',
            ],
            $this->recorder->log,
        );
    }

    /**
     * A deletion whose in-memory values no longer match its database row is
     * not a false positive: the candidate is compared against its snapshot,
     * so a modification made before remove() does not reorder the flush
     * when the modified value collides with a pending insertion.
     */
    public function testFalsePositiveTupleKeepsLateOrder(): void
    {
        $old = new EarlyDeletionEventOrderItem('k');
        $this->_em->persist($old);
        $this->_em->flush();

        $this->recorder->log = [];

        $new = new EarlyDeletionEventOrderItem('zzz');

        // Modified before remove(): the database row still holds 'k', so the
        // deletion does not collide with the 'zzz' insertion.
        $old->code = 'zzz';

        $this->_em->remove($old);
        $this->_em->persist($new);
        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:zzz',
                'prePersist:zzz',
                'postPersist:zzz',
                'postRemove:zzz id=null scheduled=false',
                'postFlush',
            ],
            $this->recorder->log,
        );
        self::assertSame(0, $this->countItemRowsByCode('k'));
        self::assertSame($new->id, $this->firstItemIdByCode('zzz'));
    }

    /**
     * A collision hidden by an in-memory modification is still detected: the
     * candidate is compared against its database snapshot, so the deletion
     * runs in the early batch and the flush succeeds.
     */
    public function testHiddenCollisionIsDetectedAndReordered(): void
    {
        $old = new EarlyDeletionEventOrderItem('k');
        $this->_em->persist($old);
        $this->_em->flush();

        $this->recorder->log = [];

        $oldId = $old->id;
        $new   = new EarlyDeletionEventOrderItem('k');

        // Modified before remove(): the snapshot still holds 'k', which
        // collides with the new insertion.
        $old->code = 'zzz';

        $this->_em->remove($old);
        $this->_em->persist($new);
        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:zzz',
                'prePersist:k',
                'postRemove:zzz id=null scheduled=false',
                'postPersist:k',
                'postFlush',
            ],
            $this->recorder->log,
        );
        self::assertSame($new->id, $this->firstItemIdByCode('k'));
        self::assertNull($this->_em->find(EarlyDeletionEventOrderItem::class, $oldId));
    }

    /**
     * A true collision on a custom DBAL type is detected through the
     * database representation: distinct PHP values ('Kept' and 'KEPT') fold
     * to the same database value, the deletion runs in the early batch and
     * the flush succeeds.
     */
    public function testCustomTypeTrueCollisionIsDetectedAndReordered(): void
    {
        $old = new EarlyDeletionEventOrderFoldItem('Kept');
        $this->_em->persist($old);
        $this->_em->flush();

        $this->recorder->log = [];

        $oldId = $old->id;
        $new   = new EarlyDeletionEventOrderFoldItem('KEPT');

        $this->_em->remove($old);
        $this->_em->persist($new);
        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:Kept',
                'prePersist:KEPT',
                'postRemove:Kept id=null scheduled=false',
                'postPersist:KEPT',
                'postFlush',
            ],
            $this->recorder->log,
        );
        self::assertSame(1, $this->countFoldRowsByCode('kept'));
        self::assertNull($this->_em->find(EarlyDeletionEventOrderFoldItem::class, $oldId));
    }

    /**
     * The owner of a pending many-to-many collection update stays on the
     * baseline commit order: the update cannot run against a deleted owner.
     * The flush therefore fails on the unique constraint.
     */
    public function testOwnerOfPendingManyToManyUpdateKeepsBaselineOrder(): void
    {
        $article = new EarlyDeletionEventOrderItem('article');
        $shelf   = new EarlyDeletionEventOrderShelf('shelf1');
        $shelf->articles->add($article);
        $this->_em->persist($article);
        $this->_em->persist($shelf);
        $this->_em->flush();

        $this->recorder->log = [];

        $newShelf = new EarlyDeletionEventOrderShelf('shelf1');

        $this->_em->persist($newShelf);

        // Leaves a pending many-to-many collection update owned by the
        // shelf, and schedules the shelf for orphan removal: the deletion
        // is materialized inside commit(), after the change sets (and the
        // pending collection update) have been computed.
        $shelf->articles->removeElement($article);
        $this->_em->getUnitOfWork()->scheduleOrphanRemoval($shelf);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->_em->flush();
    }

    /**
     * An executed collection deletion does not block the collision fix: its
     * join rows are already gone when the early batch runs, so a candidate
     * that was an element of the deleted collection is resolvable and the
     * flush succeeds.
     */
    public function testExecutedCollectionDeletionDoesNotBlockCollisionFix(): void
    {
        $article = new EarlyDeletionEventOrderItem('artx');
        $shelf   = new EarlyDeletionEventOrderShelf('shelf2');
        $shelf->articles->add($article);
        $this->_em->persist($article);
        $this->_em->persist($shelf);
        $this->_em->flush();

        $this->recorder->log = [];

        $articleId = $article->id;
        $new       = new EarlyDeletionEventOrderItem('artx');

        // Deletes the complete collection (all join rows) in this flush.
        $shelf->articles->clear();

        $this->_em->remove($article);
        $this->_em->persist($new);
        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:artx',
                'prePersist:artx',
                'postRemove:artx id=null scheduled=false',
                'postPersist:artx',
                'postFlush',
            ],
            $this->recorder->log,
        );
        self::assertSame($new->id, $this->firstItemIdByCode('artx'));
        self::assertNull($this->_em->find(EarlyDeletionEventOrderItem::class, $articleId));
    }

    /**
     * A candidate that is an instance of a subclass of a many-to-many
     * collection's target entity does not crash the flush: the element match
     * is closed over the target entity's subclasses, the deletion stays on
     * the baseline order and the flush fails on the unique constraint.
     */
    public function testSubclassElementOfRootManyToManyDoesNotCrash(): void
    {
        $sub       = new EarlyDeletionEventOrderSubItem('sub1');
        $rootShelf = new EarlyDeletionEventOrderRootShelf();
        $rootShelf->rootItems->add($sub);
        $this->_em->persist($sub);
        $this->_em->persist($rootShelf);
        $this->_em->flush();

        $newSub = new EarlyDeletionEventOrderSubItem('sub1');

        $this->_em->remove($sub);
        $this->_em->persist($newSub);

        // Leaves a pending many-to-many collection update over the root
        // target entity, with the removed subclass instance as its element.
        $rootShelf->rootItems->removeElement($sub);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->_em->flush();
    }

    /**
     * A table-level unique constraint declared in the fields form (spanning
     * a field and an association) is consumed: the collision on the pair is
     * detected and the deletion runs in the early batch.
     */
    public function testFieldsFormConstraintIsConsumed(): void
    {
        $owner = new EarlyDeletionEventOrderItem('owner');
        $old   = new EarlyDeletionEventOrderComposite('c1', $owner);
        $this->_em->persist($owner);
        $this->_em->persist($old);
        $this->_em->flush();

        $this->recorder->log = [];

        $oldId = $old->id;
        $new   = new EarlyDeletionEventOrderComposite('c1', $owner);

        $this->_em->remove($old);
        $this->_em->persist($new);
        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:c1',
                'prePersist:c1',
                'postRemove:c1 id=null scheduled=false',
                'postPersist:c1',
                'postFlush',
            ],
            $this->recorder->log,
        );
        self::assertNull($this->_em->find(EarlyDeletionEventOrderComposite::class, $oldId));
        self::assertSame(
            [$new->id],
            $this->_em->getConnection()->fetchFirstColumn(
                'SELECT id FROM early_deletion_event_order_composite',
            ),
        );
    }

    /**
     * The many-to-many element match is conservative: an unrelated pending
     * collection update of the same element class keeps the candidate on the
     * baseline order, so the flush fails on the unique constraint even
     * though the pending operation does not touch the candidate.
     */
    public function testUnrelatedManyToManyConservatismKeepsBaselineOrder(): void
    {
        $candidate = new EarlyDeletionEventOrderItem('dup');
        $other     = new EarlyDeletionEventOrderItem('other1');
        $shelf     = new EarlyDeletionEventOrderShelf('shelf3');
        $shelf->articles->add($other);
        $this->_em->persist($candidate);
        $this->_em->persist($other);
        $this->_em->persist($shelf);
        $this->_em->flush();

        $this->recorder->log = [];

        $newCandidate = new EarlyDeletionEventOrderItem('dup');

        $this->_em->remove($candidate);
        $this->_em->persist($newCandidate);

        // An unrelated pending many-to-many collection update over the same
        // element class: it does not touch the candidate, but the class-wide
        // match keeps the deletion on the baseline order.
        $shelf->articles->removeElement($other);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->_em->flush();
    }

    /**
     * A flush without any collision keeps the late order for all deletions
     * and the full event sequence: preRemove at remove(), prePersist at
     * persist(), postPersist before the update events, postRemove last
     * before postFlush.
     */
    public function testFlushWithoutCollisionKeepsLateOrder(): void
    {
        $updatable       = new EarlyDeletionEventOrderItem('updatable');
        $updatable->name = 'before';
        $gone            = new EarlyDeletionEventOrderItem('gone');
        $this->_em->persist($updatable);
        $this->_em->persist($gone);
        $this->_em->flush();

        $this->recorder->log = [];

        $goneId = $gone->id;
        $fresh  = new EarlyDeletionEventOrderItem('fresh');

        $this->_em->remove($gone);
        $this->_em->persist($fresh);
        $updatable->name = 'after';

        $this->_em->flush();

        self::assertSame(
            [
                'preRemove:gone',
                'prePersist:fresh',
                'postPersist:fresh',
                'preUpdate:updatable',
                'postUpdate:updatable',
                'postRemove:gone id=null scheduled=false',
                'postFlush',
            ],
            $this->recorder->log,
        );
        self::assertNull($this->_em->find(EarlyDeletionEventOrderItem::class, $goneId));
    }

    private function firstItemIdByCode(string $code): int|null
    {
        $row = $this->_em->getConnection()->fetchAssociative(
            'SELECT id FROM early_deletion_event_order_item WHERE code = ?',
            [$code],
        );

        return $row === false ? null : (int) $row['id'];
    }

    private function countItemRowsByCode(string $code): int
    {
        $count = $this->_em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM early_deletion_event_order_item WHERE code = ?',
            [$code],
        );

        return (int) $count;
    }

    private function countFoldRowsByCode(string $code): int
    {
        $count = $this->_em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM early_deletion_event_order_fold_item WHERE code = ?',
            [$code],
        );

        return (int) $count;
    }
}

final class EarlyDeletionEventOrderRecorder
{
    /** @var list<string> */
    public array $log = [];

    public string|null $probeCode = null;

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->log[] = 'preRemove:' . $this->label($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $em     = $args->getObjectManager();
        assert($em instanceof EntityManagerInterface);

        $entry = 'postRemove:' . $this->label($entity)
            . ' id=' . self::idOf($entity)
            . ' scheduled=' . self::bool($em->getUnitOfWork()->isScheduledForDelete($entity));

        if ($this->probeCode !== null) {
            $class  = $em->getClassMetadata($entity::class);
            $count  = $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM ' . $class->getTableName() . ' WHERE ' . $class->getColumnName('code') . ' = ?',
                [$this->probeCode],
            );
            $entry .= ' probeRow=' . $count;
        }

        $this->log[] = $entry;
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->log[] = 'prePersist:' . $this->label($args->getObject());
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->log[] = 'postPersist:' . $this->label($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->log[] = 'preUpdate:' . $this->label($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->log[] = 'postUpdate:' . $this->label($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->log[] = 'postFlush';
    }

    private function label(object $entity): string
    {
        if ($entity instanceof EarlyDeletionEventOrderItem) {
            return $entity->code;
        }

        if ($entity instanceof EarlyDeletionEventOrderFoldItem) {
            return $entity->code;
        }

        if ($entity instanceof EarlyDeletionEventOrderComposite) {
            return $entity->code;
        }

        if ($entity instanceof EarlyDeletionEventOrderShelf) {
            return $entity->code;
        }

        return '<unknown>';
    }

    private static function idOf(object $entity): string
    {
        if ($entity instanceof EarlyDeletionEventOrderItem) {
            return $entity->id === null ? 'null' : (string) $entity->id;
        }

        if ($entity instanceof EarlyDeletionEventOrderFoldItem) {
            return $entity->id === null ? 'null' : (string) $entity->id;
        }

        if ($entity instanceof EarlyDeletionEventOrderComposite) {
            return $entity->id === null ? 'null' : (string) $entity->id;
        }

        return 'null';
    }

    private static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'early_deletion_event_order_item')]
class EarlyDeletionEventOrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(unique: true)]
    public string $code;

    #[ORM\Column(type: 'string', nullable: true)]
    public string|null $name = null;

    public function __construct(string $code)
    {
        $this->code = $code;
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'early_deletion_event_order_fold_item')]
class EarlyDeletionEventOrderFoldItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: EarlyDeletionFoldType::NAME, unique: true)]
    public string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }
}

#[ORM\Entity]
class EarlyDeletionEventOrderShelf
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(unique: true)]
    public string $code;

    /** @var Collection<int, EarlyDeletionEventOrderItem> */
    #[ORM\ManyToMany(targetEntity: EarlyDeletionEventOrderItem::class)]
    #[ORM\JoinTable(name: 'early_deletion_event_order_shelf_item')]
    public Collection $articles;

    public function __construct(string $code)
    {
        $this->code     = $code;
        $this->articles = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'discriminator')]
#[ORM\DiscriminatorMap([
    'root' => EarlyDeletionEventOrderRootItem::class,
    'sub' => EarlyDeletionEventOrderSubItem::class,
])]
class EarlyDeletionEventOrderRootItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(unique: true)]
    public string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }
}

#[ORM\Entity]
class EarlyDeletionEventOrderSubItem extends EarlyDeletionEventOrderRootItem
{
}

#[ORM\Entity]
class EarlyDeletionEventOrderRootShelf
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, EarlyDeletionEventOrderRootItem> */
    #[ORM\ManyToMany(targetEntity: EarlyDeletionEventOrderRootItem::class)]
    #[ORM\JoinTable(name: 'early_deletion_event_order_root_shelf_item')]
    public Collection $rootItems;

    public function __construct()
    {
        $this->rootItems = new ArrayCollection();
    }
}

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'early_deletion_composite_uniq', fields: ['code', 'owner'])]
#[ORM\Table(name: 'early_deletion_event_order_composite')]
class EarlyDeletionEventOrderComposite
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    #[ORM\Column(type: 'string')]
    public string $code;

    #[ORM\ManyToOne(targetEntity: EarlyDeletionEventOrderItem::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false)]
    public EarlyDeletionEventOrderItem $owner;

    public function __construct(string $code, EarlyDeletionEventOrderItem $owner)
    {
        $this->code  = $code;
        $this->owner = $owner;
    }
}
