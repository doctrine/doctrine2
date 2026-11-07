<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\UnitOfWork;

use Closure;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;

use function is_object;
use function spl_object_id;

/**
 * Selects the pending deletions that collide with a pending insertion of the
 * same class on a unique constraint declared in the mapping, so they can run
 * before the insertions.
 *
 * A deletion is only selected when nothing else in the flush still references
 * it: no to-one foreign key of a pending insertion, update or deletion (current
 * or original value), and no pending many-to-many collection update as owner
 * or element class. Everything else keeps the regular commit order.
 *
 * @internal
 */
final class EarlyDeletionPlanner
{
    private UniqueTupleKey $uniqueTupleKey;

    /** @var array<string, array<string, list<array{string, bool}>>> */
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
     * @param array<string, true>                                  $manyToManyTargetClasses
     * @param array<int, true>                                     $manyToManyOwnerIds
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

        $insertIndex       = $this->insertionIndex($insertions, $candidateClasses);
        $foreignKeyTargets = $this->foreignKeyTargetsOfOperations($insertions, $updates, $deletions, $originalEntityData, $entityChangeSets);

        $earlyDeletions = [];

        foreach ($candidates as $entity) {
            $class = $candidateClasses[$entity::class];

            if (! $this->collidesWithInsertion($class, $entity, $originalEntityData[spl_object_id($entity)] ?? [], $insertIndex)) {
                continue;
            }

            $oid = spl_object_id($entity);
            if (
                isset($foreignKeyTargets[$oid])
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

    /** @param array<class-string<object>, ClassMetadata<object>> $candidateClasses */
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
     * @param array<int, object>                                   $insertions
     * @param array<int, object>                                   $updates
     * @param array<int, object>                                   $deletions
     * @param array<int, array<string, mixed>>                     $originalEntityData
     * @param array<int, array<string, array{0: mixed, 1: mixed}>> $entityChangeSets
     *
     * @return array<int, true>
     */
    private function foreignKeyTargetsOfOperations(
        array $insertions,
        array $updates,
        array $deletions,
        array $originalEntityData,
        array $entityChangeSets,
    ): array {
        $foreignKeyTargets = [];

        foreach ([$insertions, $updates, $deletions] as $entities) {
            foreach ($entities as $oid => $entity) {
                $foreignKeyTargets += $this->foreignKeyTargets(($this->classMetadata)($entity::class), $entity, $originalEntityData[$oid] ?? [], $entityChangeSets[$oid] ?? []);
            }
        }

        return $foreignKeyTargets;
    }

    /**
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

                    continue 2;
                }
            } else {
                continue;
            }

            if ($components !== []) {
                $declarations['constraint:' . $constraintName] = $components;
            }
        }

        return $this->uniqueDeclarations[$class->name] = $declarations;
    }

    /**
     * @param ClassMetadata<object>                                             $class
     * @param array<string, mixed>                                              $snapshot
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
     * @param ClassMetadata<object>                    $class
     * @param array<string, mixed>                     $originalData
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     *
     * @return array<int, true>
     */
    private function foreignKeyTargets(ClassMetadata $class, object $entity, array $originalData, array $changeSet): array
    {
        $targets = [];

        foreach ($this->toOneOwningFields($class) as $fieldName) {
            foreach ([$class->getFieldValue($entity, $fieldName), $changeSet[$fieldName][0] ?? $originalData[$fieldName] ?? null] as $target) {
                if (is_object($target)) {
                    $targets[spl_object_id($target)] = true;
                }
            }
        }

        return $targets;
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

    /** @param ClassMetadata<object> $class */
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
