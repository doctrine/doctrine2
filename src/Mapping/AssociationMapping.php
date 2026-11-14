<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Exception;
use OutOfRangeException;

use function assert;
use function count;
use function in_array;
use function property_exists;
use function sprintf;

abstract class AssociationMapping
{
    /**
     * The names of persistence operations to cascade on the association.
     *
     * @var list<'persist'|'remove'|'detach'|'refresh'|'all'>
     */
    public array $cascade = [];

    /**
     * The fetching strategy to use for the association, usually defaults to FETCH_LAZY.
     *
     * @var ClassMetadata::FETCH_*|null
     */
    public int|null $fetch = null;

    /**
     * This is set when the association is inherited by this class from another
     * (inheritance) parent <em>entity</em> class. The value is the FQCN of the
     * topmost entity class that contains this association. (If there are
     * transient classes in the class hierarchy, these are ignored, so the
     * class property may in fact come from a class further up in the PHP class
     * hierarchy.) To-many associations initially declared in mapped
     * superclasses are <em>not</em> considered 'inherited' in the nearest
     * entity subclasses.
     *
     * @var class-string|null
     */
    public string|null $inherited = null;

    /**
     * This is set when the association does not appear in the current class
     * for the first time, but is initially declared in another parent
     * <em>entity or mapped superclass</em>. The value is the FQCN of the
     * topmost non-transient class that contains association information for
     * this relationship.
     *
     * @var class-string|null
     */
    public string|null $declared = null;

    public array|null $cache = null;

    public bool|null $id = null;

    public bool|null $isOnDeleteCascade = null;

    /** @var class-string|null */
    public string|null $originalClass = null;

    public string|null $originalField = null;

    public bool $orphanRemoval = false;

    public bool|null $unique = null;

