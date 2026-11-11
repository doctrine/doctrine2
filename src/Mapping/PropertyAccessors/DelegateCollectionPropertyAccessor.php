<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\DelegateCollection;
use Doctrine\ORM\PersistentCollection;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

use function sprintf;

/**
 * Exposes the managed collection to the ORM while the entity holds its custom
 * collection object, which stays the same for the whole entity lifetime.
 *
 * @internal
 */
final class DelegateCollectionPropertyAccessor implements PropertyAccessor
{
    /** @var array<class-string, ReflectionProperty> */
    private static array $innerProperties = [];

    /** @var ReflectionClass<DelegateCollection<array-key, mixed>> */
    private readonly ReflectionClass $collectionClass;
    private readonly ReflectionProperty $innerProperty;

    /** @param class-string<DelegateCollection<array-key, mixed>> $collectionClass */
    public function __construct(private readonly PropertyAccessor $parent, string $collectionClass)
    {
        $this->collectionClass = new ReflectionClass($collectionClass);
        $this->innerProperty   = self::resolveInnerProperty($collectionClass);
    }

    public function getValue(object $object): mixed
    {
        $value = $this->parent->getValue($object);

        // A custom collection may wrap another one, e.g. new TagCollection($other->getTags()):
        // expose the managed collection at the bottom, so that the UnitOfWork copies it when
        // it belongs to another entity
        while ($value instanceof DelegateCollection) {
            $value = self::readInnerCollection($value);
        }

        return $value;
    }

    public function setValue(object $object, mixed $value): void
    {
        $current = $this->parent->getValue($object);

        if ($current instanceof DelegateCollection && ! self::isOwnedByAnotherEntity($current, $object)) {
            $this->innerProperty->setValue($current, $value);

            return;
        }

        // Like entities, the instance is created without calling its constructor
        $delegate = $this->collectionClass->newInstanceWithoutConstructor();
        $this->innerProperty->setValue($delegate, $value);
        $this->parent->setValue($object, $delegate);
    }

    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->parent->getUnderlyingReflector();
    }

    /**
     * Returns the collection held by a custom collection, read from its property
     * so that no user code runs.
     *
     * @param DelegateCollection<array-key, mixed> $delegate
     *
     * @return Collection<array-key, mixed>|null
     */
    public static function readInnerCollection(DelegateCollection $delegate): Collection|null
    {
        $property = self::$innerProperties[$delegate::class] ??= self::resolveInnerProperty($delegate::class);

        return $property->isInitialized($delegate) ? $property->getValue($delegate) : null;
    }

    /**
     * Resolves the $collection property holding the managed collection.
     *
     * @param class-string $collectionClass
     *
     * @throws LogicException When the class does not declare a non-static,
     *                        non-readonly $collection property typed as Collection.
     */
    public static function resolveInnerProperty(string $collectionClass): ReflectionProperty
    {
        $class = new ReflectionClass($collectionClass);

        // A private property declared by a parent class is only visible from that class
        while (! $class->hasProperty('collection') && $class->getParentClass() !== false) {
            $class = $class->getParentClass();
        }

        if (! $class->hasProperty('collection')) {
            throw new LogicException(sprintf(
                'the class must declare a $collection property typed as %s to hold the underlying collection',
                Collection::class,
            ));
        }

        $property = $class->getProperty('collection');

        $type = $property->getType();

        // Exactly Collection: a narrower type such as ArrayCollection cannot hold the PersistentCollection
        if ($property->isStatic() || ! $type instanceof ReflectionNamedType || $type->getName() !== Collection::class) {
            throw new LogicException(sprintf(
                'the $collection property must be non-static and typed as %s',
                Collection::class,
            ));
        }

        if ($property->isReadOnly()) {
            throw new LogicException('the $collection property must not be readonly');
        }

        return $property;
    }

    /**
     * A custom collection assigned from another managed entity shares its
     * managed collection: the entity needs its own instance.
     *
     * @param DelegateCollection<array-key, mixed> $current
     */
    private static function isOwnedByAnotherEntity(DelegateCollection $current, object $object): bool
    {
        $inner = self::readInnerCollection($current);

        return $inner instanceof PersistentCollection && $inner->getOwner() !== null && $inner->getOwner() !== $object;
    }
}
