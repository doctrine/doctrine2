<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use BadMethodCallException;
use Doctrine\Common\Cache\Psr6\CacheAdapter;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\Common\EventManager;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\Exception\InvalidHydrationMode;
use Doctrine\ORM\Exception\MismatchedEventManager;
use Doctrine\ORM\Exception\MissingIdentifierField;
use Doctrine\ORM\Exception\MissingMappingDriverImplementation;
use Doctrine\ORM\Exception\UnrecognizedIdentifierFields;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\ObjectRepository;
use InvalidArgumentException;
use Throwable;

use function array_keys;
use function call_user_func;
use function get_class;
use function gettype;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function ltrim;
use function method_exists;
use function sprintf;

/**
 * The EntityManager is the central access point to ORM functionality.
 *
 * It is a facade to all different ORM subsystems such as UnitOfWork,
 * Query Language and Repository API. Instantiation is done through
 * the static create() method. The quickest way to obtain a fully
 * configured EntityManager is:
 *
 *     use Doctrine\ORM\Tools\Setup;
 *     use Doctrine\ORM\EntityManager;
 *
 *     $paths = array('/path/to/entity/mapping/files');
 *
 *     $config = Setup::createAnnotationMetadataConfiguration($paths);
 *     $dbParams = array('driver' => 'pdo_sqlite', 'memory' => true);
 *     $entityManager = EntityManager::create($dbParams, $config);
 *
 * For more information see
 * {@link http://docs.doctrine-project.org/projects/doctrine-orm/en/latest/reference/configuration.html}
 *
 * You should never attempt to inherit from the EntityManager: Inheritance
 * is not a valid extension point for the EntityManager. Instead you
 * should take a look at the {@see \Doctrine\ORM\Decorator\EntityManagerDecorator}
 * and wrap your entity manager in a decorator.
 */
