<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\UnitOfWork;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Internal\UnitOfWork\EarlyDeletionPlanner;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function spl_object_id;

#[CoversClass(EarlyDeletionPlanner::class)]
final class EarlyDeletionPlannerTest extends TestCase
{
    /** @return iterable<string, array{Closure(PlannerItem, PlannerItem): array<string, mixed>, bool}> */
    public static function provideCollisions(): iterable
    {
        yield 'unique field' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $new->f = 'v';

                return ['f' => 'v'];
            },
            true,
        ];

        yield 'unique field compared on the database row of the deletion' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $old->f = 'changed';
                $new->f = 'v';

                return ['f' => 'v'];
            },
            true,
        ];

        yield 'unique field compared as database value' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $new->d = new DateTimeImmutable('2020-01-01 10:00:00');

                return ['d' => new DateTimeImmutable('2020-01-01 10:00:00')];
            },
            true,
        ];

        yield 'unique field with different values' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $new->f = 'w';

                return ['f' => 'v'];
            },
            false,
        ];

        yield 'NULL never collides' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                return ['f' => null];
            },
            false,
        ];

        yield 'unique join column on the same entity' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $ref      = new PlannerRef();
                $new->ref = $ref;

                return ['ref' => $ref];
            },
            true,
        ];

        yield 'unique join column on different entities' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $new->ref = new PlannerRef();

                return ['ref' => new PlannerRef()];
            },
            false,
        ];

        yield 'table constraint in columns form over a join column and a field' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $owner      = new PlannerRef();
                $new->owner = $owner;
                $new->a     = 'v';

                return ['owner' => $owner, 'a' => 'v'];
            },
            true,
        ];

        yield 'table constraint in fields form over an association and a field' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $owner      = new PlannerRef();
                $new->owner = $owner;
                $new->b     = 'v';

                return ['owner' => $owner, 'b' => 'v'];
            },
            true,
        ];

        yield 'table constraint with a different owner' => [
            static function (PlannerItem $old, PlannerItem $new): array {
                $new->owner = new PlannerRef();
                $new->a     = 'v';

                return ['owner' => new PlannerRef(), 'a' => 'v'];
            },
            false,
        ];
    }

    /** @param Closure(PlannerItem, PlannerItem): array<string, mixed> $setUp */
    #[DataProvider('provideCollisions')]
    public function testCollisionDetection(Closure $setUp, bool $expectEarlyDeletion): void
    {
        $old      = new PlannerItem();
        $new      = new PlannerItem();
        $snapshot = $setUp($old, $new);

        self::assertSame(
            $expectEarlyDeletion ? [$old] : [],
            $this->plan($old, [$new], originalEntityData: [spl_object_id($old) => $snapshot]),
        );
    }

    public function testCollisionIsOnlyMatchedWithinTheSameClass(): void
    {
        $old    = new PlannerItem();
        $new    = new PlannerOther();
        $new->f = 'v';

        self::assertSame([], $this->plan($old, [$new], originalEntityData: [spl_object_id($old) => ['f' => 'v']]));
    }

    /** @return iterable<string, array{Closure(PlannerItem): array<string, mixed>}> */
    public static function provideUnsafeEarlyDeletions(): iterable
    {
        yield 'referenced by a pending insertion' => [
            static function (PlannerItem $old): array {
                $referencing        = new PlannerItem();
                $referencing->other = $old;

                return ['insertions' => [$referencing]];
            },
        ];

        yield 'referenced by the change set of a pending update' => [
            static function (PlannerItem $old): array {
                $updated = new PlannerItem();

                return [
                    'updates' => [spl_object_id($updated) => $updated],
                    'entityChangeSets' => [spl_object_id($updated) => ['other' => [$old, null]]],
                ];
            },
        ];

        yield 'referenced by the original data of a pending update' => [
            static function (PlannerItem $old): array {
                $updated = new PlannerItem();

                return [
                    'updates' => [spl_object_id($updated) => $updated],
                    'originalEntityData' => [spl_object_id($updated) => ['other' => $old]],
                ];
            },
        ];

        yield 'referenced by a pending deletion' => [
            static function (PlannerItem $old): array {
                $deleted        = new PlannerItem();
                $deleted->other = $old;

                return ['deletions' => [spl_object_id($deleted) => $deleted]];
            },
        ];

        yield 'element class of a pending many-to-many update' => [
            static fn (PlannerItem $old): array => ['manyToManyTargetClasses' => [PlannerItem::class => true]],
        ];

        yield 'owner of a pending many-to-many update' => [
            static fn (PlannerItem $old): array => ['manyToManyOwnerIds' => [spl_object_id($old) => true]],
        ];
    }

    /** @param Closure(PlannerItem): array<string, mixed> $setUp */
    #[DataProvider('provideUnsafeEarlyDeletions')]
    public function testUnsafeEarlyDeletionKeepsCommitOrder(Closure $setUp): void
    {
        $old    = new PlannerItem();
        $new    = new PlannerItem();
        $new->f = 'v';

        $pending = $setUp($old);

        self::assertSame([], $this->plan(
            $old,
            [$new, ...$pending['insertions'] ?? []],
            $pending['updates'] ?? [],
            $pending['deletions'] ?? [],
            [spl_object_id($old) => ['f' => 'v']] + ($pending['originalEntityData'] ?? []),
            $pending['entityChangeSets'] ?? [],
            $pending['manyToManyTargetClasses'] ?? [],
            $pending['manyToManyOwnerIds'] ?? [],
        ));
    }

    /**
     * @param list<object>                                         $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     * @param array<string, true>                                  $manyToManyTargetClasses
     * @param array<int, true>                                     $manyToManyOwnerIds
     *
     * @return list<object>
     */
    private function plan(
        PlannerItem $candidate,
        array $insertions,
        array $updates = [],
        array $deletions = [],
        array $originalEntityData = [],
        array $entityChangeSets = [],
        array $manyToManyTargetClasses = [],
        array $manyToManyOwnerIds = [],
    ): array {
        $metadata = [
            PlannerItem::class => self::itemMetadata(),
            PlannerOther::class => self::otherMetadata(),
            PlannerRef::class => new ClassMetadata(PlannerRef::class),
        ];

        $planner = new EarlyDeletionPlanner(
            static fn (string $class): ClassMetadata => $metadata[$class],
            new SQLitePlatform(),
        );

        $indexedInsertions = [];
        foreach ($insertions as $insertion) {
            $indexedInsertions[spl_object_id($insertion)] = $insertion;
        }

        return $planner->planEarlyDeletions(
            [$candidate],
            $indexedInsertions,
            $updates,
            [spl_object_id($candidate) => $candidate] + $deletions,
            $originalEntityData,
            $entityChangeSets,
            $manyToManyTargetClasses,
            $manyToManyOwnerIds,
        );
    }

    private static function itemMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerItem::class);

        self::addField($metadata, 'f', 'string', true);
        self::addField($metadata, 'd', 'datetime_immutable', true);
        self::addField($metadata, 'a', 'string');
        self::addField($metadata, 'b', 'string');
        self::addManyToOne($metadata, 'ref', PlannerRef::class, true);
        self::addManyToOne($metadata, 'owner', PlannerRef::class);
        self::addManyToOne($metadata, 'other', PlannerItem::class);

        $metadata->table['uniqueConstraints']['owner_a'] = ['columns' => ['owner_id', 'a']];
        $metadata->table['uniqueConstraints']['owner_b'] = ['fields' => ['owner', 'b']];

        return $metadata;
    }

    private static function otherMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(PlannerOther::class);

        self::addField($metadata, 'f', 'string', true);

        return $metadata;
    }

    private static function addField(ClassMetadata $metadata, string $name, string $type, bool $unique = false): void
    {
        $metadata->fieldMappings[$name]     = FieldMapping::fromMappingArray([
            'type' => $type,
            'fieldName' => $name,
            'columnName' => $name,
            'unique' => $unique ? true : null,
        ]);
        $metadata->fieldNames[$name]        = $name;
        $metadata->propertyAccessors[$name] = PropertyAccessorFactory::createPropertyAccessor($metadata->name, $name);
    }

    private static function addManyToOne(ClassMetadata $metadata, string $name, string $targetClass, bool $unique = false): void
    {
        $metadata->associationMappings[$name] = ManyToOneAssociationMapping::fromMappingArray([
            'fieldName' => $name,
            'sourceEntity' => $metadata->name,
            'targetEntity' => $targetClass,
            'joinColumns' => [
                [
                    'name' => $name . '_id',
                    'referencedColumnName' => 'id',
                    'unique' => $unique ? true : null,
                ],
            ],
        ]);
        $metadata->propertyAccessors[$name]   = PropertyAccessorFactory::createPropertyAccessor($metadata->name, $name);
    }
}

class PlannerItem
{
    public string|null $f            = null;
    public DateTimeImmutable|null $d = null;
    public string|null $a            = null;
    public string|null $b            = null;
    public PlannerRef|null $ref      = null;
    public PlannerRef|null $owner    = null;
    public PlannerItem|null $other   = null;
}

class PlannerRef
{
}

class PlannerOther
{
    public string|null $f = null;
}
