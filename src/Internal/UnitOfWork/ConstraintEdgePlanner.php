<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\UnitOfWork;

use Closure;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

use function implode;
use function is_object;
use function serialize;
use function spl_object_id;

/**
 * Plans DELETE-before-INSERT ordering for pending deletions that collide
 * with pending insertions on metadata-declared unique tuples.
 *
 * A collision is resolvable only when no pending operation of this flush
 * holds an owning (FK-carrying) to-one reference to the candidate, current
 * or original value, and the candidate's class is not the element class of
 * a pending many-to-many collection operation: a live join row may still
 * reference it. The filter is conservative by design: a false positive
 * falls back to the baseline commit order, a false negative would violate
 * a foreign key.
 *
 * Pure module: entities and mapping metadata in, ordering edges out. Tuples
 * are compared as PHP values (referenced-entity identity for association
 * components); no database-level conversion is performed.
 *
 * @internal
 */
final class ConstraintEdgePlanner
{
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

    /**
     * @param list<object>                                         $candidates
     * @param array<int, object>                                   $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     * @param array<string, true>                                  $manyToManyTargetClasses hash-set
     *                                                            of class names referenced as
     *                                                            elements by pending many-to-many
     *                                                            collection operations of this flush
     * @param Closure(string): ClassMetadata<object>               $classMetadata
     */
    public static function planEarlyDeletions(
        array $candidates,
        array $insertions,
        array $updates,
        array $deletions,
        array $originalEntityData,
        array $entityChangeSets,
        array $manyToManyTargetClasses,
        Closure $classMetadata,
    ): ConstraintEdgePlan {
        $planner = new self();

        if ($candidates === [] || $insertions === []) {
            return new ConstraintEdgePlan([], []);
        }

        $insertIndex = [];
        foreach ($insertions as $insertion) {
            $class = $classMetadata($insertion::class);

            foreach ($planner->uniqueDeclarations($class) as $declarationId => $declaration) {
                $key = $planner->tupleKey($class, $insertion, $declaration);
                if ($key === null) {
                    continue;
                }

                $insertIndex[$class->name][$declarationId][$key] ??= $insertion;
            }
        }

        $fkTargets = [];
        foreach ($insertions as $oid => $entity) {
            $planner->collectFkTargets($classMetadata($entity::class), $entity, $oid, $originalEntityData, $entityChangeSets, $fkTargets);
        }

        foreach ($updates as $oid => $entity) {
            $planner->collectFkTargets($classMetadata($entity::class), $entity, $oid, $originalEntityData, $entityChangeSets, $fkTargets);
        }

        foreach ($deletions as $oid => $entity) {
            $planner->collectFkTargets($classMetadata($entity::class), $entity, $oid, $originalEntityData, $entityChangeSets, $fkTargets);
        }

        $edges   = [];
        $blocked = [];

        foreach ($candidates as $entity) {
            $class = $classMetadata($entity::class);

            $collidingInsertions = null;
            foreach ($planner->uniqueDeclarations($class) as $declarationId => $declaration) {
                $key = $planner->tupleKey($class, $entity, $declaration);
                if ($key === null) {
                    continue;
                }

                $collidingInsertion = $insertIndex[$class->name][$declarationId][$key] ?? null;
                if ($collidingInsertion !== null) {
                    $collidingInsertions[] = $collidingInsertion;
                }
            }

            if ($collidingInsertions === null) {
                continue;
            }

            if (isset($fkTargets[spl_object_id($entity)]) || isset($manyToManyTargetClasses[$class->name])) {
                $blocked[] = $entity;

                continue;
            }

            foreach ($collidingInsertions as $insertion) {
                $edges[] = new ConstraintEdge($entity, $insertion);
            }
        }

        return new ConstraintEdgePlan($edges, $blocked);
    }

    /**
     * Metadata-declared unique tuples of the class: field-level and join-column
     * level unique flags, plus table-level unique constraints.
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
            if (! $assoc instanceof ToOneOwningSideMapping) {
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

            foreach ($constraint['columns'] ?? [] as $column) {
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

            if ($components !== []) {
                $declarations['constraint:' . $constraintName] = $components;
            }
        }

        return $this->uniqueDeclarations[$class->name] = $declarations;
    }

    /**
     * String key of the unique tuple for the entity, or null when a component
     * is NULL: NULL never conflicts in a unique constraint.
     *
     * @param ClassMetadata<object>     $class
     * @param list<array{string, bool}> $declaration
     */
    private function tupleKey(ClassMetadata $class, object $entity, array $declaration): string|null
    {
        $parts = [];

        foreach ($declaration as [$fieldName, $isAssociation]) {
            $value = $class->getFieldValue($entity, $fieldName);

            if ($isAssociation) {
                if (! is_object($value)) {
                    return null;
                }

                $parts[] = 'o' . spl_object_id($value);

                continue;
            }

            if ($value === null) {
                return null;
            }

            $parts[] = serialize($value);
        }

        return implode("\x1f", $parts);
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
            if (! $assoc instanceof ToOneOwningSideMapping) {
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
