<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\UnitOfWork;

use Closure;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;

use function is_object;
use function spl_object_id;

/**
 * Plans DELETE-before-INSERT ordering for pending deletions that collide
 * with pending insertions on metadata-declared unique tuples.
 *
 * A collision is resolvable only when no pending operation of this flush
 * holds an owning (FK-carrying) to-one reference to the candidate, current
 * or original value, the candidate is not the owner of a pending
 * many-to-many collection update (its update would run against a deleted
 * owner), and the candidate's class is not the element class of a pending
 * many-to-many collection update: a live join row may still reference it.
 * The filter is conservative: a false positive falls back to the
 * baseline commit order, a false negative would violate a foreign key.
 * The element match is class-wide and is closed over the subclasses of
 * each target entity; the owner exclusion above is applied at instance
 * precision.
 *
 * Pure module: entities and mapping metadata in, the early-deletion list
 * out. Tuple components are compared in their database representation.
 * Equality beyond that representation is not detected: strings that a
 * collation (or another server-side normalization) treats as equal in a
 * unique index compare as different here, since that equality is decided
 * by the server. When a component has no database representation — no
 * field mapping, a value the converter rejects, a converter failure or a
 * non-scalar conversion result — the comparison of that candidate against
 * that declaration is skipped and the candidate stays on the baseline
 * commit order. A table-level declaration is only consumed when
 * each of its components resolves to a field or an owning to-one
 * association of the class (a discriminator column, for instance, resolves
 * to neither), and tuples are only matched within one class.
 *
 * @internal
 */
final class EarlyDeletionPlanner
{
    private UniqueTupleKey $uniqueTupleKey;

    /**
     * Unique-tuple declarations per class name: declaration id to components,
     * each a pair of property name and whether the component is a to-one
     * association (referenced-entity identity) rather than a field (PHP value).
     *
     * @var array<string, array<string, list<array{string, bool}>>>
     */
    private array $uniqueDeclarations = [];

    /** @var array<string, list<string>> */
    private array $toOneOwningFields = [];

    /** @param Closure(string): ClassMetadata<object> $classMetadata */
    public function __construct(
        private readonly Closure $classMetadata,
        AbstractPlatform $platform,
    ) {
        $this->uniqueTupleKey = new UniqueTupleKey($platform);
    }

    /**
     * @param list<object>                                         $candidates
     * @param array<int, object>                                   $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     * @param array<string, true>                                  $manyToManyTargetClasses element class names of pending many-to-many collection updates, closed over subclasses
     * @param array<int, true>                                     $manyToManyOwnerIds      spl_object_id() of the owners of pending many-to-many collection updates
     *
     * @return list<object>
     */
    public function planEarlyDeletions(
        array $candidates,
        array $insertions,
        array $updates,
        array $deletions,
        array $originalEntityData,
        array $entityChangeSets,
        array $manyToManyTargetClasses,
        array $manyToManyOwnerIds,
    ): array {
        if ($candidates === [] || $insertions === []) {
            return [];
        }

        $candidateClasses = $this->candidateClasses($candidates);

        if (! $this->hasUniqueDeclarations($candidateClasses)) {
            return [];
        }

        $insertIndex = $this->insertionIndex($insertions, $candidateClasses);
        $fkTargets   = $this->collectFkTargetsFromOperations($insertions, $updates, $deletions, $originalEntityData, $entityChangeSets);

        $earlyDeletions = [];

        foreach ($candidates as $entity) {
            $class = $candidateClasses[$entity::class];

            if (! $this->collidesWithInsertion($class, $entity, $originalEntityData[spl_object_id($entity)] ?? [], $insertIndex)) {
                continue;
            }

            $oid = spl_object_id($entity);
            if (
                isset($fkTargets[$oid])
                || isset($manyToManyOwnerIds[$oid])
                || isset($manyToManyTargetClasses[$class->name])
            ) {
                continue;
            }

            $earlyDeletions[] = $entity;
        }

        return $earlyDeletions;
    }

    /**
     * @param list<object> $candidates
     *
     * @return array<class-string<object>, ClassMetadata<object>>
     */
    private function candidateClasses(array $candidates): array
    {
        $candidateClasses = [];
        foreach ($candidates as $entity) {
            $candidateClasses[$entity::class] ??= ($this->classMetadata)($entity::class);
        }

        return $candidateClasses;
    }