    /**
     * @param string       $fieldName    The name of the field in the entity
     *                                   the association is mapped to.
     * @param class-string $sourceEntity The class name of the source entity.
     *                                   In the case of to-many associations
     *                                   initially present in mapped
     *                                   superclasses, the nearest
     *                                   <em>entity</em> subclasses will be
     *                                   considered the respective source
     *                                   entities.
     * @param class-string $targetEntity The class name of the target entity.
     *                                   If it is fully-qualified it is used as
     *                                   is. If it is a simple, unqualified
     *                                   class name the namespace is assumed to
     *                                   be the same as the namespace of the
     *                                   source entity.
     */
    final public function __construct(
        public readonly string $fieldName,
        public string $sourceEntity,
        public readonly string $targetEntity,
    ) {
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
    public static function fromMappingArray(array $mappingArray): static
    {
        unset($mappingArray['isOwningSide'], $mappingArray['type']);
        $mapping = new static(
            $mappingArray['fieldName'],
            $mappingArray['sourceEntity'],
            $mappingArray['targetEntity'],
        );
        unset($mappingArray['fieldName'], $mappingArray['sourceEntity'], $mappingArray['targetEntity']);

        foreach ($mappingArray as $key => $value) {
            if ($key === 'joinTable') {
                assert($mapping instanceof ManyToManyAssociationMapping);

                if ($value === [] || $value === null) {
                    continue;
                }

                if (! $mapping instanceof ManyToManyOwningSideMapping) {
                    throw new MappingException(
                        "Mapping error on field '" .
                        $mapping->fieldName . "' in " . $mapping->sourceEntity .
                        " : 'joinTable' can only be set on many-to-many owning side.",
                    );
                }

                $mapping->joinTable = JoinTableMapping::fromMappingArray($value);

                continue;
            }

            if (property_exists($mapping, $key)) {
                $mapping->$key = $value;
            } else {
                throw new OutOfRangeException('Unknown property ' . $key . ' on class ' . static::class);
            }
        }

        return $mapping;
    }

    /**
     * @phpstan-assert-if-true OwningSideMapping $this
     * @phpstan-assert-if-false InverseSideMapping $this
     */
    final public function isOwningSide(): bool
    {
        return $this instanceof OwningSideMapping;
    }

    /** @phpstan-assert-if-true ToOneAssociationMapping $this */
    final public function isToOne(): bool
    {
        return $this instanceof ToOneAssociationMapping;
    }

    /** @phpstan-assert-if-true ToManyAssociationMapping $this */
    final public function isToMany(): bool
    {
        return $this instanceof ToManyAssociationMapping;
    }

    /** @phpstan-assert-if-true OneToOneOwningSideMapping $this */
    final public function isOneToOneOwningSide(): bool
    {
        return $this->isOneToOne() && $this->isOwningSide();
    }

    /** @phpstan-assert-if-true OneToOneOwningSideMapping|ManyToOneAssociationMapping $this */
    final public function isToOneOwningSide(): bool
    {
        return $this->isToOne() && $this->isOwningSide();
    }

    /** @phpstan-assert-if-true ManyToManyOwningSideMapping $this */
    final public function isManyToManyOwningSide(): bool
    {
        return $this instanceof ManyToManyOwningSideMapping;
    }

    /** @phpstan-assert-if-true OneToOneAssociationMapping $this */
    final public function isOneToOne(): bool
    {
        return $this instanceof OneToOneAssociationMapping;
    }

    /** @phpstan-assert-if-true OneToManyAssociationMapping $this */
    final public function isOneToMany(): bool
    {
        return $this instanceof OneToManyAssociationMapping;
    }

    /** @phpstan-assert-if-true ManyToOneAssociationMapping $this */
    final public function isManyToOne(): bool
    {
        return $this instanceof ManyToOneAssociationMapping;
    }

    /** @phpstan-assert-if-true ManyToManyAssociationMapping $this */
    final public function isManyToMany(): bool
    {
        return $this instanceof ManyToManyAssociationMapping;
    }

    /** @phpstan-assert-if-true ToManyAssociationMapping $this */
    final public function isOrdered(): bool
    {
        return $this->isToMany() && $this->orderBy() !== [];
    }

    /** @phpstan-assert-if-true ToManyAssociationMapping $this */
    public function isIndexed(): bool
    {
        return false;
    }

    final public function type(): int
    {
        return match (true) {
            $this instanceof OneToOneAssociationMapping => ClassMetadata::ONE_TO_ONE,
            $this instanceof OneToManyAssociationMapping => ClassMetadata::ONE_TO_MANY,
            $this instanceof ManyToOneAssociationMapping => ClassMetadata::MANY_TO_ONE,
            $this instanceof ManyToManyAssociationMapping => ClassMetadata::MANY_TO_MANY,
            default => throw new Exception('Cannot determine type for ' . static::class),
        };
    }

    /** @param string $offset */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->$offset) || in_array($offset, ['isOwningSide', 'type'], true);
    }

    final public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'isOwningSide' => $this->isOwningSide(),
            'type' => $this->type(),
            'isCascadeRemove' => $this->isCascadeRemove(),
            'isCascadePersist' => $this->isCascadePersist(),
            'isCascadeRefresh' => $this->isCascadeRefresh(),
            'isCascadeDetach' => $this->isCascadeDetach(),
            default => property_exists($this, $offset) ? $this->$offset : throw new OutOfRangeException(sprintf(
                'Unknown property "%s" on class %s',
                $offset,
                static::class,
            )),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        assert($offset !== null);
        if (! property_exists($this, $offset)) {
            throw new OutOfRangeException(sprintf(
                'Unknown property "%s" on class %s',
                $offset,
                static::class,
            ));
        }

        if ($offset === 'joinTable') {
            $value = JoinTableMapping::fromMappingArray($value);
        }

        $this->$offset = $value;
    }

    /** @param string $offset */
    public function offsetUnset(mixed $offset): void
    {
        if (! property_exists($this, $offset)) {
            throw new OutOfRangeException(sprintf(
                'Unknown property "%s" on class %s',
                $offset,
                static::class,
            ));
        }

        $this->$offset = null;
    }

    final public function isCascadeRemove(): bool
    {
        return in_array('remove', $this->cascade, true);
    }

    final public function isCascadePersist(): bool
    {
        return in_array('persist', $this->cascade, true);
    }

    final public function isCascadeRefresh(): bool
    {
        return in_array('refresh', $this->cascade, true);
    }

    final public function isCascadeDetach(): bool
    {
        return in_array('detach', $this->cascade, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = (array) $this;

        $array['isOwningSide'] = $this->isOwningSide();
        $array['type']         = $this->type();

        return $array;
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        $serialized = ['fieldName', 'sourceEntity', 'targetEntity'];

        if (count($this->cascade) > 0) {
            $serialized[] = 'cascade';
        }

        foreach (
            [
                'fetch',
                'inherited',
                'declared',
                'cache',
                'originalClass',
                'originalField',
            ] as $stringOrArrayProperty
        ) {
            if ($this->$stringOrArrayProperty !== null) {
                $serialized[] = $stringOrArrayProperty;
            }
        }

        foreach (['id', 'orphanRemoval', 'isOnDeleteCascade', 'unique'] as $boolProperty) {
            if ($this->$boolProperty) {
                $serialized[] = $boolProperty;
            }
        }

        return $serialized;
    }
}
