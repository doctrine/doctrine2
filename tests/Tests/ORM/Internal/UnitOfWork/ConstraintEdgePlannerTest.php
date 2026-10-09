<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\UnitOfWork;

use Doctrine\ORM\Internal\UnitOfWork\ConstraintEdgePlanner;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function spl_object_id;

#[CoversClass(ConstraintEdgePlanner::class)]
#[Group('#6776')]
final class ConstraintEdgePlannerTest extends TestCase
{
    public function testUniqueFieldCollisionProducesEarlyDeletion(): void
    {
        $old    = new PlannerItem();
        $old->f = 'v';
        $new    = new PlannerItem();
        $new->f = 'v';

        self::assertSame([$old], $this->plan([$old], [$new]));
    }

    public function testNullTupleComponentNeverCollides(): void
    {
        $old    = new PlannerItem();
        $old->f = null;
        $new    = new PlannerItem();
        $new->f = null;

        self::assertSame([], $this->plan([$old], [$new]));
    }

    #[Group('planner-mixed-composite-unit')]
    public function testMixedCompositeTableUniqueConstraintCollisionProducesEarlyDeletion(): void
    {
        $owner = new PlannerItem();

        $old        = new PlannerItem();
        $old->f     = 'v';
        $old->owner = $owner;
        $new        = new PlannerItem();
        $new->f     = 'v';
        $new->owner = $owner;

        self::assertSame([$old], $this->plan([$old], [$new], [], [], [], [], [], [
            PlannerItem::class => $this->mixedCompositeItemMetadata(),
        ]));
    }

    #[Group('planner-mixed-composite-unit')]
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

        self::assertSame([], $this->plan([$old], [$new], [], [], [], [], [], [
            PlannerItem::class => $this->mixedCompositeItemMetadata(),
        ]));
    }

    #[Group('planner-mixed-composite-unit')]
    public function testUniqueJoinColumnCollisionByReferencedEntityIdentity(): void
    {
        $ref = new PlannerRef();

        $old      = new PlannerItem();
        $old->ref = $ref;
        $new      = new PlannerItem();
        $new->ref = $ref;

        self::assertSame([$old], $this->plan([$old], [$new]));
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

        self::assertSame([], $this->plan([$old], [$new, $referencing]));
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
            [],
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
            [spl_object_id($updater) => ['owner' => $old]],
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
            [],
            [],
            [PlannerItem::class => true],
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
     * @param list<object>                                         $candidates
     * @param array<int, object>                                   $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     * @param array<string, true>                                  $manyToManyTargetClasses
     * @param array<string, ClassMetadata<object>>                 $metadataOverrides
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

        $planner = new ConstraintEdgePlanner(
            static fn (string $class): ClassMetadata => $metadata[$class],
        );

        return $planner->planEarlyDeletions(
            $candidates,
            $insertions,
            $updates,
            $candidateDeletions + $deletions,
            $originalEntityData,
            $entityChangeSets,
            $manyToManyTargetClasses,
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
