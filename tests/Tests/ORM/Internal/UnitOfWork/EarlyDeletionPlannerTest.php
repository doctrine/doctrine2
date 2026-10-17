<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\UnitOfWork;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Internal\UnitOfWork\EarlyDeletionPlanner;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\Tests\DbalTypes\EarlyDeletionThrowingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function count;
use function spl_object_id;

#[CoversClass(EarlyDeletionPlanner::class)]
#[Group('#6776')]
final class EarlyDeletionPlannerTest extends TestCase
{
    public function testUniqueFieldCollisionProducesEarlyDeletion(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';
        $new    = new PlannerItem();
        $new->f = 'v';

        // The candidate is compared against its original entity data (the
        // snapshot of its database row): the fixtures carry it explicitly.
        self::assertSame([$old], $this->plan([$old], [$new], [], [], [spl_object_id($old) => ['f' => 'v']]));
    }

    public function testNullTupleComponentNeverCollides(): void
    {
        $old    = new PlannerItem();
        $old->f = null;
        $new    = new PlannerItem();
        $new->f = null;

        self::assertSame([], $this->plan([$old], [$new]));
    }

    public function testMixedCompositeTableUniqueConstraintCollisionProducesEarlyDeletion(): void
    {
        $owner = new PlannerItem();

        $old        = new PlannerItem();
        $old->f     = 'v';
        $old->owner = $owner;
        $new        = new PlannerItem();
        $new->f     = 'v';
        $new->owner = $owner;

        self::assertSame([$old], $this->plan([$old], [$new], [], [], [spl_object_id($old) => ['f' => 'v', 'owner' => $owner]], [], [], [
            PlannerItem::class => $this->mixedCompositeItemMetadata(),
        ]));
    }

    public function testMixedCompositeTableUniqueConstraintWithDifferentOwnersDoesNotCollide(): void
    {
        $firstOwner  = new PlannerItem();
        $secondOwner = new PlannerItem();

        $old        = new PlannerItem();
        $old->f     = 'v';
        $old->owner = $firstOwner;
        $new        = new PlannerItem();
        $new->f     = 'v';
        $new->owner = $secondOwner;

        self::assertSame([], $this->plan([$old], [$new], [], [], [spl_object_id($old) => ['f' => 'v', 'owner' => $firstOwner]], [], [], [
            PlannerItem::class => $this->mixedCompositeItemMetadata(),
        ]));
    }

    public function testUniqueJoinColumnCollisionByReferencedEntityIdentity(): void
    {
        $ref = new PlannerRef();

        $old      = new PlannerItem();
        $old->ref = $ref;
        $new      = new PlannerItem();
        $new->ref = $ref;

        self::assertSame([$old], $this->plan([$old], [$new], [], [], [spl_object_id($old) => ['ref' => $ref]]));
    }

    public function testUniqueJoinColumnWithNullAssociationDoesNotCollide(): void
    {
        $old = new PlannerItem();
        $new = new PlannerItem();

        self::assertSame([], $this->plan([$old], [$new]));
    }

