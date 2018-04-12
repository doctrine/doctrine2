<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Common\Persistence\Mapping\MappingException as CommonMappingException;
use Doctrine\ORM\Reflection\ReflectionService;
use Doctrine\ORM\Reflection\RuntimeReflectionService;
use Doctrine\ORM\Utility\StaticClassNameConverter;
use ReflectionException;
use function array_reverse;

/**
 * The ClassMetadataFactory is used to create ClassMetadata objects that contain all the
 * metadata mapping information of a class which describes how a class should be mapped
 * to a relational database.
 *
 * This class was abstracted from the ORM ClassMetadataFactory.
 */
abstract class AbstractClassMetadataFactory implements ClassMetadataFactory
{
    /**
     * Salt used by specific Object Manager implementation.
     *
     * @var string
     */
    protected $cacheSalt = '$CLASSMETADATA';

    /** @var Cache|null */
    private $cacheDriver;

    /** @var ClassMetadata[] */
    private $loadedMetadata = [];

    /** @var ReflectionService|null */
    protected $reflectionService;

    /**
     * Sets the cache driver used by the factory to cache ClassMetadata instances.
     */
    public function setCacheDriver(?Cache $cacheDriver = null) : void
    {
        $this->cacheDriver = $cacheDriver;
    }

    /**
     * Gets the cache driver used by the factory to cache ClassMetadata instances.
     */
    public function getCacheDriver() : ?Cache
    {
        return $this->cacheDriver;
    }

    /**
     * Returns an array of all the loaded metadata currently in memory.
     *
     * @return ClassMetadata[]
     */
    public function getLoadedMetadata() : array
    {
        return $this->loadedMetadata;
    }

    /**
     * Sets the reflectionService.
     */
    public function setReflectionService(ReflectionService $reflectionService) : void
    {
        $this->reflectionService = $reflectionService;
    }

    /**
     * Gets the reflection service associated with this metadata factory.
     */
    public function getReflectionService() : ReflectionService
    {
        if ($this->reflectionService === null) {
            $this->reflectionService = new RuntimeReflectionService();
        }

        return $this->reflectionService;
    }

    /**
     * Checks whether the factory has the metadata for a class loaded already.
     *
     * @param string $className
     *
     * @return bool TRUE if the metadata of the class in question is already loaded, FALSE otherwise.
     */
    public function hasMetadataFor($className) : bool
    {
        return isset($this->loadedMetadata[$className]);
    }

    /**
     * Sets the metadata descriptor for a specific class.
     *
     * NOTE: This is only useful in very special cases, like when generating proxy classes.
     *
     * @param string        $className
     * @param ClassMetadata $class
     */
    public function setMetadataFor($className, $class) : void
    {
        $this->loadedMetadata[$className] = $class;
    }

    /**
     * Forces the factory to load the metadata of all classes known to the underlying
     * mapping driver.
     *
     * @return ClassMetadata[] The ClassMetadata instances of all mapped classes.
     *
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     * @throws MappingException
     */
    public function getAllMetadata() : array
    {
        $driver   = $this->getDriver();
        $metadata = [];

        foreach ($driver->getAllClassNames() as $className) {
            $metadata[] = $this->getMetadataFor($className);
        }

        return $metadata;
    }

    /**
     * Gets the class metadata descriptor for a class.
     *
     * @param string $className The name of the class.
     *
     * @throws \InvalidArgumentException
     * @throws ReflectionException
     * @throws CommonMappingException
     */
    public function getMetadataFor($className) : ClassMetadata
    {
        if (isset($this->loadedMetadata[$className])) {
            return $this->loadedMetadata[$className];
        }

        $realClassName = $this->normalizeClassName($className);

        if (isset($this->loadedMetadata[$realClassName])) {
            // We do not have the alias name in the map, include it
            return $this->loadedMetadata[$className] = $this->loadedMetadata[$realClassName];
        }

        $metadataBuildingContext = $this->newClassMetadataBuildingContext();
        $loadingException        = null;

        try {
            if ($this->cacheDriver) {
                $cached = $this->cacheDriver->fetch($realClassName . $this->cacheSalt);

                if ($cached instanceof ClassMetadata) {
                    $this->loadedMetadata[$realClassName] = $cached;

                    $cached->wakeupReflection($metadataBuildingContext->getReflectionService());
                } else {
                    foreach ($this->loadMetadata($realClassName, $metadataBuildingContext) as $loadedClass) {
                        $loadedClassName = $loadedClass->getClassName();

                        $this->cacheDriver->save($loadedClassName . $this->cacheSalt, $loadedClass, null);
                    }
                }
            } else {
                $this->loadMetadata($realClassName, $metadataBuildingContext);
            }
        } catch (CommonMappingException $loadingException) {
            $fallbackMetadataResponse = $this->onNotFoundMetadata($realClassName, $metadataBuildingContext);

            if (! $fallbackMetadataResponse) {
                throw $loadingException;
            }

            $this->loadedMetadata[$realClassName] = $fallbackMetadataResponse;
        }

        if ($className !== $realClassName) {
            // We do not have the alias name in the map, include it
            $this->loadedMetadata[$className] = $this->loadedMetadata[$realClassName];
        }

        $metadataBuildingContext->validate();

        return $this->loadedMetadata[$className];
    }

