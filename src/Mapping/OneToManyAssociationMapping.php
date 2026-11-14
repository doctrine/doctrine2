<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

final class OneToManyAssociationMapping extends ToManyInverseSideMapping
{
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
    #[Override]
    public static function fromMappingArray(array $mappingArray): static
    {
        $mapping = parent::fromMappingArray($mappingArray);

        if ($mapping->orphanRemoval && ! $mapping->isCascadeRemove()) {
            $mapping->cascade[] = 'remove';
        }

        return $mapping;
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
    public static function fromMappingArrayAndName(array $mappingArray, string $name): static
    {
        $mapping = self::fromMappingArray($mappingArray);

        // OneToMany-side MUST be inverse (must have mappedBy)
        if (! isset($mapping->mappedBy)) {
            throw MappingException::oneToManyRequiresMappedBy($name, $mapping->fieldName);
        }

        return $mapping;
    }
}
