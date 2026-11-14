<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

use function strtolower;
use function trim;

final class ManyToManyOwningSideMapping extends ToManyOwningSideMapping implements ManyToManyAssociationMapping
{
    /**
     * Specification of the join table and its join columns (foreign keys).
     * Only valid for many-to-many mappings. Note that one-to-many associations
     * can be mapped through a join table by simply mapping the association as
     * many-to-many with a unique constraint on the join table.
     */
    public JoinTableMapping $joinTable;

    /** @var list<mixed> */
    public array $joinTableColumns = [];

    /** @var array<string, string> */
    public array $relationToSourceKeyColumns = [];
    /** @var array<string, string> */
    public array $relationToTargetKeyColumns = [];

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        $array = parent::toArray();

        $array['joinTable'] = $this->joinTable->toArray();

        return $array;
    }

    /**
     * @param mixed[] $mappingArray
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
     * } $mappingArray
     */
    public static function fromMappingArrayAndNamingStrategy(array $mappingArray, NamingStrategy $namingStrategy): self
    {
        if (isset($mappingArray['joinTable']['joinColumns'])) {
            foreach ($mappingArray['joinTable']['joinColumns'] as $key => $joinColumn) {
                if (empty($joinColumn['referencedColumnName'])) {
                    $mappingArray['joinTable']['joinColumns'][$key]['referencedColumnName'] = $namingStrategy->referenceColumnName();
                }

                if (empty($joinColumn['name'])) {
                    $mappingArray['joinTable']['joinColumns'][$key]['name'] = $namingStrategy->joinKeyColumnName(
                        $mappingArray['sourceEntity'],
                        $joinColumn['referencedColumnName'] ?? $namingStrategy->referenceColumnName(),
                    );
                }
            }
        }

        if (isset($mappingArray['joinTable']['inverseJoinColumns'])) {
            foreach ($mappingArray['joinTable']['inverseJoinColumns'] as $key => $joinColumn) {
                if (empty($joinColumn['referencedColumnName'])) {
                    $mappingArray['joinTable']['inverseJoinColumns'][$key]['referencedColumnName'] = $namingStrategy->referenceColumnName();
                }

                if (empty($joinColumn['name'])) {
                    $mappingArray['joinTable']['inverseJoinColumns'][$key]['name'] = $namingStrategy->joinKeyColumnName(
                        $mappingArray['targetEntity'],
                        $joinColumn['referencedColumnName'] ?? $namingStrategy->referenceColumnName(),
                    );
                }
            }
        }

        // owning side MUST have a join table
        if (! isset($mappingArray['joinTable']) || ! isset($mappingArray['joinTable']['name'])) {
            $mappingArray['joinTable']['name'] = $namingStrategy->joinTableName(
                $mappingArray['sourceEntity'],
                $mappingArray['targetEntity'],
                $mappingArray['fieldName'],
            );
        }

        $mapping = parent::fromMappingArray($mappingArray);

        $selfReferencingEntityWithoutJoinColumns = $mapping->sourceEntity === $mapping->targetEntity
            && $mapping->joinTable->joinColumns === []
            && $mapping->joinTable->inverseJoinColumns === [];

        if ($mapping->joinTable->joinColumns === []) {
            $mapping->joinTable->joinColumns = [
                JoinColumnMapping::fromMappingArray([
                    'name' => $namingStrategy->joinKeyColumnName($mapping->sourceEntity, $selfReferencingEntityWithoutJoinColumns ? 'source' : null),
                    'referencedColumnName' => $namingStrategy->referenceColumnName(),
                    'onDelete' => 'CASCADE',
                ]),
            ];
        }

        if ($mapping->joinTable->inverseJoinColumns === []) {
            $mapping->joinTable->inverseJoinColumns = [
                JoinColumnMapping::fromMappingArray([
                    'name' => $namingStrategy->joinKeyColumnName($mapping->targetEntity, $selfReferencingEntityWithoutJoinColumns ? 'target' : null),
                    'referencedColumnName' => $namingStrategy->referenceColumnName(),
                    'onDelete' => 'CASCADE',
                ]),
            ];
        }

        $mapping->joinTableColumns = [];

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            if ($joinColumn->nullable !== null) {
                throw MappingException::cannotSetNullableOnManyToManyJoinColumns(
                    $mapping->sourceEntity,
                    $mapping->fieldName,
                );
            }

            $joinColumn->nullable = false;

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

            if (isset($joinColumn->onDelete) && strtolower($joinColumn->onDelete) === 'cascade') {
                $mapping->isOnDeleteCascade = true;
            }

            $mapping->relationToSourceKeyColumns[$joinColumn->name] = $joinColumn->referencedColumnName;
            $mapping->joinTableColumns[]                            = $joinColumn->name;
        }

        foreach ($mapping->joinTable->inverseJoinColumns as $inverseJoinColumn) {
            if ($inverseJoinColumn->nullable !== null) {
                throw MappingException::cannotSetNullableOnManyToManyJoinColumns(
                    $mapping->targetEntity,
                    $mapping->fieldName,
                );
            }

            $inverseJoinColumn->nullable = false;

            if (empty($inverseJoinColumn->referencedColumnName)) {
                $inverseJoinColumn->referencedColumnName = $namingStrategy->referenceColumnName();
            }

            if ($inverseJoinColumn->name[0] === '`') {
                $inverseJoinColumn->name   = trim($inverseJoinColumn->name, '`');
                $inverseJoinColumn->quoted = true;
            }

            if ($inverseJoinColumn->referencedColumnName[0] === '`') {
                $inverseJoinColumn->referencedColumnName = trim($inverseJoinColumn->referencedColumnName, '`');
                $inverseJoinColumn->quoted               = true;
            }

            if (isset($inverseJoinColumn->onDelete) && strtolower($inverseJoinColumn->onDelete) === 'cascade') {
                $mapping->isOnDeleteCascade = true;
            }

            $mapping->relationToTargetKeyColumns[$inverseJoinColumn->name] = $inverseJoinColumn->referencedColumnName;
            $mapping->joinTableColumns[]                                   = $inverseJoinColumn->name;
        }

        return $mapping;
    }

    /** @return list<string> */
    #[Override]
    public function __sleep(): array
    {
        $serialized   = parent::__sleep();
        $serialized[] = 'joinTable';
        $serialized[] = 'joinTableColumns';

        foreach (['relationToSourceKeyColumns', 'relationToTargetKeyColumns'] as $arrayKey) {
            if ($this->$arrayKey !== null) {
                $serialized[] = $arrayKey;
            }
        }

        return $serialized;
    }
}
