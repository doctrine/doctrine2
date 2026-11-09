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

use function array_keys;
use function count;
use function current;
use function implode;
use function sprintf;

/**
 * Exposes the managed collection to the ORM while the entity holds its custom
 * collection object, which stays the same for the whole entity lifetime.
 *
 * @internal
 */
final class DelegateCollectionPropertyAccessor implements PropertyAccessor
{
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

        return $value instanceof DelegateCollection ? $value->getDoctrineCollection() : $value;
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
     * Resolves the single Collection-typed property holding the managed collection.
     *
     * @param class-string $collectionClass
     *
     * @throws LogicException When the class does not declare exactly one
     *                        writable Collection-typed property.
     */
    public static function resolveInnerProperty(string $collectionClass): ReflectionProperty
    {
        $candidates = [];

        $reflection = new ReflectionClass($collectionClass);

        while ($reflection !== false) {
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() || isset($candidates[$property->getName()])) {
                    continue;
                }

                $type = $property->getType();

                // Exactly Collection: a narrower type such as ArrayCollection cannot hold the PersistentCollection
                if (! $type instanceof ReflectionNamedType || $type->getName() !== Collection::class) {
                    continue;
                }

                $candidates[$property->getName()] = $property;
            }

            $reflection = $reflection->getParentClass();
        }

        if (count($candidates) !== 1) {
            throw new LogicException(sprintf(
                'the class must declare exactly one property typed as %s to hold the underlying collection, %s found%s',
                Collection::class,
                count($candidates),
                $candidates === [] ? '' : ' ($' . implode(', $', array_keys($candidates)) . ')',
            ));
        }

        $property = current($candidates);

        if ($property->isReadOnly()) {
            throw new LogicException(sprintf(
                'the property $%s holding the underlying collection must not be readonly',
                $property->getName(),
            ));
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
        $inner = $current->getDoctrineCollection();

        return $inner instanceof PersistentCollection && $inner->getOwner() !== null && $inner->getOwner() !== $object;
    }
}
