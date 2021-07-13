<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use InvalidArgumentException;

use function get_class;
use function gettype;
use function is_object;
use function sprintf;
use function trim;

/**
 * The default DefaultEntityListener
 */
class DefaultEntityListenerResolver implements EntityListenerResolver
{
    /** @psalm-var array<class-string, object> Map to store entity listener instances. */
    private $instances = [];

    /**
     * {@inheritdoc}
     */
    public function clear($className = null)
    {
        if ($className === null) {
            $this->instances = [];

            return;
        }

        $className = trim($className, '\\');
        if (isset($this->instances[$className])) {
            unset($this->instances[$className]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function register($object)
    {
        if (! is_object($object)) {
            throw new InvalidArgumentException(sprintf('An object was expected, but got "%s".', gettype($object)));
        }

        $this->instances[get_class($object)] = $object;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve($className)
    {
        $className = trim($className, '\\');
        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        return $this->instances[$className] = new $className();
    }
}
