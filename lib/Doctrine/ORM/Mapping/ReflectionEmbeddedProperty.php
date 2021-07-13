<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Instantiator\Instantiator;
use ReflectionProperty;
use ReturnTypeWillChange;

/**
 * Acts as a proxy to a nested Property structure, making it look like
 * just a single scalar property.
 *
 * This way value objects "just work" without UnitOfWork, Persisters or Hydrators
 * needing any changes.
 *
 * TODO: Move this class into Common\Reflection
 */
class ReflectionEmbeddedProperty extends ReflectionProperty
{
    /** @var ReflectionProperty reflection property of the class where the embedded object has to be put */
    private $parentProperty;

    /** @var ReflectionProperty reflection property of the embedded object */
    private $childProperty;

    /** @var string name of the embedded class to be eventually instantiated */
    private $embeddedClass;

    /** @var Instantiator|null */
    private $instantiator;

    /**
     * @param string $embeddedClass
     */
    public function __construct(ReflectionProperty $parentProperty, ReflectionProperty $childProperty, $embeddedClass)
    {
        $this->parentProperty = $parentProperty;
        $this->childProperty  = $childProperty;
        $this->embeddedClass  = (string) $embeddedClass;

        parent::__construct($childProperty->getDeclaringClass()->getName(), $childProperty->getName());
    }

    /**
     * {@inheritDoc}
     */
    #[ReturnTypeWillChange]
    public function getValue($object = null)
    {
        $embeddedObject = $this->parentProperty->getValue($object);

        if ($embeddedObject === null) {
            return null;
        }

        return $this->childProperty->getValue($embeddedObject);
    }

    /**
     * {@inheritDoc}
     */
    #[ReturnTypeWillChange]
    public function setValue($object, $value = null)
    {
        $embeddedObject = $this->parentProperty->getValue($object);

        if ($embeddedObject === null) {
            $this->instantiator = $this->instantiator ?: new Instantiator();

            $embeddedObject = $this->instantiator->instantiate($this->embeddedClass);

            $this->parentProperty->setValue($object, $embeddedObject);
        }

        $this->childProperty->setValue($embeddedObject, $value);
    }
}
