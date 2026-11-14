<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use ReflectionProperty;

/**
 * A property accessor is a class that allows to read and write properties on objects regardless of visibility.
 *
 * We use them while creating objects from database rows in {@link UnitOfWork::createEntity()} or when
 * computing changesets from objects that are about to be written back to the database in {@link UnitOfWork::computeChangeSet()}.
 *
 * This abstraction over ReflectionProperty is necessary, because for several features of either Doctrine or PHP, we
 * need to handle edge cases in reflection at a central location in the code.
 *
 * @internal
 */
interface PropertyAccessor
{
    /**
     * Writes the value of the property, without triggering the initialization of the given object.
     *
     * A lazy embedded object is initialized, unless the given object is itself an uninitialized lazy object.
     *
     * Passing null unsets the property, so that it counts as not initialized.
     *
     * @param mixed $value The new value of the property, null to unset the property.
     */
    public function setValue(object $object, mixed $value): void;

    /**
     * Reads the value of the property, without triggering the initialization of the given object.
     *
     * A lazy embedded object is initialized, unless the given object is itself an uninitialized lazy object.
     *
     * @return mixed The value of the object property, null if the property is not initialized.
     */
    public function getValue(object $object): mixed;

    public function getUnderlyingReflector(): ReflectionProperty;
}
