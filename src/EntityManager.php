<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use BackedEnum;
use DateTimeInterface;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\Exception\InvalidHydrationMode;
use Doctrine\ORM\Exception\MissingIdentifierField;
use Doctrine\ORM\Exception\MissingMappingDriverImplementation;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Exception\UnrecognizedIdentifierFields;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Repository\RepositoryFactory;
use Override;

use function array_keys;
use function is_array;
use function is_object;
use function ltrim;

/**
 * The EntityManager is the central access point to ORM functionality.
 *
 * It is a facade to all different ORM subsystems such as UnitOfWork,
 * Query Language and Repository API. The quickest way to obtain a fully
 * configured EntityManager is:
 *
 *     use Doctrine\ORM\Tools\ORMSetup;
 *     use Doctrine\ORM\EntityManager;
 *
 *     $paths = ['/path/to/entity/mapping/files'];
 *
 *     $config = ORMSetup::createAttributeMetadataConfig($paths);
 *     $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
 *     $entityManager = new EntityManager($connection, $config);
 *
 * For more information see
 * {@link http://docs.doctrine-project.org/projects/doctrine-orm/en/stable/reference/configuration.html}
 *
 * You should never attempt to inherit from the EntityManager: Inheritance
 * is not a valid extension point for the EntityManager. Instead you
 * should take a look at the {@see \Doctrine\ORM\Decorator\EntityManagerDecorator}
 * and wrap your entity manager in a decorator.
 *
 * @final
 */
class EntityManager implements EntityManagerInterface
{
    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     */
    private ClassMetadataFactory $metadataFactory;

    /**
     * The UnitOfWork used to coordinate object-level transactions.
     */
    private UnitOfWork $unitOfWork;

    /**
     * The event manager that is the central point of the event system.
     */
    private EventManagerInterface $eventManager;

    /**
     * The proxy factory used to create dynamic proxies.
     */
    private ProxyFactory $proxyFactory;

    /**
     * The repository factory used to create dynamic repositories.
     */
    private RepositoryFactory $repositoryFactory;

    /**
     * The expression builder instance used to generate query expressions.
     */
    private Expr|null $expressionBuilder = null;

    /**
     * Whether the EntityManager is closed or not.
     */
    private bool $closed = false;

    /**
     * Collection of query filters.
     */
    private FilterCollection|null $filterCollection = null;

    /**
     * The second level cache regions API.
     */
    private Cache|null $cache = null;

    /**
     * Creates a new EntityManager that operates on the given database connection
     * and uses the given Configuration and EventManager implementations.
     *
     * @param Connection $conn The database connection used by the EntityManager.
     */
    public function __construct(
        private Connection $conn,
        private Configuration $config,
        EventManagerInterface|null $eventManager = null,
    ) {
        if (! $config->getMetadataDriverImpl()) {
            throw MissingMappingDriverImplementation::create();
        }

        $this->eventManager = $eventManager ?? new EventManager();

        $metadataFactoryClassName = $config->getClassMetadataFactoryName();

        $this->metadataFactory = new $metadataFactoryClassName();
        $this->metadataFactory->setEntityManager($this);

        $this->configureMetadataCache();

        $this->repositoryFactory = $config->getRepositoryFactory();
        $this->unitOfWork        = new UnitOfWork($this);
        $this->proxyFactory      = new ProxyFactory($this);

        if ($config->isSecondLevelCacheEnabled()) {
            $cacheConfig  = $config->getSecondLevelCacheConfiguration();
            $cacheFactory = $cacheConfig->getCacheFactory();
            $this->cache  = $cacheFactory->createCache($this);
        }
    }

    #[Override]
    public function getConnection(): Connection
    {
        return $this->conn;
    }

