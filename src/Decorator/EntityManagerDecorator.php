<?php

declare(strict_types=1);

namespace Doctrine\ORM\Decorator;

use DateTimeInterface;
use Doctrine\Common\EventManagerInterface;
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
use Doctrine\Persistence\ObjectManagerDecorator;
use Override;

/**
 * Base class for EntityManager decorators
 *
 * @extends ObjectManagerDecorator<EntityManagerInterface>
 */
abstract class EntityManagerDecorator extends ObjectManagerDecorator implements EntityManagerInterface
{
    public function __construct(EntityManagerInterface $wrapped)
    {
        $this->wrapped = $wrapped;
    }

    #[Override]
    public function getRepository(string $className): EntityRepository
    {
        return $this->wrapped->getRepository($className);
    }

    #[Override]
    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->wrapped->getMetadataFactory();
    }

    #[Override]
    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->wrapped->getClassMetadata($className);
    }

    #[Override]
    public function getConnection(): Connection
    {
        return $this->wrapped->getConnection();
    }

    #[Override]
    public function getExpressionBuilder(): Expr
    {
        return $this->wrapped->getExpressionBuilder();
    }

    #[Override]
    public function beginTransaction(): void
    {
        $this->wrapped->beginTransaction();
    }

    #[Override]
    public function wrapInTransaction(callable $func): mixed
    {
        return $this->wrapped->wrapInTransaction($func);
    }

    #[Override]
    public function commit(): void
    {
        $this->wrapped->commit();
    }

    #[Override]
    public function rollback(): void
    {
        $this->wrapped->rollback();
    }

    #[Override]
    public function createQuery(string $dql = ''): Query
    {
        return $this->wrapped->createQuery($dql);
    }

    #[Override]
    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery
    {
        return $this->wrapped->createNativeQuery($sql, $rsm);
    }

    #[Override]
    public function createQueryBuilder(): QueryBuilder
    {
        return $this->wrapped->createQueryBuilder();
    }

    #[Override]
    public function getReference(string $entityName, mixed $id): object|null
    {
        return $this->wrapped->getReference($entityName, $id);
    }

    #[Override]
    public function close(): void
    {
        $this->wrapped->close();
    }

    #[Override]
    public function lock(object $entity, LockMode $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->wrapped->lock($entity, $lockMode, $lockVersion);
    }

    #[Override]
    public function find(string $className, mixed $id, LockMode $lockMode = LockMode::NONE, int|null $lockVersion = null): object|null
    {
        return $this->wrapped->find($className, $id, $lockMode, $lockVersion);
    }

    #[Override]
    public function refresh(object $object, LockMode $lockMode = LockMode::NONE): void
    {
        $this->wrapped->refresh($object, $lockMode);
    }

    #[Override]
    public function getEventManager(): EventManagerInterface
    {
        return $this->wrapped->getEventManager();
    }

    #[Override]
    public function getConfiguration(): Configuration
    {
        return $this->wrapped->getConfiguration();
    }

    #[Override]
    public function isOpen(): bool
    {
        return $this->wrapped->isOpen();
    }

    #[Override]
    public function getUnitOfWork(): UnitOfWork
    {
        return $this->wrapped->getUnitOfWork();
    }

    #[Override]
    public function newHydrator(string|int $hydrationMode): AbstractHydrator
    {
        return $this->wrapped->newHydrator($hydrationMode);
    }

    #[Override]
    public function getProxyFactory(): ProxyFactory
    {
        return $this->wrapped->getProxyFactory();
    }

    #[Override]
    public function getFilters(): FilterCollection
    {
        return $this->wrapped->getFilters();
    }

    #[Override]
    public function isFiltersStateClean(): bool
    {
        return $this->wrapped->isFiltersStateClean();
    }

    #[Override]
    public function hasFilters(): bool
    {
        return $this->wrapped->hasFilters();
    }

    #[Override]
    public function getCache(): Cache|null
    {
        return $this->wrapped->getCache();
    }
}
