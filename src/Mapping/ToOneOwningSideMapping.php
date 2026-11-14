<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;
use RuntimeException;

use function array_flip;
use function assert;
use function count;
use function trim;

abstract class ToOneOwningSideMapping extends OwningSideMapping implements ToOneAssociationMapping
{
    /** @var array<string, string> */
    public array $sourceToTargetKeyColumns = [];

    /** @var array<string, string> */
    public array $targetToSourceKeyColumns = [];

    /** @var list<JoinColumnMapping> */
    public array $joinColumns = [];

    /** @var array<string, string> */
    public array $joinColumnFieldNames = [];

    /**
     * @param array<string, mixed> $mappingArray
     * @phpstan-param array{
     *     fieldName: string,
     *     sourceEntity: class-string,
     *     targetEntity: class-string,
     *     cascade?: list<'persist'|'remove'|'detach'|'refresh'|'all'>,
     *     fetch?: ClassMetadata::FETCH_*|null,
     *     inherited?: class-string|null,
     *     declared?: class-string|null,
     *     cache?: array<mixed>|null,
     *     id?: bool|null,
     *     isOnDeleteCascade?: bool|null,
     *     originalClass?: class-string|null,
     *     originalField?: string|null,
     *     orphanRemoval?: bool,
     *     unique?: bool|null,
     *     joinTable?: mixed[]|null,
     *     type?: int,
     *     isOwningSide: bool,
     *     joinColumns?: mixed[]|null,
     * } $mappingArray
     */
    #[Override]
    public static function fromMappingArray(array $mappingArray): static
    {
        $joinColumns = $mappingArray['joinColumns'] ?? [];
        unset($mappingArray['joinColumns']);

        $instance = parent::fromMappingArray($mappingArray);
        assert($instance->isToOneOwningSide());

        foreach ($joinColumns as $column) {
            $instance->joinColumns[] = JoinColumnMapping::fromMappingArray($column);
        }

        if ($instance->orphanRemoval) {
            if (! $instance->isCascadeRemove()) {
                $instance->cascade[] = 'remove';
            }

            $instance->unique = null;
        }

        return $instance;
    }

    /**
     * @param mixed[]      $mappingArray
     * @param class-string $name
     * @phpstan-param array{
     *     fieldName: string,
     *     sourceEntity: class-string,
     *     targetEntity: class-string,
     *     cascade?: list<'persist'|'remove'|'detach'|'refresh'|'all'>,
     *     fetch?: ClassMetadata::FETCH_*|null,
     *     inherited?: class-string|null,
     *     declared?: class-string|null,
     *     cache?: array<mixed>|null,
     *     id?: bool|null,
     *     isOnDeleteCascade?: bool|null,
     *     originalClass?: class-string|null,
     *     originalField?: string|null,
     *     orphanRemoval?: bool,
     *     unique?: bool|null,
     *     joinTable?: mixed[]|null,
     *     type?: int,
     *     isOwningSide: bool,
     *     joinColumns?: mixed[]|null,
     * } $mappingArray
     */
    public static function fromMappingArrayAndName(
        array $mappingArray,
        NamingStrategy $namingStrategy,
        string $name,
        array|null $table,
        bool $isInheritanceTypeSingleTable,
    ): static {
        if (isset($mappingArray['joinColumns'])) {
            foreach ($mappingArray['joinColumns'] as $index => $joinColumn) {
                if (empty($joinColumn['name'])) {
                    $mappingArray['joinColumns'][$index]['name'] = $namingStrategy->joinColumnName($mappingArray['fieldName'], $name);
                }

                if (empty($joinColumn['referencedColumnName'])) {
                    $mappingArray['joinColumns'][$index]['referencedColumnName'] = $namingStrategy->referenceColumnName();
                }
            }
        }

        $mapping = static::fromMappingArray($mappingArray);

        assert($mapping->isToOneOwningSide());
        if (empty($mapping->joinColumns)) {
            // Apply default join column
            $mapping->joinColumns = [
                JoinColumnMapping::fromMappingArray([
                    'name' => $namingStrategy->joinColumnName($mapping->fieldName, $name),
                    'referencedColumnName' => $namingStrategy->referenceColumnName(),
                ]),
            ];
        }

        $uniqueConstraintColumns = [];

        foreach ($mapping->joinColumns as $joinColumn) {
            if ($mapping->id) {
                if ($joinColumn->nullable !== null) {
                    throw MappingException::cannotSetNullableOnToOneIdentifierJoinColumns(
                        $mapping->sourceEntity,
                        $mapping->fieldName,
                    );
                }

                $joinColumn->nullable = false;
            } elseif ($joinColumn->nullable === null) {
                $joinColumn->nullable = true;
            }

            if ($mapping->isOneToOne() && ! $isInheritanceTypeSingleTable) {
                if (count($mapping->joinColumns) === 1) {
                    if (empty($mapping->id)) {
                        $joinColumn->unique = true;
                    }
                } else {
                    $uniqueConstraintColumns[] = $joinColumn->name;
                }
            }

            if (empty($joinColumn->referencedColumnName)) {
                $joinColumn->referencedColumnName = $namingStrategy->referenceColumnName();
            }

            if ($joinColumn->name[0] === '`') {
                $joinColumn->name   = trim($joinColumn->name, '`');
                $joinColumn->quoted = true;
            }

            if ($joinColumn->referencedColumnName[0] === '`') {
                $joinColumn->referencedColumnName = trim($joinColumn->referencedColumnName, '`');
                $joinColumn->quoted               = true;
            }

            $mapping->sourceToTargetKeyColumns[$joinColumn->name] = $joinColumn->referencedColumnName;
            $mapping->joinColumnFieldNames[$joinColumn->name]     = $joinColumn->fieldName ?? $joinColumn->name;
        }

        if ($uniqueConstraintColumns) {
            if (! $table) {
                throw new RuntimeException('ClassMetadata::setTable() has to be called before defining a one to one relationship.');
            }

            $table['uniqueConstraints'][$mapping->fieldName . '_uniq'] = ['columns' => $uniqueConstraintColumns];
        }

        $mapping->targetToSourceKeyColumns = array_flip($mapping->sourceToTargetKeyColumns);

        return $mapping;
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === 'joinColumns') {
            $joinColumns = [];
            foreach ($value as $column) {
                $joinColumns[] = JoinColumnMapping::fromMappingArray($column);
            }

            $this->joinColumns = $joinColumns;

            return;
        }

        parent::offsetSet($offset, $value);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        $array = parent::toArray();

        $joinColumns = [];
        foreach ($array['joinColumns'] as $column) {
            $columnArray = (array) $column;
            if ($this->id) {
                unset($columnArray['nullable']);
            }

            $joinColumns[] = $columnArray;
        }

        $array['joinColumns'] = $joinColumns;

        return $array;
    }

    /** @return list<string> */
    #[Override]
    public function __sleep(): array
    {
        return [
            ...parent::__sleep(),
            'joinColumns',
            'joinColumnFieldNames',
            'sourceToTargetKeyColumns',
            'targetToSourceKeyColumns',
        ];
    }
}
