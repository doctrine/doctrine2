<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Doctrine\Persistence\Mapping\ReflectionService;
use LogicException;

use function array_unshift;
use function sprintf;

/**
 * Compiles a full set of class metadata by iterating every class the driver enumerates.
 *
 * Doubles as a {@see ClassMetadataBag} for the duration of a single {@see compile()} call: loading
 * one class's metadata may, through `onLoadMetadata` event listeners or embedded-class resolution,
 * ask for another class's metadata before the whole driver enumeration has been processed. That
 * only resolves correctly as long as metadata dependencies between classes form a DAG; a cycle
 * surfaces as a {@see LogicException}.
 *
 * Extends {@see AbstractClassMetadataFactory} purely to reuse its driver access, inheritance
 * resolution and embedded-class handling, not because of a genuine is-a relationship: most of its
 * public API (the one inherited from {@see \Doctrine\Persistence\Mapping\ClassMetadataFactory}) is
 * deliberately unsupported here, see the methods below. Callers must not treat this class as a
 * general-purpose metadata factory.
 *
 * @internal
 */
final class ClassMetadataBagCompiler extends AbstractClassMetadataFactory implements ClassMetadataBag
{
    /** @var array<class-string, ClassMetadata<object>> */
    private array $built = [];

    /** @var array<class-string, true> */
    private array $building = [];

    public function __construct(EntityManagerInterface $entityManager, ReflectionService $reflectionService)
    {
        $this->setEntityManager($entityManager);
        $this->setReflectionService($reflectionService);
        $this->initialize();
    }

    public function compile(): ReadonlyClassMetadataBag
    {
        foreach ($this->getDriver()->getAllClassNames() as $className) {
            $this->buildOne($className);
        }

        return new ReadonlyClassMetadataBag($this->built);
    }

    public function get(string $className): ClassMetadata
    {
        return $this->buildOne($className);
    }

    /** @phpstan-return array<class-string, ClassMetadata<object>> */
    public function all(): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function has(string $className): bool
    {
        try {
            $this->buildOne($className);

            return true;
        } catch (PersistenceMappingException) {
            return false;
        }
    }

    public function hasMetadataFor(string $className): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        return $this->buildOne($this->normalizeClassName($className));
    }

    public function setMetadataFor(string $className, ClassMetadataInterface $class): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @phpstan-return list<ClassMetadata<object>> */
    public function getAllMetadata(): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @phpstan-return array<class-string, ClassMetadata<object>> */
    public function getLoadedMetadata(): array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function isTransient(string $className): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function isMapped(string $className): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /**
     * Builds (and memoizes) a single class's metadata, recursively building its ancestors first.
     *
     * Safe to call re-entrantly while another class is still being built, e.g. for an embedded
     * class pulled in from within {@see AbstractClassMetadataFactory::doLoadMetadata()}: a class
     * only ever needs its own ancestor chain, not the rest of the driver's class list, built
     * first.
     *
     * @param class-string $className
     *
     * @return ClassMetadata<object>
     */
    private function buildOne(string $className): ClassMetadata
    {
        if (isset($this->built[$className])) {
            return $this->built[$className];
        }

        if (isset($this->building[$className])) {
            throw new LogicException(sprintf('Circular metadata dependency detected for class "%s".', $className));
        }

        $this->building[$className] = true;

        try {
            $parent = null;
            $rootEntityFound = false;
            $visited = [];

            foreach ($this->getParentClasses($className) as $ancestorClassName) {
                $parent = $this->buildOne($ancestorClassName);

                if ($this->isEntity($parent)) {
                    $rootEntityFound = true;
                    array_unshift($visited, $ancestorClassName);
                }
            }

            $reflService = $this->getReflectionService();
            $class = $this->newClassMetadataInstance($className);
            $this->initializeReflection($class, $reflService);

            $this->doLoadMetadata($class, $parent, $rootEntityFound, $visited);

            $this->built[$className] = $class;

            $this->wakeupReflection($class, $reflService);

            return $this->built[$className];
        } finally {
            unset($this->building[$className]);
        }
    }

    private function unsupported(string $method): LogicException
    {
        return new LogicException(sprintf(
            '%s::%s() is not supported; this class only drives compile(), it is not a general-purpose metadata factory.',
            self::class,
            $method,
        ));
    }
}
