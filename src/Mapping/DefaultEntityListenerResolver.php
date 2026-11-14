<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

use function trim;

/**
 * The default DefaultEntityListener
 */
class DefaultEntityListenerResolver implements EntityListenerResolver
{
    /** @var array<class-string, object> Map to store entity listener instances. */
    private array $instances = [];

    #[Override]
    public function clear(string|null $className = null): void
    {
        if ($className === null) {
            $this->instances = [];

            return;
        }

        $className = trim($className, '\\');
        unset($this->instances[$className]);
    }

    #[Override]
    public function register(object $object): void
    {
        $this->instances[$object::class] = $object;
    }

    #[Override]
    public function resolve(string $className): object
    {
        $className = trim($className, '\\');

        return $this->instances[$className] ??= new $className();
    }
}