/* final */class EntityManager implements EntityManagerInterface
{
    /**
     * The used Configuration.
     *
     * @var Configuration
     */
    private $config;

    /**
     * The database connection used by the EntityManager.
     *
     * @var Connection
     */
    private $conn;

    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     *
     * @var ClassMetadataFactory
     */
    private $metadataFactory;

    /**
     * The UnitOfWork used to coordinate object-level transactions.
     *
     * @var UnitOfWork
     */
    private $unitOfWork;

    /**
     * The event manager that is the central point of the event system.
     *
     * @var EventManager
     */
    private $eventManager;

    /**
     * The proxy factory used to create dynamic proxies.
     *
     * @var ProxyFactory
     */
    private $proxyFactory;

    /**
     * The repository factory used to create dynamic repositories.
     *
     * @var RepositoryFactory
     */
    private $repositoryFactory;

    /**
     * The expression builder instance used to generate query expressions.
     *
     * @var Expr
     */
    private $expressionBuilder;

    /**
     * Whether the EntityManager is closed or not.
     *
     * @var bool
     */
    private $closed = false;

    /**
     * Collection of query filters.
     *
     * @var FilterCollection
     */
    private $filterCollection;

    /** @var Cache The second level cache regions API. */
    private $cache;

    /**
     * Creates a new EntityManager that operates on the given database connection
     * and uses the given Configuration and EventManager implementations.
     */
    protected function __construct(Connection $conn, Configuration $config, EventManager $eventManager)
    {
        $this->conn         = $conn;
        $this->config       = $config;
        $this->eventManager = $eventManager;

        $metadataFactoryClassName = $config->getClassMetadataFactoryName();

        $this->metadataFactory = new $metadataFactoryClassName();
        $this->metadataFactory->setEntityManager($this);

        $this->configureMetadataCache();

        $this->repositoryFactory = $config->getRepositoryFactory();
        $this->unitOfWork        = new UnitOfWork($this);
        $this->proxyFactory      = new ProxyFactory(
            $this,
            $config->getProxyDir(),
            $config->getProxyNamespace(),
            $config->getAutoGenerateProxyClasses()
        );

        if ($config->isSecondLevelCacheEnabled()) {
            $cacheConfig  = $config->getSecondLevelCacheConfiguration();
            $cacheFactory = $cacheConfig->getCacheFactory();
            $this->cache  = $cacheFactory->createCache($this);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->conn;
    }

    /**
     * Gets the metadata factory used to gather the metadata of classes.
     *
     * @return ClassMetadataFactory
     */
    public function getMetadataFactory()
    {
        return $this->metadataFactory;
    }

    /**
     * {@inheritDoc}
     */
    public function getExpressionBuilder()
    {
        if ($this->expressionBuilder === null) {
            $this->expressionBuilder = new Query\Expr();
        }

        return $this->expressionBuilder;
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        $this->conn->beginTransaction();
    }

    /**
     * {@inheritDoc}
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * {@inheritDoc}
     */
    public function transactional($func)
    {
        if (! is_callable($func)) {
            throw new InvalidArgumentException('Expected argument of type "callable", got "' . gettype($func) . '"');
        }

        $this->conn->beginTransaction();

        try {
            $return = call_user_func($func, $this);

            $this->flush();
            $this->conn->commit();

            return $return ?: true;
        } catch (Throwable $e) {
            $this->close();
            $this->conn->rollBack();

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function commit()
    {
        $this->conn->commit();
    }

    /**
     * {@inheritDoc}
     */
    public function rollback()
    {
        $this->conn->rollBack();
    }

    /**
     * Returns the ORM metadata descriptor for a class.
     *
     * The class name must be the fully-qualified class name without a leading backslash
     * (as it is returned by get_class($obj)) or an aliased class name.
     *
     * Examples:
     * MyProject\Domain\User
     * sales:PriceRequest
     *
     * Internal note: Performance-sensitive method.
     *
     * {@inheritDoc}
     */
    public function getClassMetadata($className)
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    /**
     * {@inheritDoc}
     */
    public function createQuery($dql = '')
    {
        $query = new Query($this);

        if (! empty($dql)) {
            $query->setDQL($dql);
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function createNamedQuery($name)
    {
        return $this->createQuery($this->config->getNamedQuery($name));
    }

    /**
     * {@inheritDoc}
     */
    public function createNativeQuery($sql, ResultSetMapping $rsm)
    {
        $query = new NativeQuery($this);

        $query->setSQL($sql);
        $query->setResultSetMapping($rsm);

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function createNamedNativeQuery($name)
    {
        [$sql, $rsm] = $this->config->getNamedNativeQuery($name);

        return $this->createNativeQuery($sql, $rsm);
    }

    /**
     * {@inheritDoc}
     */
    public function createQueryBuilder()
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
     * @param object|mixed[]|null $entity
     *
     * @return void
     *
     * @throws OptimisticLockException If a version check on an entity that
     * makes use of optimistic locking fails.
     * @throws ORMException
     */
    public function flush($entity = null)
    {
        if ($entity !== null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/issues/8459',
                'Calling %s() with any arguments to flush specific entities is deprecated and will not be supported in Doctrine ORM 3.0.',
                __METHOD__
            );
        }

        $this->errorIfClosed();

        $this->unitOfWork->commit($entity);
    }

    /**
     * Finds an Entity by its identifier.
     *
     * @param string   $className   The class name of the entity to find.
     * @param mixed    $id          The identity of the entity to find.
     * @param int|null $lockMode    One of the \Doctrine\DBAL\LockMode::* constants
     *    or NULL if no specific lock mode should be used
     *    during the search.
     * @param int|null $lockVersion The version of the entity to find when using
     * optimistic locking.
     * @psalm-param class-string<T> $className
     *
     * @return object|null The entity instance or NULL if the entity can not be found.
     * @psalm-return ?T
     *
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     *
     * @template T
     */
    public function find($className, $id, $lockMode = null, $lockVersion = null)
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($className, '\\'));

        if ($lockMode !== null) {
            $this->checkLockRequirements($lockMode, $class);
        }

        if (! is_array($id)) {
            if ($class->isIdentifierComposite) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        foreach ($id as $i => $value) {
            if (is_object($value) && $this->metadataFactory->hasMetadataFor(ClassUtils::getClass($value))) {
                $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value);

                if ($id[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
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

                case $lockMode === LockMode::NONE:
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

                $unitOfWork->lock($entity, $lockMode, $lockVersion);

                return $entity;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                return $persister->load($sortedId, null, null, [], $lockMode);

            default:
                return $persister->loadById($sortedId);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getReference($entityName, $id)
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

        $this->unitOfWork->registerManaged($entity, $sortedId, []);

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function getPartialReference($entityName, $identifier)
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($entityName, '\\'));

        $entity = $this->unitOfWork->tryGetById($identifier, $class->rootEntityName);

        // Check identity map first, if its already in there just return it.
        if ($entity !== false) {
            return $entity instanceof $class->name ? $entity : null;
        }

        if (! is_array($identifier)) {
            $identifier = [$class->identifier[0] => $identifier];
        }

        $entity = $class->newInstance();

        $class->setIdentifierValues($entity, $identifier);

        $this->unitOfWork->registerManaged($entity, $identifier, []);
        $this->unitOfWork->markReadOnly($entity);

        return $entity;
    }

    /**
     * Clears the EntityManager. All entities that are currently managed
     * by this EntityManager become detached.
     *
     * @param string|null $entityName if given, only entities of this type will get detached
     *
     * @return void
     *
     * @throws ORMInvalidArgumentException If a non-null non-string value is given.
     * @throws MappingException            If a $entityName is given, but that entity is not
     *                                     found in the mappings.
     */
    public function clear($entityName = null)
    {
        if ($entityName !== null && ! is_string($entityName)) {
            throw ORMInvalidArgumentException::invalidEntityName($entityName);
        }

        if ($entityName !== null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/issues/8460',
                'Calling %s() with any arguments to clear specific entities is deprecated and will not be supported in Doctrine ORM 3.0.',
                __METHOD__
            );
        }

        $this->unitOfWork->clear(
            $entityName === null
                ? null
                : $this->metadataFactory->getMetadataFor($entityName)->getName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function close()
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
     * @param object $entity The instance to make managed and persistent.
     *
     * @return void
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    public function persist($entity)
    {
        if (! is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#persist()', $entity);
        }

        $this->errorIfClosed();

        $this->unitOfWork->persist($entity);
    }

    /**
     * Removes an entity instance.
     *
     * A removed entity will be removed from the database at or before transaction commit
     * or as a result of the flush operation.
     *
     * @param object $entity The entity instance to remove.
     *
     * @return void
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    public function remove($entity)
    {
        if (! is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#remove()', $entity);
        }

        $this->errorIfClosed();

        $this->unitOfWork->remove($entity);
    }

    /**
     * Refreshes the persistent state of an entity from the database,
     * overriding any local changes that have not yet been persisted.
     *
     * @param object $entity The entity to refresh.
     *
     * @return void
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    public function refresh($entity)
    {
        if (! is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#refresh()', $entity);
        }

        $this->errorIfClosed();

        $this->unitOfWork->refresh($entity);
    }

    /**
     * Detaches an entity from the EntityManager, causing a managed entity to
     * become detached.  Unflushed changes made to the entity if any
     * (including removal of the entity), will not be synchronized to the database.
     * Entities which previously referenced the detached entity will continue to
     * reference it.
     *
     * @param object $entity The entity to detach.
     *
     * @return void
     *
     * @throws ORMInvalidArgumentException
     */
    public function detach($entity)
    {
        if (! is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#detach()', $entity);
        }

        $this->unitOfWork->detach($entity);
    }

    /**
     * Merges the state of a detached entity into the persistence context
     * of this EntityManager and returns the managed copy of the entity.
     * The entity passed to merge will not become associated/managed with this EntityManager.
     *
     * @deprecated 2.7 This method is being removed from the ORM and won't have any replacement
     *
     * @param object $entity The detached entity to merge into the persistence context.
     *
     * @return object The managed copy of the entity.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    public function merge($entity)
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8461',
            'Method %s() is deprecated and will be removed in Doctrine ORM 3.0.',
            __METHOD__
        );

        if (! is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#merge()', $entity);
        }

        $this->errorIfClosed();

        return $this->unitOfWork->merge($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function copy($entity, $deep = false)
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8462',
            'Method %s() is deprecated and will be removed in Doctrine ORM 3.0.',
            __METHOD__
        );

        throw new BadMethodCallException('Not implemented.');
    }

    /**
     * {@inheritDoc}
     */
    public function lock($entity, $lockMode, $lockVersion = null)
    {
        $this->unitOfWork->lock($entity, $lockMode, $lockVersion);
    }

    /**
     * Gets the repository for an entity class.
     *
     * @param string $entityName The name of the entity.
     * @psalm-param class-string<T> $entityName
     *
     * @return ObjectRepository|EntityRepository The repository class.
     * @psalm-return EntityRepository<T>
     *
     * @template T
     */
    public function getRepository($entityName)
    {
        return $this->repositoryFactory->getRepository($this, $entityName);
    }

    /**
     * Determines whether an entity instance is managed in this EntityManager.
     *
     * @param object $entity
     *
     * @return bool TRUE if this EntityManager currently manages the given entity, FALSE otherwise.
     */
    public function contains($entity)
    {
        return $this->unitOfWork->isScheduledForInsert($entity)
            || $this->unitOfWork->isInIdentityMap($entity)
            && ! $this->unitOfWork->isScheduledForDelete($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function getEventManager()
    {
        return $this->eventManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfiguration()
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

    /**
     * {@inheritDoc}
     */
    public function isOpen()
    {
        return ! $this->closed;
    }

    /**
     * {@inheritDoc}
     */
    public function getUnitOfWork()
    {
        return $this->unitOfWork;
    }

    /**
     * {@inheritDoc}
     */
    public function getHydrator($hydrationMode)
    {
        return $this->newHydrator($hydrationMode);
    }

    /**
     * {@inheritDoc}
     */
    public function newHydrator($hydrationMode)
    {
        switch ($hydrationMode) {
            case Query::HYDRATE_OBJECT:
                return new Internal\Hydration\ObjectHydrator($this);

            case Query::HYDRATE_ARRAY:
                return new Internal\Hydration\ArrayHydrator($this);

            case Query::HYDRATE_SCALAR:
                return new Internal\Hydration\ScalarHydrator($this);

            case Query::HYDRATE_SINGLE_SCALAR:
                return new Internal\Hydration\SingleScalarHydrator($this);

            case Query::HYDRATE_SIMPLEOBJECT:
                return new Internal\Hydration\SimpleObjectHydrator($this);

            default:
                $class = $this->config->getCustomHydrationMode($hydrationMode);

                if ($class !== null) {
                    return new $class($this);
                }
        }

        throw InvalidHydrationMode::fromMode((string) $hydrationMode);
    }

    /**
     * {@inheritDoc}
     */
    public function getProxyFactory()
    {
        return $this->proxyFactory;
    }

    /**
     * {@inheritDoc}
     */
    public function initializeObject($obj)
    {
        $this->unitOfWork->initializeObject($obj);
    }

    /**
     * Factory method to create EntityManager instances.
     *
     * @param mixed[]|Connection $connection   An array with the connection parameters or an existing Connection instance.
     * @param Configuration      $config       The Configuration instance to use.
     * @param EventManager|null  $eventManager The EventManager instance to use.
     * @psalm-param array<string, mixed>|Connection $connection
     *
     * @return EntityManager The created EntityManager.
     *
     * @throws InvalidArgumentException
     * @throws ORMException
     */
    public static function create($connection, Configuration $config, ?EventManager $eventManager = null)
    {
        if (! $config->getMetadataDriverImpl()) {
            throw MissingMappingDriverImplementation::create();
        }

        $connection = static::createConnection($connection, $config, $eventManager);

        return new EntityManager($connection, $config, $connection->getEventManager());
    }

    /**
     * Factory method to create Connection instances.
     *
     * @param mixed[]|Connection $connection   An array with the connection parameters or an existing Connection instance.
     * @param Configuration      $config       The Configuration instance to use.
     * @param EventManager|null  $eventManager The EventManager instance to use.
     * @psalm-param array<string, mixed>|Connection $connection
     *
     * @return Connection
     *
     * @throws InvalidArgumentException
     * @throws ORMException
     */
    protected static function createConnection($connection, Configuration $config, ?EventManager $eventManager = null)
    {
        if (is_array($connection)) {
            return DriverManager::getConnection($connection, $config, $eventManager ?: new EventManager());
        }

        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid $connection argument of type %s given%s.',
                    is_object($connection) ? get_class($connection) : gettype($connection),
                    is_object($connection) ? '' : ': "' . $connection . '"'
                )
            );
        }

        if ($eventManager !== null && $connection->getEventManager() !== $eventManager) {
            throw MismatchedEventManager::create();
        }

        return $connection;
    }

    /**
     * {@inheritDoc}
     */
    public function getFilters()
    {
        if ($this->filterCollection === null) {
            $this->filterCollection = new FilterCollection($this);
        }

        return $this->filterCollection;
    }

    /**
     * {@inheritDoc}
     */
    public function isFiltersStateClean()
    {
        return $this->filterCollection === null || $this->filterCollection->isClean();
    }

    /**
     * {@inheritDoc}
     */
    public function hasFilters()
    {
        return $this->filterCollection !== null;
    }

    /**
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    private function checkLockRequirements(int $lockMode, ClassMetadata $class): void
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
            $this->configureLegacyMetadataCache();

            return;
        }

        // We have a PSR-6 compatible metadata factory. Use cache directly
        if (method_exists($this->metadataFactory, 'setCache')) {
            $this->metadataFactory->setCache($metadataCache);

            return;
        }

        // Wrap PSR-6 cache to provide doctrine/cache interface
        $this->metadataFactory->setCacheDriver(DoctrineProvider::wrap($metadataCache));
    }

    private function configureLegacyMetadataCache(): void
    {
        $metadataCache = $this->config->getMetadataCacheImpl();
        if (! $metadataCache) {
            return;
        }

        // Metadata factory is not PSR-6 compatible. Use cache directly
        if (! method_exists($this->metadataFactory, 'setCache')) {
            $this->metadataFactory->setCacheDriver($metadataCache);

            return;
        }

        // Wrap doctrine/cache to provide PSR-6 interface
        $this->metadataFactory->setCache(CacheAdapter::wrap($metadataCache));
    }
}
