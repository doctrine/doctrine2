<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\ORM\Mapping\Cache\ClassMetadatasCache;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use LogicException;
use ReflectionClass;

use function array_values;
use function class_exists;
use function sprintf;
use function str_contains;

/**
 * Serves metadata from a {@see ReadonlyClassMetadataBag} snapshot. Once built or supplied,
 * the snapshot is complete and read-only; this class itself never touches
 * the mapping driver.
 */
final class CompiledClassMetadataFactory extends AbstractClassMetadataFactory
{
    private ClassMetadataBag|null $classMetadatas = null;

    private ClassMetadatasCache|null $cache = null;

    /**
     * Metadata resolved reactively via {@see onNotFoundMetadata()} for classes the snapshot does
     * not know about, e.g. an interface target resolved by `ResolveTargetEntityListener`. Kept
     * for the lifetime of this factory instance only, never part of the snapshot itself.
     *
     * @var array<class-string, ClassMetadata<object>>
     */
    private array $resolvedTargetEntities = [];

    /**
     * Without an injected cache, {@see getClassMetadatas()} builds its snapshot ad hoc on first
     * use and forgets it again once this factory instance is discarded.
     */
    public function setCache(ClassMetadatasCache $cache): void
    {
        $this->cache = $cache;
    }

    public function hasMetadataFor(string $className): bool
    {
        $className = $this->getRealClass($this->normalizeClassName($className));

        return isset($this->resolvedTargetEntities[$className]) || $this->getClassMetadatas()->has($className);
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        $className = $this->getRealClass($this->normalizeClassName($className));

        if (isset($this->resolvedTargetEntities[$className])) {
            return $this->resolvedTargetEntities[$className];
        }

        try {
            return $this->getClassMetadatas()->get($className);
        } catch (PersistenceMappingException $notFound) {
            if (! $this->initialized) {
                $this->initialize();
            }

            $fallbackMetadata = $this->onNotFoundMetadata($className);

            if ($fallbackMetadata === null) {
                throw $notFound;
            }

            return $this->resolvedTargetEntities[$className] = $fallbackMetadata;
        }
    }

    public function setMetadataFor(string $className, ClassMetadataInterface $class): void
    {
        throw new LogicException(sprintf(
            '%s::setMetadataFor() is not supported; the underlying snapshot is read-only.',
            self::class,
        ));
    }

    /** @return list<ClassMetadata<object>> */
    public function getAllMetadata(): array
    {
        return array_values($this->getLoadedMetadata());
    }

    /** @return array<class-string, ClassMetadata<object>> */
    public function getLoadedMetadata(): array
    {
        return [...$this->getClassMetadatas()->all(), ...$this->resolvedTargetEntities];
    }

    public function isTransient(string $className): bool
    {
        if (class_exists($className, false) && (new ReflectionClass($className))->isAnonymous()) {
            return false;
        }

        if (! class_exists($className, false) && str_contains($className, ':')) {
            throw MappingException::nonExistingClass($className);
        }

        return ! $this->hasMetadataFor($className);
    }

    /**
     * Whether this class is mapped by the ORM.
     *
     * Every reachable class, including embeddables, is already present in the snapshot once
     * built, so this is a plain snapshot lookup — unlike the lazy factory, there is no mapping
     * driver left to fall back to.
     *
     * @param class-string $className
     */
    public function isMapped(string $className): bool
    {
        return $this->hasMetadataFor($className);
    }

    private function getClassMetadatas(): ClassMetadataBag
    {
        if ($this->classMetadatas !== null) {
            return $this->classMetadatas;
        }

        if ($this->cache !== null) {
            $this->classMetadatas = $this->cache->load();

            if ($this->classMetadatas !== null) {
                $this->classMetadatas->wakeupReflection($this->getReflectionService());
            }
        }

        if ($this->classMetadatas === null) {
            // Assigned before compile() returns: a class's metadata loading may, through
            // onLoadMetadata event listeners or embedded-class resolution, re-enter this factory
            // for another class before the whole snapshot is built. Exposing the in-progress
            // compiler itself as the (not yet complete) ClassMetadataBag lets that resolve.
            $compiler             = new ClassMetadataBagCompiler($this->getEntityManager(), $this->getReflectionService());
            $this->classMetadatas = $compiler;
            $this->classMetadatas = $compiler->compile();

            $this->cache?->save($this->classMetadatas);
        }

        return $this->classMetadatas;
    }
}
