<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;

use function array_combine;
use function array_keys;
use function array_map;
use function array_unshift;
use function class_exists;
use function str_contains;
use function str_replace;

/**
 * The ClassMetadataFactory is used to create ClassMetadata objects that contain all the
 * metadata mapping information of a class which describes how a class should be mapped
 * to a relational database.
 */
class ClassMetadataFactory extends AbstractClassMetadataFactory
{
    /** Salt used when computing a cache key for a class's metadata. */
    //protected string $cacheSalt = '__CLASSMETADATA__';

    private CacheItemPoolInterface|null $cache = null;

    /** @var array<class-string, ClassMetadata<object>> */
    private array $loadedMetadata = [];

    /** @var array<class-string, bool> */
    private array $mappedClasses = [];

    public function setCache(CacheItemPoolInterface $cache): void
    {
        $this->cache = $cache;
    }

//    protected function getCache(): CacheItemPoolInterface|null
//    {
//        return $this->cache;
//    }

    /** @return array<class-string, ClassMetadata<object>> */
    public function getLoadedMetadata(): array
    {
        return $this->loadedMetadata;
    }

    /** @return list<ClassMetadata<object>> */
    public function getAllMetadata(): array
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        $metadata = [];
        foreach ($this->getDriver()->getAllClassNames() as $className) {
            $metadata[] = $this->getMetadataFor($className);
        }

        return $metadata;
    }

    /**
     * @param class-string $className
     *
     * @throws MappingException
     */
    public function getMetadataFor(string $className): ClassMetadata
    {
        $className = $this->normalizeClassName($className);

        if (isset($this->loadedMetadata[$className])) {
            return $this->loadedMetadata[$className];
        }

        if (class_exists($className, false) && (new ReflectionClass($className))->isAnonymous()) {
            throw MappingException::classIsAnonymous($className);
        }

        if (! class_exists($className, false) && str_contains($className, ':')) {
            throw MappingException::nonExistingClass($className);
        }

        $realClassName = $this->getRealClass($className);

        if (isset($this->loadedMetadata[$realClassName])) {
            // We do not have the alias name in the map, include it
            return $this->loadedMetadata[$className] = $this->loadedMetadata[$realClassName];
        }

        try {
            if ($this->cache !== null) {
                $cached = $this->cache->getItem($this->getCacheKey($realClassName))->get();
                if ($cached instanceof ClassMetadata) {
                    $this->loadedMetadata[$realClassName] = $cached;

                    $this->wakeupReflection($cached, $this->getReflectionService());
                } else {
                    $loadedClassNames = $this->loadMetadata($realClassName);
                    $cacheKeys        = array_combine(
                        array_map($this->getCacheKey(...), $loadedClassNames),
                        $loadedClassNames,
                    );

                    foreach ($this->cache->getItems(array_keys($cacheKeys)) as $item) {
                        if (! isset($cacheKeys[$item->getKey()])) {
                            continue;
                        }

                        $item->set($this->loadedMetadata[$cacheKeys[$item->getKey()]]);
                        $this->cache->saveDeferred($item);
                    }

                    $this->cache->commit();
                }
            } else {
                $this->loadMetadata($realClassName);
            }
        } catch (PersistenceMappingException $loadingException) {
            $fallbackMetadataResponse = $this->onNotFoundMetadata($realClassName);

            if ($fallbackMetadataResponse === null) {
                throw $loadingException;
            }

            $this->loadedMetadata[$realClassName] = $fallbackMetadataResponse;
        }

        if ($className !== $realClassName) {
            // We do not have the alias name in the map, include it
            $this->loadedMetadata[$className] = $this->loadedMetadata[$realClassName];
        }

        return $this->loadedMetadata[$className];
    }

    public function hasMetadataFor(string $className): bool
    {
        return isset($this->loadedMetadata[$this->normalizeClassName($className)]);
    }

    /**
     * Sets the metadata descriptor for a specific class.
     *
     * @param class-string $className
     */
    public function setMetadataFor(string $className, ClassMetadataInterface $class): void
    {
        $this->loadedMetadata[$this->normalizeClassName($className)] = $class;
    }

    /** @param class-string $className */
    public function isTransient(string $className): bool
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        if (class_exists($className, false) && (new ReflectionClass($className))->isAnonymous()) {
            return false;
        }

        if (! class_exists($className, false) && str_contains($className, ':')) {
            throw MappingException::nonExistingClass($className);
        }

        return $this->getDriver()->isTransient($className);
    }

    /**
     * Whether this class is mapped by the ORM.
     *
     * In addition to non-transient classes (see {@see isTransient()}), this also includes
     * embedded classes, which provide mapped fields as well.
     *
     * @param class-string $className
     */
    public function isMapped(string $className): bool
    {
        /*
            With the current state of drivers, finding a reliable answer with regard to Embeddable classes would be to do a full,
            expensive metadata loading attempt. So, try to shortcut this as much as possible by caching the result, referring to the
            cache of already-loaded metadata and separating transient classes (a rather simple class attribute check in the best case)
            before falling through to the load attempt as a last resort.
        */

        return $this->mappedClasses[$className]
            ??= $this->hasMetadataFor($className)
            || ! $this->isTransient($className)
            || $this->canLoadMetadataFor($className);
    }

    /** @param class-string $className */
    private function canLoadMetadataFor(string $className): bool
    {
        try {
            $this->getMetadataFor($className);

            return true;
        } catch (PersistenceMappingException) {
            return false;
        }
    }

    protected function getCacheKey(string $realClassName): string
    {
        return str_replace('\\', '__', $realClassName) . $this->cacheSalt;
    }

    /**
     * Loads the metadata of the class in question and all its ancestors whose metadata
     * is still not loaded.
     *
     * Important: The class $name does not necessarily exist at this point here.
     * Scenarios in a code-generation setup might have access to XML/YAML
     * Mapping files without the actual PHP code existing here.
     *
     * @param class-string $name The name of the class for which the metadata should get loaded.
     *
     * @return list<string>
     */
    /*private*/ protected function loadMetadata(string $name): array
    {
        if (! $this->initialized) {
            $this->initialize();
        }

        $loaded = [];

        $parentClasses   = $this->getParentClasses($name);
        $parentClasses[] = $name;

        // Move down the hierarchy of parent classes, starting from the topmost class
        $parent          = null;
        $rootEntityFound = false;
        $visited         = [];
        $reflService     = $this->getReflectionService();

        foreach ($parentClasses as $className) {
            if (isset($this->loadedMetadata[$className])) {
                $parent = $this->loadedMetadata[$className];

                if ($this->isEntity($parent)) {
                    $rootEntityFound = true;

                    array_unshift($visited, $className);
                }

                continue;
            }

            $class = $this->newClassMetadataInstance($className);
            $this->initializeReflection($class, $reflService);

            $this->doLoadMetadata($class, $parent, $rootEntityFound, $visited);

            $this->loadedMetadata[$className] = $class;

            $parent = $class;

            if ($this->isEntity($class)) {
                $rootEntityFound = true;

                array_unshift($visited, $className);
            }

            $this->wakeupReflection($class, $reflService);

            $loaded[] = $className;
        }

        return $loaded;
    }
}
