<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\Persister\Collection\CachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\NonStrictReadWriteCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\ReadOnlyCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\ReadWriteCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Entity\CachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\NonStrictReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadOnlyCachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Cache\Region\FileLockRegion;
use Doctrine\ORM\Cache\Region\UpdateTimestampCache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Collection\CollectionPersister;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use InvalidArgumentException;
use LogicException;
use Override;
use Psr\Cache\CacheItemPoolInterface;

use function assert;
use function sprintf;

use const DIRECTORY_SEPARATOR;

class DefaultCacheFactory implements CacheFactory
{
    private TimestampRegion|null $timestampRegion = null;

    /** @var Region[] */
    private array $regions = [];

    private string|null $fileLockRegionDirectory = null;

    public function __construct(private readonly RegionsConfiguration $regionsConfig, private readonly CacheItemPoolInterface $cacheItemPool)
    {
    }

    public function setFileLockRegionDirectory(string $fileLockRegionDirectory): void
    {
        $this->fileLockRegionDirectory = $fileLockRegionDirectory;
    }

    public function getFileLockRegionDirectory(): string|null
    {
        return $this->fileLockRegionDirectory;
    }

    public function setRegion(Region $region): void
    {
        $this->regions[$region->getName()] = $region;
    }

    public function setTimestampRegion(TimestampRegion $region): void
    {
        $this->timestampRegion = $region;
    }

    #[Override]
    public function buildCachedEntityPersister(EntityManagerInterface $em, EntityPersister $persister, ClassMetadata $metadata): CachedEntityPersister
    {
        assert($metadata->cache !== null);
        $region = $this->getRegion($metadata->cache);
        $usage  = $metadata->cache['usage'];

        if ($usage === ClassMetadata::CACHE_USAGE_READ_ONLY) {
            return new ReadOnlyCachedEntityPersister($persister, $region, $em, $metadata);
        }

        if ($usage === ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE) {
            return new NonStrictReadWriteCachedEntityPersister($persister, $region, $em, $metadata);
        }

        if ($usage === ClassMetadata::CACHE_USAGE_READ_WRITE) {
            if (! $region instanceof ConcurrentRegion) {
                throw new InvalidArgumentException(sprintf('Unable to use access strategy type of [%s] without a ConcurrentRegion', $usage));
            }

            return new ReadWriteCachedEntityPersister($persister, $region, $em, $metadata);
        }

        throw new InvalidArgumentException(sprintf('Unrecognized access strategy type [%s]', $usage));
    }

    #[Override]
    public function buildCachedCollectionPersister(
        EntityManagerInterface $em,
        CollectionPersister $persister,
        AssociationMapping $mapping,
    ): CachedCollectionPersister {
        assert(isset($mapping->cache));
        $usage  = $mapping->cache['usage'];
        $region = $this->getRegion($mapping->cache);

        if ($usage === ClassMetadata::CACHE_USAGE_READ_ONLY) {
            return new ReadOnlyCachedCollectionPersister($persister, $region, $em, $mapping);
        }

        if ($usage === ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE) {
            return new NonStrictReadWriteCachedCollectionPersister($persister, $region, $em, $mapping);
        }

        if ($usage === ClassMetadata::CACHE_USAGE_READ_WRITE) {
            if (! $region instanceof ConcurrentRegion) {
                throw new InvalidArgumentException(sprintf('Unable to use access strategy type of [%s] without a ConcurrentRegion', $usage));
            }

            return new ReadWriteCachedCollectionPersister($persister, $region, $em, $mapping);
        }

        throw new InvalidArgumentException(sprintf('Unrecognized access strategy type [%s]', $usage));
    }

    #[Override]
    public function buildQueryCache(EntityManagerInterface $em, string|null $regionName = null): QueryCache
    {
        return new DefaultQueryCache(
            $em,
            $this->getRegion(
                [
                    'region' => $regionName ?: Cache::DEFAULT_QUERY_REGION_NAME,
                    'usage'  => ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE,
                ],
            ),
        );
    }

    #[Override]
    public function buildCollectionHydrator(EntityManagerInterface $em, AssociationMapping $mapping): CollectionHydrator
    {
        return new DefaultCollectionHydrator($em);
    }

    #[Override]
    public function buildEntityHydrator(EntityManagerInterface $em, ClassMetadata $metadata): EntityHydrator
    {
        return new DefaultEntityHydrator($em);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getRegion(array $cache): Region
    {
        if (isset($this->regions[$cache['region']])) {
            return $this->regions[$cache['region']];
        }

        $name     = $cache['region'];
        $lifetime = $this->regionsConfig->getLifetime($cache['region']);
        $region   = new DefaultRegion($name, $this->cacheItemPool, $lifetime);

        if ($cache['usage'] === ClassMetadata::CACHE_USAGE_READ_WRITE) {
            if (
                $this->fileLockRegionDirectory === '' ||
                $this->fileLockRegionDirectory === null
            ) {
                throw new LogicException(
                    'If you want to use a "READ_WRITE" cache an implementation of "Doctrine\ORM\Cache\ConcurrentRegion" is required, ' .
                    'The default implementation provided by doctrine is "Doctrine\ORM\Cache\Region\FileLockRegion" if you want to use it please provide a valid directory, DefaultCacheFactory#setFileLockRegionDirectory(). ',
                );
            }

            $directory = $this->fileLockRegionDirectory . DIRECTORY_SEPARATOR . $cache['region'];
            $region    = new FileLockRegion($region, $directory, (string) $this->regionsConfig->getLockLifetime($cache['region']));
        }

        return $this->regions[$cache['region']] = $region;
    }

    #[Override]
    public function getTimestampRegion(): TimestampRegion
    {
        if ($this->timestampRegion === null) {
            $name     = Cache::DEFAULT_TIMESTAMP_REGION_NAME;
            $lifetime = $this->regionsConfig->getLifetime($name);

            $this->timestampRegion = new UpdateTimestampCache($name, $this->cacheItemPool, $lifetime);
        }

        return $this->timestampRegion;
    }

    #[Override]
    public function createCache(EntityManagerInterface $entityManager): Cache
    {
        return new DefaultCache($entityManager);
    }
}