    public function testCandidateWithoutInsertionsStaysOnBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        self::assertSame([], $this->plan([$old], []));
    }

    public function testFkReferenceFromInsertFallsBackToBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        $new    = new PlannerItem();
        $new->f = 'v';

        $referencing        = new PlannerItem();
        $referencing->f     = 'w';
        $referencing->owner = $old;

        self::assertSame([], $this->plan([$old], [$new, $referencing], [], [], [spl_object_id($old) => ['f' => 'v']]));
    }

    public function testFkReferenceFromUpdateOriginalValueFallsBackToBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        $new    = new PlannerItem();
        $new->f = 'v';

        $updater    = new PlannerItem();
        $updater->f = 'w';

        self::assertSame([], $this->plan(
            [$old],
            [$new],
            [spl_object_id($updater) => $updater],
            [],
            [spl_object_id($old) => ['f' => 'v']],
            [spl_object_id($updater) => ['owner' => [$old, null]]],
        ));
    }

    public function testFkReferenceFromUpdateOriginalEntityDataFallsBackToBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        $new    = new PlannerItem();
        $new->f = 'v';

        $updater    = new PlannerItem();
        $updater->f = 'w';

        self::assertSame([], $this->plan(
            [$old],
            [$new],
            [spl_object_id($updater) => $updater],
            [],
            [spl_object_id($old) => ['f' => 'v'], spl_object_id($updater) => ['owner' => $old]],
        ));
    }

    public function testFkReferenceFromPendingDeletionCurrentValueFallsBackToBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        $new    = new PlannerItem();
        $new->f = 'v';

        $deleter        = new PlannerItem();
        $deleter->f     = 'w';
        $deleter->owner = $old;

        self::assertSame([], $this->plan(
            [$old],
            [$new],
            [],
            [spl_object_id($deleter) => $deleter],
            [spl_object_id($old) => ['f' => 'v']],
        ));
    }

    public function testFkReferenceFromPendingManyToManyCollectionOperationFallsBackToBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        $new    = new PlannerItem();
        $new->f = 'v';

        self::assertSame([], $this->plan(
            [$old],
            [$new],
            [],
            [],
            [spl_object_id($old) => ['f' => 'v']],
            [],
            [PlannerItem::class => true],
        ));
    }

    public function testFkReferenceFromPendingManyToManyOwnerFallsBackToBaselineOrder(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';

        $new    = new PlannerItem();
        $new->f = 'v';

        self::assertSame([], $this->plan(
            [$old],
            [$new],
            [],
            [],
            [spl_object_id($old) => ['f' => 'v']],
            [],
            [],
            [],
            [spl_object_id($old) => true],
        ));
    }

    public function testCollisionIsOnlyMatchedWithinSameClass(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';
        $new    = new PlannerOther();
        $new->f = 'v';

        self::assertSame([], $this->plan([$old], [$new]));
    }

    /**
     * A component that has no database representation — a value the
     * converter rejects or a converter that throws — floors the comparison
     * of that candidate against that declaration: the planner never throws
     * and the candidate stays on the baseline order.
     */
    public function testUnconvertibleComponentSkipsDeclaration(): void
    {
        if (! Type::hasType(EarlyDeletionThrowingType::NAME)) {
            Type::addType(EarlyDeletionThrowingType::NAME, EarlyDeletionThrowingType::class);
        }

        // A converter that throws on every value.
        $throwing     = new PlannerBag();
        $throwing->v  = 'anything';
        $insertion    = new PlannerBag();
        $insertion->v = 'zzz';

        self::assertSame([], $this->plan(
            [$throwing],
            [$insertion],
            [],
            [],
            [spl_object_id($throwing) => ['v' => 'anything']],
            [],
            [],
            [PlannerBag::class => $this->bagMetadata(EarlyDeletionThrowingType::NAME)],
        ));

        // A value the conversion cannot represent (the string conversion
        // returns the closure as is): a non-scalar conversion result.
        $closure    = static fn (): string => 'unreachable';
        $opaque     = new PlannerBag();
        $opaque->v  = 'anything';
        $insertion2 = new PlannerBag();

        $insertion2->v = $closure;

        self::assertSame([], $this->plan(
            [$opaque],
            [$insertion2],
            [],
            [],
            [spl_object_id($opaque) => ['v' => 'anything']],
            [],
            [],
            [PlannerBag::class => $this->bagMetadata('string')],
        ));
    }

    /**
     * A table-level unique constraint declared in the fields form is
     * consumed like the same constraint declared in the columns form: both
     * forms produce identical declarations and therefore identical
     * collision decisions.
     */
    public function testFieldsAndColumnsFormsProduceIdenticalDeclarations(): void
    {
        $owner = new PlannerItem();

        $old        = new PlannerItem();
        $old->f     = 'v';
        $old->owner = $owner;
        $new        = new PlannerItem();
        $new->f     = 'v';
        $new->owner = $owner;

        $firstOwner      = new PlannerItem();
        $secondOwner     = new PlannerItem();
        $oldOther        = new PlannerItem();
        $oldOther->f     = 'v';
        $oldOther->owner = $firstOwner;
        $newOther        = new PlannerItem();
        $newOther->f     = 'v';
        $newOther->owner = $secondOwner;

        $snapshot = static function (PlannerItem $entity): array {
            return [spl_object_id($entity) => ['f' => 'v', 'owner' => $entity->owner]];
        };

        $columnsForm = ['columns' => ['owner_id', 'f']];
        $fieldsForm  = ['fields' => ['owner', 'f']];

        // Same owner: both forms detect the collision.
        self::assertSame(
            $this->plan([$old], [$new], [], [], $snapshot($old), [], [], [PlannerItem::class => $this->compositeItemMetadata($columnsForm)]),
            $this->plan([$old], [$new], [], [], $snapshot($old), [], [], [PlannerItem::class => $this->compositeItemMetadata($fieldsForm)]),
        );

        // Different owners: neither form detects a collision.
        self::assertSame(
            $this->plan([$oldOther], [$newOther], [], [], $snapshot($oldOther), [], [], [PlannerItem::class => $this->compositeItemMetadata($columnsForm)]),
            $this->plan([$oldOther], [$newOther], [], [], $snapshot($oldOther), [], [], [PlannerItem::class => $this->compositeItemMetadata($fieldsForm)]),
        );
    }

    /**
     * The insertion index is built only over the candidate classes: when no
     * candidate class declares a unique tuple, the planner returns before
     * resolving any insertion, and a foreign insertion class is resolved
     * once (for the foreign-key guard) but never for the index.
     */
    public function testIndexBuildTouchesOnlyCandidateClasses(): void
    {
        $metadata = [
            PlannerItem::class => $this->itemMetadata(),
            PlannerOther::class => $this->otherMetadata(),
            PlannerRef::class => new ClassMetadata(PlannerRef::class),
        ];

        // Without candidate declarations: early exit, no insertion resolved.
        $resolutions = [];
        $planner     = new EarlyDeletionPlanner(
            static function (string $class) use (&$resolutions, $metadata): ClassMetadata {
                $resolutions[] = $class;

                return $metadata[$class];
            },
            new SQLitePlatform(),
        );

        $ref          = new PlannerRef();
        $insertion    = new PlannerItem();
        $insertion->f = 'v';
        $foreign      = new PlannerOther();
        $foreign->f   = 'v';

        self::assertSame([], $planner->planEarlyDeletions([$ref], [$insertion, $foreign], [], [], [], [], [], []));
        self::assertSame([PlannerRef::class], $resolutions);

        // With a candidate declaration: the foreign class is resolved once,
        // by the foreign-key guard only — the index build skipped it.
        $resolutions = [];
        $planner     = new EarlyDeletionPlanner(
            static function (string $class) use (&$resolutions, $metadata): ClassMetadata {
                $resolutions[] = $class;

                return $metadata[$class];
            },
            new SQLitePlatform(),
        );

        $candidate    = new PlannerItem();
        $candidate->f = 'v';
        $colliding    = new PlannerItem();
        $colliding->f = 'v';

        self::assertSame(
            [$candidate],
            $planner->planEarlyDeletions(
                [$candidate],
                [$colliding, $foreign],
                [],
                [],
                [spl_object_id($candidate) => ['f' => 'v']],
                [],
                [],
                [],
            ),
        );
        self::assertSame(1, count(array_keys($resolutions, PlannerOther::class, true)));
    }

    /**
     * @param list<object>                                         $candidates
     * @param array<int, object>                                   $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     * @param array<string, true>                                  $manyToManyTargetClasses
     * @param array<string, ClassMetadata<object>>                 $metadataOverrides
     * @param array<int, true>                                     $manyToManyOwnerIds
     *
     * @return list<object>
     */
    private function plan(
        array $candidates,
        array $insertions,
        array $updates = [],
        array $deletions = [],
        array $originalEntityData = [],
        array $entityChangeSets = [],
        array $manyToManyTargetClasses = [],
        array $metadataOverrides = [],
        array $manyToManyOwnerIds = [],
    ): array {
        $metadata = [
            PlannerItem::class => $this->itemMetadata(),
            PlannerRef::class => new ClassMetadata(PlannerRef::class),
            PlannerOther::class => $this->otherMetadata(),
            ...$metadataOverrides,
        ];

        $candidateDeletions = [];
        foreach ($candidates as $entity) {
            $candidateDeletions[spl_object_id($entity)] = $entity;
        }

        $planner = new EarlyDeletionPlanner(
            static fn (string $class): ClassMetadata => $metadata[$class],
            new SQLitePlatform(),
        );

        return $planner->planEarlyDeletions(
            $candidates,
            $insertions,
            $updates,
            $candidateDeletions + $deletions,
            $originalEntityData,
            $entityChangeSets,
            $manyToManyTargetClasses,
            $manyToManyOwnerIds,
        );
    }

    private function itemMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerItem::class);

        $this->addField($metadata, 'f', true);
        $this->addField($metadata, 'a');
        $this->addField($metadata, 'b');

        $this->addManyToOne($metadata, 'owner', PlannerItem::class);
        $this->addManyToOne($metadata, 'ref', PlannerRef::class, true);

        $metadata->table['uniqueConstraints']['ab_uniq'] = ['columns' => ['a', 'b']];

        return $metadata;
    }

    private function otherMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerOther::class);

        $this->addField($metadata, 'f', true);

        return $metadata;
    }

    /**
     * Metadata with a single table-level unique constraint over the owner
     * association and the field 'f', in either the columns or the fields
     * form.
     *
     * @param array{columns?: list<string>, fields?: list<string>} $constraint
     */
    private function compositeItemMetadata(array $constraint): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerItem::class);

        $this->addField($metadata, 'f');

        $this->addManyToOne($metadata, 'owner', PlannerItem::class);

        $metadata->table['uniqueConstraints']['owner_f_uniq'] = $constraint;

        return $metadata;
    }

    /**
     * Metadata with a single unique field of the given DBAL type.
     */
    private function bagMetadata(string $type): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerBag::class);

        $metadata->fieldMappings['v']     = FieldMapping::fromMappingArray([
            'type' => $type,
            'fieldName' => 'v',
            'columnName' => 'v',
            'unique' => true,
        ]);
        $metadata->fieldNames['v']        = 'v';
        $metadata->propertyAccessors['v'] = PropertyAccessorFactory::createPropertyAccessor(PlannerBag::class, 'v');

        return $metadata;
    }

    /**
     * Metadata for the mixed composite shape of #4153: a table-level unique
     * constraint spanning the join column of the owning side ('owner_id')
     * and a plain field ('f').
     */
    private function mixedCompositeItemMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerItem::class);

        $this->addField($metadata, 'f');

        $this->addManyToOne($metadata, 'owner', PlannerItem::class);

        $metadata->table['uniqueConstraints']['owner_f_uniq'] = ['columns' => ['owner_id', 'f']];

        return $metadata;
    }

    private function addField(ClassMetadata $metadata, string $name, bool $unique = false): void
    {
        $class = $metadata->name;

        $metadata->fieldMappings[$name]     = FieldMapping::fromMappingArray([
            'type' => 'string',
            'fieldName' => $name,
            'columnName' => $name,
            'unique' => $unique ? true : null,
        ]);
        $metadata->fieldNames[$name]        = $name;
        $metadata->propertyAccessors[$name] = PropertyAccessorFactory::createPropertyAccessor($class, $name);
    }

    private function addManyToOne(ClassMetadata $metadata, string $name, string $targetClass, bool $uniqueJoinColumn = false): void
    {
        $metadata->associationMappings[$name] = ManyToOneAssociationMapping::fromMappingArray([
            'fieldName' => $name,
            'sourceEntity' => $metadata->name,
            'targetEntity' => $targetClass,
            'joinColumns' => [
                [
                    'name' => $name . '_id',
                    'referencedColumnName' => 'id',
                    'unique' => $uniqueJoinColumn ? true : null,
                ],
            ],
        ]);
        $metadata->propertyAccessors[$name]   = PropertyAccessorFactory::createPropertyAccessor($metadata->name, $name);
    }
}

class PlannerItem
{
    public string|null $f          = null;
    public string|null $a          = null;
    public string|null $b          = null;
    public PlannerItem|null $owner = null;
    public PlannerRef|null $ref    = null;
}

class PlannerRef
{
    public int|null $id = null;
}

class PlannerOther
{
    public string|null $f = null;
}

class PlannerBag
{
    public mixed $v = null;
}