    /**
     * Loads the metadata of the class in question and all it's ancestors whose metadata
     * is still not loaded.
     *
     * Important: The class $name does not necessarily exist at this point here.
     * Scenarios in a code-generation setup might have access to XML
     * Mapping files without the actual PHP code existing here. That is why the
     * {@see Doctrine\Common\Persistence\Mapping\ReflectionService} interface
     * should be used for reflection.
     *
     * @param string $name The name of the class for which the metadata should
     *                                                              get loaded.
     *
     * @return ClassMetadata[]
     *
     * @throws \InvalidArgumentException
     */
    protected function loadMetadata(string $name, ClassMetadataBuildingContext $metadataBuildingContext) : array
    {
        $loaded = [];

        $parentClasses   = $this->getParentClasses($name);
        $parentClasses[] = $name;

        // Move down the hierarchy of parent classes, starting from the topmost class
        $parent = null;

        foreach ($parentClasses as $className) {
            if (isset($this->loadedMetadata[$className])) {
                $parent = $this->loadedMetadata[$className];

                continue;
            }

            $class = $this->doLoadMetadata($className, $parent, $metadataBuildingContext);

            $this->loadedMetadata[$className] = $class;

            $parent = $class;

            $loaded[] = $class;
        }

        return $loaded;
    }

    /**
     * {@inheritDoc}
     */
    public function isTransient($className) : bool
    {
        return $this->getDriver()->isTransient($this->normalizeClassName($className));
    }

    /**
     * Gets an array of parent classes for the given entity class.
     *
     * @param string $name
     *
     * @return string[]
     *
     * @throws \InvalidArgumentException
     */
    protected function getParentClasses($name) : array
    {
        // Collect parent classes, ignoring transient (not-mapped) classes.
        $parentClasses = [];

        foreach (array_reverse($this->getReflectionService()->getParentClasses($name)) as $parentClass) {
            if (! $this->getDriver()->isTransient($parentClass)) {
                $parentClasses[] = $parentClass;
            }
        }

        return $parentClasses;
    }

    /**
     * Provides a fallback hook for loading metadata when loading failed due to reflection/mapping exceptions
     *
     * Override this method to implement a fallback strategy for failed metadata loading
     */
    protected function onNotFoundMetadata(
        string $className,
        ClassMetadataBuildingContext $metadataBuildingContext
    ) : ?ClassMetadata {
        return null;
    }

    private function normalizeClassName(string $className) : string
    {
        return StaticClassNameConverter::getRealClass($className);
    }

    /**
     * Returns the mapping driver implementation.
     */
    abstract protected function getDriver() : Driver\MappingDriver;

    /**
     * Checks whether the class metadata is an entity.
     *
     * This method should return false for mapped superclasses or embedded classes.
     */
    abstract protected function isEntity(ClassMetadata $class) : bool;

    /**
     * Creates a new ClassMetadata instance for the given class name.
     */
    abstract protected function doLoadMetadata(
        string $className,
        ?ClassMetadata $parent,
        ClassMetadataBuildingContext $metadataBuildingContext
    ) : ClassMetadata;

    /**
     * Creates a new ClassMetadataBuildingContext instance.
     */
    abstract protected function newClassMetadataBuildingContext() : ClassMetadataBuildingContext;
}
