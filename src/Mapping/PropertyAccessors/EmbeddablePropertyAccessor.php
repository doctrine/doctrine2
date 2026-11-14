<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use Doctrine\Instantiator\Instantiator;
use Override;
use ReflectionClass;
use ReflectionProperty;

/** @internal */
class EmbeddablePropertyAccessor implements PropertyAccessor
{
    private static Instantiator|null $instantiator = null;

    /** @var ReflectionClass<object>|null */
    private ReflectionClass|null $embeddedReflectionClass = null;

    public function __construct(
        private PropertyAccessor $parent,
        private PropertyAccessor $child,
        /** @var class-string */
        private string $embeddedClass,
    ) {
    }

    #[Override]
    public function setValue(object $object, mixed $value): void
    {
        $embeddedObject = $this->parent->getValue($object);

        if ($embeddedObject === null) {
            self::$instantiator ??= new Instantiator();

            $embeddedObject = self::$instantiator->instantiate($this->embeddedClass);

            $this->parent->setValue($object, $embeddedObject);
        } else {
            $this->initializeLazyObject($object, $embeddedObject);
        }

        $this->child->setValue($embeddedObject, $value);
    }

    #[Override]
    public function getValue(object $object): mixed
    {
        $embeddedObject = $this->parent->getValue($object);

        if ($embeddedObject === null) {
            return null;
        }

        $this->initializeLazyObject($object, $embeddedObject);

        return $this->child->getValue($embeddedObject);
    }

    #[Override]
    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->child->getUnderlyingReflector();
    }

    private function initializeLazyObject(object $object, object $embeddedObject): void
    {
        $this->embeddedReflectionClass ??= new ReflectionClass($this->embeddedClass);

        if (! $this->embeddedReflectionClass->isUninitializedLazyObject($embeddedObject)) {
            return;
        }

        // The embedded objects of a partial entity are ghosts that load the entity when initialized.
        if ((new ReflectionClass($object))->isUninitializedLazyObject($object)) {
            return;
        }

        $this->embeddedReflectionClass->initializeLazyObject($embeddedObject);
    }
}