    #[Override]
    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->metadataFactory;
    }

    #[Override]
    public function getExpressionBuilder(): Expr
    {
        return $this->expressionBuilder ??= new Expr();
    }

    #[Override]
    public function beginTransaction(): void
    {
        $this->conn->beginTransaction();
    }

    #[Override]
    public function getCache(): Cache|null
    {
        return $this->cache;
    }

    #[Override]
    public function wrapInTransaction(callable $func): mixed
    {
        $this->conn->beginTransaction();

        $successful = false;

        try {
            $return = $func($this);

            $this->flush();
            $this->conn->commit();

            $successful = true;

            return $return;
        } finally {
            if (! $successful) {
                $this->close();
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            }
        }
    }

    #[Override]
    public function commit(): void
    {
        $this->conn->commit();
    }

    #[Override]
    public function rollback(): void
    {
        $this->conn->rollBack();
    }

    /**
     * Returns the ORM metadata descriptor for a class.
     *
     * Internal note: Performance-sensitive method.
     *
     * {@inheritDoc}
     */
    #[Override]
    public function getClassMetadata(string $className): Mapping\ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    #[Override]
    public function createQuery(string $dql = ''): Query
    {
        $query = new Query($this);

        if (! empty($dql)) {
            $query->setDQL($dql);
        }

        return $query;
    }

    #[Override]
    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery
    {
        $query = new NativeQuery($this);

        $query->setSQL($sql);
        $query->setResultSetMapping($rsm);

        return $query;
    }

    #[Override]
    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * Flushes all changes to objects that have been queued up to now to the database.
     * This effectively synchronizes the in-memory state of managed objects with the
     * database.
     *
     * If an entity is explicitly passed to this method only this entity and
     * the cascade-persist semantics + scheduled inserts/removals are synchronized.
     *
     * @throws OptimisticLockException If a version check on an entity that
     * makes use of optimistic locking fails.
     * @throws ORMException
     */
    #[Override]
    public function flush(): void
    {
        $this->errorIfClosed();
        $this->unitOfWork->commit();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function find($className, mixed $id, LockMode $lockMode = LockMode::NONE, int|null $lockVersion = null): object|null
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($className, '\\'));

        $this->checkLockRequirements($lockMode, $class);

        if (! is_array($id)) {
            if ($class->isIdentifierComposite) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        foreach ($id as $i => $value) {
            if (is_object($value)) {
                $className = $value::class;
                if ($this->metadataFactory->hasMetadataFor($className)) {
                    $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value);

                    if ($id[$i] === null) {
                        throw ORMInvalidArgumentException::invalidIdentifierBindingEntity($className);
                    }
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if (! isset($id[$identifier])) {
                throw MissingIdentifierField::fromFieldAndClass($identifier, $class->name);
            }

            if ($id[$identifier] instanceof BackedEnum) {
                $sortedId[$identifier] = $id[$identifier]->value;
            } else {
                $sortedId[$identifier] = $id[$identifier];
            }

            unset($id[$identifier]);
        }

        if ($id) {
            throw UnrecognizedIdentifierFields::fromClassAndFieldNames($class->name, array_keys($id));
        }

        $unitOfWork = $this->getUnitOfWork();

        $entity = $unitOfWork->tryGetById($sortedId, $class->rootEntityName);

        // Check identity map first
        if ($entity !== false) {
            if (! ($entity instanceof $class->name)) {
                return null;
            }

            switch (true) {
                case $lockMode === LockMode::OPTIMISTIC:
                    $this->lock($entity, $lockMode, $lockVersion);
                    break;

                case $lockMode === LockMode::PESSIMISTIC_READ:
                case $lockMode === LockMode::PESSIMISTIC_WRITE:
                    $persister = $unitOfWork->getEntityPersister($class->name);
                    $persister->refresh($sortedId, $entity, $lockMode);
                    break;
            }

            return $entity; // Hit!
        }

        $persister = $unitOfWork->getEntityPersister($class->name);

        switch (true) {
            case $lockMode === LockMode::OPTIMISTIC:
                $entity = $persister->load($sortedId);

                if ($entity !== null) {
                    $unitOfWork->lock($entity, $lockMode, $lockVersion);
                }

                return $entity;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                return $persister->load($sortedId, null, null, [], $lockMode);

            default:
                return $persister->loadById($sortedId);
        }
    }

    #[Override]
    public function getReference(string $entityName, mixed $id): object|null
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($entityName, '\\'));

        if (! is_array($id)) {
            $id = [$class->identifier[0] => $id];
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if (! isset($id[$identifier])) {
                throw MissingIdentifierField::fromFieldAndClass($identifier, $class->name);
            }

            $sortedId[$identifier] = $id[$identifier];
            unset($id[$identifier]);
        }

        if ($id) {
            throw UnrecognizedIdentifierFields::fromClassAndFieldNames($class->name, array_keys($id));
        }

        $entity = $this->unitOfWork->tryGetById($sortedId, $class->rootEntityName);

        // Check identity map first, if its already in there just return it.
        if ($entity !== false) {
            return $entity instanceof $class->name ? $entity : null;
        }

        if ($class->subClasses) {
            return $this->find($entityName, $sortedId);
        }

        $entity = $this->proxyFactory->getProxy($class->name, $sortedId);

        $this->unitOfWork->registerManagedProxy($entity, $sortedId);

        return $entity;
    }

    /**
     * Clears the EntityManager. All entities that are currently managed
     * by this EntityManager become detached.
     */
    #[Override]
    public function clear(): void
    {
        $this->unitOfWork->clear();
    }

    #[Override]
    public function close(): void
    {
        $this->clear();

        $this->closed = true;
    }

    /**
     * Tells the EntityManager to make an instance managed and persistent.
     *
     * The entity will be entered into the database at or before transaction
     * commit or as a result of the flush operation.
     *
     * NOTE: The persist operation always considers entities that are not yet known to
     * this EntityManager as NEW. Do not pass detached entities to the persist operation.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    #[Override]
    public function persist(object $object): void
    {
        $this->errorIfClosed();

        $this->unitOfWork->persist($object);
    }

    /**
     * Removes an entity instance.
     *
     * A removed entity will be removed from the database at or before transaction commit
     * or as a result of the flush operation.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    #[Override]
    public function remove(object $object): void
    {
        $this->errorIfClosed();

        $this->unitOfWork->remove($object);
    }

    #[Override]
    public function refresh(object $object, LockMode $lockMode = LockMode::NONE): void
    {
        $this->errorIfClosed();

        $this->unitOfWork->refresh($object, $lockMode);
    }

    /**
     * Detaches an entity from the EntityManager, causing a managed entity to
     * become detached.  Unflushed changes made to the entity if any
     * (including removal of the entity), will not be synchronized to the database.
     * Entities which previously referenced the detached entity will continue to
     * reference it.
     *
     * @throws ORMInvalidArgumentException
     */
    #[Override]
    public function detach(object $object): void
    {
        $this->unitOfWork->detach($object);
    }

    #[Override]
    public function lock(object $entity, LockMode $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->unitOfWork->lock($entity, $lockMode, $lockVersion);
    }

    /**
     * Gets the repository for an entity class.
     *
     * @param class-string<T> $className The name of the entity.
     *
     * @return EntityRepository<T> The repository class.
     *
     * @template T of object
     */
    #[Override]
    public function getRepository(string $className): EntityRepository
    {
        return $this->repositoryFactory->getRepository($this, $className);
    }

    /**
     * Determines whether an entity instance is managed in this EntityManager.
     *
     * @return bool TRUE if this EntityManager currently manages the given entity, FALSE otherwise.
     */
    #[Override]
    public function contains(object $object): bool
    {
        return $this->unitOfWork->isScheduledForInsert($object)
            || $this->unitOfWork->isInIdentityMap($object)
            && ! $this->unitOfWork->isScheduledForDelete($object);
    }

    #[Override]
    public function getEventManager(): EventManagerInterface
    {
        return $this->eventManager;
    }

    #[Override]
    public function getConfiguration(): Configuration
    {
        return $this->config;
    }

    /**
     * Throws an exception if the EntityManager is closed or currently not active.
     *
     * @throws EntityManagerClosed If the EntityManager is closed.
     */
    private function errorIfClosed(): void
    {
        if ($this->closed) {
            throw EntityManagerClosed::create();
        }
    }

    #[Override]
    public function isOpen(): bool
    {
        return ! $this->closed;
    }

    #[Override]
    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    #[Override]
    public function newHydrator(string|int $hydrationMode): AbstractHydrator
    {
        return match ($hydrationMode) {
            Query::HYDRATE_OBJECT => new Internal\Hydration\ObjectHydrator($this),
            Query::HYDRATE_ARRAY => new Internal\Hydration\ArrayHydrator($this),
            Query::HYDRATE_SCALAR => new Internal\Hydration\ScalarHydrator($this),
            Query::HYDRATE_SINGLE_SCALAR => new Internal\Hydration\SingleScalarHydrator($this),
            Query::HYDRATE_SIMPLEOBJECT => new Internal\Hydration\SimpleObjectHydrator($this),
            Query::HYDRATE_SCALAR_COLUMN => new Internal\Hydration\ScalarColumnHydrator($this),
            default => $this->createCustomHydrator((string) $hydrationMode),
        };
    }

    #[Override]
    public function getProxyFactory(): ProxyFactory
    {
        return $this->proxyFactory;
    }

    #[Override]
    public function initializeObject(object $obj): void
    {
        $this->unitOfWork->initializeObject($obj);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function isUninitializedObject($value): bool
    {
        return $this->unitOfWork->isUninitializedObject($value);
    }

    #[Override]
    public function getFilters(): FilterCollection
    {
        return $this->filterCollection ??= new FilterCollection($this);
    }

    #[Override]
    public function isFiltersStateClean(): bool
    {
        return $this->filterCollection === null || $this->filterCollection->isClean();
    }

    #[Override]
    public function hasFilters(): bool
    {
        return $this->filterCollection !== null;
    }

    /**
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    private function checkLockRequirements(LockMode $lockMode, ClassMetadata $class): void
    {
        switch ($lockMode) {
            case LockMode::OPTIMISTIC:
                if (! $class->isVersioned) {
                    throw OptimisticLockException::notVersioned($class->name);
                }

                break;
            case LockMode::PESSIMISTIC_READ:
            case LockMode::PESSIMISTIC_WRITE:
                if (! $this->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }
        }
    }

    private function configureMetadataCache(): void
    {
        $metadataCache = $this->config->getMetadataCache();
        if (! $metadataCache) {
            return;
        }

        $this->metadataFactory->setCache($metadataCache);
    }

    private function createCustomHydrator(string $hydrationMode): AbstractHydrator
    {
        $class = $this->config->getCustomHydrationMode($hydrationMode);

        if ($class !== null) {
            return new $class($this);
        }

        throw InvalidHydrationMode::fromMode($hydrationMode);
    }
}