    /**
     * Whether at least one candidate class declares a unique tuple.
     * Declarations are only computed for the candidate classes: when none
     * of them declares a unique tuple, no insertion index is built at all.
     *
     * @param array<class-string<object>, ClassMetadata<object>> $candidateClasses
     */
    private function hasUniqueDeclarations(array $candidateClasses): bool
    {
        foreach ($candidateClasses as $class) {
            if ($this->uniqueDeclarations($class) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index only holds insertions of the candidate classes: keys of
     * other classes can never be matched, since a collision is only
     * detected within one class.
     *
     * @param array<int, object>                                 $insertions
     * @param array<class-string<object>, ClassMetadata<object>> $candidateClasses
     *
     * @return array<class-string<object>, array<string, array<string, object>>>
     */
    private function insertionIndex(array $insertions, array $candidateClasses): array
    {
        $insertIndex = [];
        foreach ($insertions as $insertion) {
            $class = $candidateClasses[$insertion::class] ?? null;
            if ($class === null) {
                continue;
            }

            foreach ($this->uniqueDeclarations($class) as $declarationId => $declaration) {
                $key = $this->uniqueTupleKey->forDeclaration($class, $insertion, $declaration, null);
                if ($key === null) {
                    continue;
                }

                $insertIndex[$class->name][$declarationId][$key] ??= $insertion;
            }
        }

        return $insertIndex;
    }

    /**
     * Collects the FK targets of the pending operations of this flush —
     * insertions, updates and deletions — into one hash for the guard chain
     * of planEarlyDeletions().
     *
     * @param array<int, object>                                   $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     *
     * @return array<int, true>
     */
    private function collectFkTargetsFromOperations(
        array $insertions,
        array $updates,
        array $deletions,
        array $originalEntityData,
        array $entityChangeSets,
    ): array {
        $fkTargets = [];
        foreach ($insertions as $oid => $entity) {
            $this->collectFkTargets(($this->classMetadata)($entity::class), $entity, $oid, $originalEntityData, $entityChangeSets, $fkTargets);
        }

        foreach ($updates as $oid => $entity) {
            $this->collectFkTargets(($this->classMetadata)($entity::class), $entity, $oid, $originalEntityData, $entityChangeSets, $fkTargets);
        }

        foreach ($deletions as $oid => $entity) {
            $this->collectFkTargets(($this->classMetadata)($entity::class), $entity, $oid, $originalEntityData, $entityChangeSets, $fkTargets);
        }

        return $fkTargets;
    }

    /**
     * Metadata-declared unique tuples of the class: field-level and join-column
     * level unique flags, plus table-level unique constraints in either the
     * columns or the fields form (a fields component may name a field or an
     * owning to-one association).
     *
     * @param ClassMetadata<object> $class
     *
     * @return array<string, list<array{string, bool}>>
     */
    private function uniqueDeclarations(ClassMetadata $class): array
    {
        if (isset($this->uniqueDeclarations[$class->name])) {
            return $this->uniqueDeclarations[$class->name];
        }

        $declarations = [];

        foreach ($class->fieldMappings as $fieldMapping) {
            if ($fieldMapping->unique) {
                $declarations['field:' . $fieldMapping->fieldName] = [[$fieldMapping->fieldName, false]];
            }
        }

        foreach ($class->associationMappings as $assoc) {
            if (! $assoc->isToOneOwningSide()) {
                continue;
            }

            foreach ($assoc->joinColumns as $joinColumn) {
                if ($joinColumn->unique) {
                    $declarations['join:' . $assoc->fieldName] = [[$assoc->fieldName, true]];

                    break;
                }
            }
        }

        foreach ($class->table['uniqueConstraints'] ?? [] as $constraintName => $constraint) {
            $components = [];

            if (isset($constraint['columns'])) {
                foreach ($constraint['columns'] as $column) {
                    if (isset($class->fieldNames[$column])) {
                        $components[] = [$class->fieldNames[$column], false];

                        continue;
                    }

                    $associationField = $this->associationFieldForColumn($class, $column);
                    if ($associationField === null) {
                        // Column is not backed by a field or FK association
                        // (e.g. a discriminator column): tuple is not computable.
                        continue 2;
                    }

                    $components[] = [$associationField, true];
                }
            } elseif (isset($constraint['fields'])) {
                foreach ($constraint['fields'] as $fieldName) {
                    if (isset($class->fieldMappings[$fieldName])) {
                        $components[] = [$fieldName, false];

                        continue;
                    }

                    $assoc = $class->associationMappings[$fieldName] ?? null;
                    if ($assoc !== null && $assoc->isToOneOwningSide()) {
                        $components[] = [$fieldName, true];

                        continue;
                    }

                    // Component resolves to neither a field nor an owning
                    // to-one association: tuple is not computable.
                    continue 2;
                }
            } else {
                // Neither form is declared: tuple is not computable.
                continue;
            }

            if ($components !== []) {
                $declarations['constraint:' . $constraintName] = $components;
            }
        }

        return $this->uniqueDeclarations[$class->name] = $declarations;
    }

    /**
     * Whether the entity's tuple matches a pending insertion's tuple on any
     * metadata-declared unique constraint of the class.
     *
     * @param ClassMetadata<object>                                             $class
     * @param array<string, mixed>                                              $snapshot    the candidate's original entity data
     * @param array<class-string<object>, array<string, array<string, object>>> $insertIndex
     */
    private function collidesWithInsertion(ClassMetadata $class, object $entity, array $snapshot, array $insertIndex): bool
    {
        foreach ($this->uniqueDeclarations($class) as $declarationId => $declaration) {
            $key = $this->uniqueTupleKey->forDeclaration($class, $entity, $declaration, $snapshot);
            if ($key === null) {
                continue;
            }

            if (isset($insertIndex[$class->name][$declarationId][$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collects the entities referenced by owning (FK-carrying) to-one
     * associations of the pending operation into the hash of FK targets.
     *
     * For non-insertions, the original/database value of each association is
     * considered as well: the owner's database FK row may still reference the
     * target until its UPDATE or DELETE has run. The original value of updated
     * entities lives in the change set pairs, since original entity data is
     * overwritten with the actual data during change-set computation.
     *
     * @param ClassMetadata<object>                                $class
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     * @param array<int, true>                                     $fkTargets
     */
    private function collectFkTargets(
        ClassMetadata $class,
        object $entity,
        int $oid,
        array $originalEntityData,
        array $entityChangeSets,
        array &$fkTargets,
    ): void {
        foreach ($this->toOneOwningFields($class) as $fieldName) {
            $target = $class->getFieldValue($entity, $fieldName);
            if (is_object($target)) {
                $fkTargets[spl_object_id($target)] = true;
            }

            $originalTarget = $entityChangeSets[$oid][$fieldName][0]
                ?? $originalEntityData[$oid][$fieldName]
                ?? null;

            if (is_object($originalTarget)) {
                $fkTargets[spl_object_id($originalTarget)] = true;
            }
        }
    }

    /**
     * @param ClassMetadata<object> $class
     *
     * @return list<string>
     */
    private function toOneOwningFields(ClassMetadata $class): array
    {
        if (isset($this->toOneOwningFields[$class->name])) {
            return $this->toOneOwningFields[$class->name];
        }

        $fields = [];

        foreach ($class->associationMappings as $assoc) {
            if (! $assoc->isToOneOwningSide()) {
                continue;
            }

            $fields[] = $assoc->fieldName;
        }

        return $this->toOneOwningFields[$class->name] = $fields;
    }

    /**
     * Field name of the to-one owning association carrying the given FK
     * column, or null when the column belongs to no such association.
     *
     * @param ClassMetadata<object> $class
     */
    private function associationFieldForColumn(ClassMetadata $class, string $column): string|null
    {
        foreach ($class->associationMappings as $assoc) {
            if (! $assoc->isToOneOwningSide()) {
                continue;
            }

            foreach ($assoc->joinColumns as $joinColumn) {
                if ($joinColumn->name === $column) {
                    return $assoc->fieldName;
                }
            }
        }

        return null;
    }
}
