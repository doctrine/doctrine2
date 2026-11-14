<?php

declare(strict_types=1);

namespace Doctrine\Performance\Mock;

use DateTimeInterface;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\UnitOfWork;

/**
 * An entity manager mock that prevents lazy-loading of proxies
 */
class NonProxyLoadingEntityManager implements EntityManagerInterface
{
    public function __construct(private readonly EntityManagerInterface $realEntityManager)
    {
    }

    public function getProxyFactory(): ProxyFactory
    {
        $config = $this->realEntityManager->getConfiguration();

        return new ProxyFactory($this);
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->realEntityManager->getMetadataFactory();
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->realEntityManager->getClassMetadata($className);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return new NonProxyLoadingUnitOfWork($this);
    }

    public function getCache(): Cache|null
    {
        return $this->realEntityManager->getCache();
    }

    public function getConnection(): Connection
    {
        return $this->realEntityManager->getConnection();
    }

    public function getExpressionBuilder(): Expr
    {
        return $this->realEntityManager->getExpressionBuilder();
    }

    public function beginTransaction(): void
    {
        $this->realEntityManager->beginTransaction();
    }

    public function wrapInTransaction(callable $func): mixed
    {
        return $this->realEntityManager->wrapInTransaction($func);
    }

    public function commit(): void
    {
        $this->realEntityManager->commit();
    }

    public function rollback(): void
    {
        $this->realEntityManager->rollback();
    }

    public function createQuery(string $dql = ''): Query
    {
        return $this->realEntityManager->createQuery($dql);
    }

    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery
    {
        return $this->realEntityManager->createNativeQuery($sql, $rsm);
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->realEntityManager->createQueryBuilder();
    }

    public function getReference(string $entityName, mixed $id): object|null
    {
        return $this->realEntityManager->getReference($entityName, $id);
    }

    public function close(): void
    {
        $this->realEntityManager->close();
    }

    public function lock(object $entity, LockMode $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->realEntityManager->lock($entity, $lockMode, $lockVersion);
    }

    public function getEventManager(): EventManager
    {
        return $this->realEntityManager->getEventManager();
    }

    public function getConfiguration(): Configuration
    {
        return $this->realEntityManager->getConfiguration();
    }

    public function isOpen(): bool
    {
        return $this->realEntityManager->isOpen();
    }

    /**
     * {@inheritDoc}
     */
    public function newHydrator($hydrationMode): AbstractHydrator
    {
        return $this->realEntityManager->newHydrator($hydrationMode);
    }

    public function getFilters(): FilterCollection
    {
        return $this->realEntityManager->getFilters();
    }

    public function isFiltersStateClean(): bool
    {
        return $this->realEntityManager->isFiltersStateClean();
    }

    public function hasFilters(): bool
    {
        return $this->realEntityManager->hasFilters();
    }

    public function find(string $className, mixed $id, LockMode|null $lockMode = null, int|null $lockVersion = null): object|null
    {
        return $this->realEntityManager->find($className, $id, $lockMode, $lockVersion);
    }

    public function persist(object $object): void
    {
        $this->realEntityManager->persist($object);
    }

    public function remove(object $object): void
    {
        $this->realEntityManager->remove($object);
    }

    public function clear(): void
    {
        $this->realEntityManager->clear();
    }

    public function detach(object $object): void
    {
        $this->realEntityManager->detach($object);
    }

    public function refresh(object $object, LockMode|null $lockMode = null): void
    {
        $this->realEntityManager->refresh($object, $lockMode);
    }

    public function flush(): void
    {
        $this->realEntityManager->flush();
    }

    public function getRepository(string $className): EntityRepository
    {
        return $this->realEntityManager->getRepository($className);
    }

    public function initializeObject(object $obj): void
    {
        $this->realEntityManager->initializeObject($obj);
    }

    public function contains(object $object): bool
    {
        return $this->realEntityManager->contains($object);
    }

    /**
     * {@inheritDoc}
     */
    public function isUninitializedObject($value): bool
    {
        return $this->realEntityManager->isUninitializedObject($value);
    }
}
