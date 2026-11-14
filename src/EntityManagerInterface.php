<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use DateTimeInterface;
use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Persistence\ObjectManager;
use Override;

/**
 * Finds, queries and persists entities, and flushes their changes to the database.
 */
interface EntityManagerInterface extends ObjectManager
{
    /**
     * {@inheritDoc}
     *
     * @param class-string<T> $className
     *
     * @return EntityRepository<T>
     *
     * @template T of object
     */
    #[Override]
    public function getRepository(string $className): EntityRepository;

    /**
     * Returns the cache API for managing the second level cache regions or NULL if the cache is not enabled.
     */
    public function getCache(): Cache|null;

    /**
     * Gets the database connection object used by the EntityManager.
     */
    public function getConnection(): Connection;

    #[Override]
    public function getMetadataFactory(): ClassMetadataFactory;

    /**
     * Gets an ExpressionBuilder used for object-oriented construction of query expressions.
     *
     * Example:
     *
     * <code>
     *     $qb = $em->createQueryBuilder();
     *     $expr = $em->getExpressionBuilder();
     *     $qb->select('u')->from('User', 'u')
     *         ->where($expr->orX($expr->eq('u.id', 1), $expr->eq('u.id', 2)));
     * </code>
     */
    public function getExpressionBuilder(): Expr;

    /**
     * Starts a transaction on the underlying database connection.
     */
    public function beginTransaction(): void;

    /**
     * Executes a function in a transaction.
     *
     * The function gets passed this EntityManager instance as an (optional) parameter.
     *
     * {@link flush} is invoked prior to transaction commit.
     *
     * If an exception occurs during execution of the function or flushing or transaction commit,
     * the transaction is rolled back, the EntityManager closed and the exception re-thrown.
     *
     * @phpstan-param callable(self): T $func The function to execute transactionally.
     *
     * @return mixed The value returned from the closure.
     * @phpstan-return T
     *
     * @param-immediately-invoked-callable $func
     * @template T
     */
    public function wrapInTransaction(callable $func): mixed;

    /**
     * Commits a transaction on the underlying database connection.
     */
    public function commit(): void;

    /**
     * Performs a rollback on the underlying database connection.
     */
    public function rollback(): void;

    /**
     * Creates a new Query object.
     *
     * @param string $dql The DQL string.
     */
    public function createQuery(string $dql = ''): Query;

    /**
     * Creates a native SQL query.
     */
    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery;

    /**
     * Create a QueryBuilder instance
     */
    public function createQueryBuilder(): QueryBuilder;

    /**
     * Finds an Entity by its identifier.
     *
     * @param string   $className   The class name of the entity to find.
     * @param mixed    $id          The identity of the entity to find.
     * @param LockMode $lockMode    One of the \Doctrine\DBAL\LockMode::* cases.
     * @param int|null $lockVersion The version of the entity to find when using
     *                              optimistic locking.
     * @phpstan-param class-string<T> $className
     *
     * @return object|null The entity instance or NULL if the entity can not be found.
     * @phpstan-return T|null
     *
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     *
     * @template T of object
     */
    #[Override]
    public function find(string $className, mixed $id, LockMode $lockMode = LockMode::NONE, int|null $lockVersion = null): object|null;

    /**
     * Refreshes the persistent state of an object from the database,
     * overriding any local changes that have not yet been persisted.
     *
     * @param LockMode $lockMode One of the \Doctrine\DBAL\LockMode::* cases.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     * @throws TransactionRequiredException
     */
    #[Override]
    public function refresh(object $object, LockMode $lockMode = LockMode::NONE): void;

    /**
     * Gets a reference to the entity identified by the given type and identifier
     * without actually loading it, if the entity is not yet loaded.
     *
     * @param class-string<T> $entityName The name of the entity type.
     * @param mixed           $id         The entity identifier.
     *
     * @return T|null The entity reference.
     *
     * @throws ORMException
     *
     * @template T of object
     */
    public function getReference(string $entityName, mixed $id): object|null;

    /**
     * Closes the EntityManager. All entities that are currently managed
     * by this EntityManager become detached. The EntityManager may no longer
     * be used after it is closed.
     */
    public function close(): void;

    /**
     * Acquire a lock on the given entity.
     *
     * @throws OptimisticLockException
     * @throws PessimisticLockException
     */
    public function lock(object $entity, LockMode $lockMode, DateTimeInterface|int|null $lockVersion = null): void;

    /**
     * Gets the EventManagerInterface used by the EntityManager.
     */
    public function getEventManager(): EventManagerInterface;

    /**
     * Gets the Configuration used by the EntityManager.
     */
    public function getConfiguration(): Configuration;

    /**
     * Check if the Entity manager is open or closed.
     */
    public function isOpen(): bool;

    /**
     * Gets the UnitOfWork used by the EntityManager to coordinate operations.
     */
    public function getUnitOfWork(): UnitOfWork;

    /**
     * Create a new instance for the given hydration mode.
     *
     * @phpstan-param string|AbstractQuery::HYDRATE_* $hydrationMode
     *
     * @throws ORMException
     */
    public function newHydrator(string|int $hydrationMode): AbstractHydrator;

    /**
     * Gets the proxy factory used by the EntityManager to create entity proxies.
     */
    public function getProxyFactory(): ProxyFactory;

    /**
     * Gets the enabled filters.
     */
    public function getFilters(): FilterCollection;

    /**
     * Checks whether the state of the filter collection is clean.
     */
    public function isFiltersStateClean(): bool;

    /**
     * Checks whether the Entity Manager has filters.
     */
    public function hasFilters(): bool;

    /**
     * {@inheritDoc}
     *
     * @param string|class-string<T> $className
     *
     * @phpstan-return ($className is class-string<T> ? Mapping\ClassMetadata<T> : Mapping\ClassMetadata<object>)
     *
     * @phpstan-template T of object
     */
    #[Override]
    public function getClassMetadata(string $className): Mapping\ClassMetadata;
}
