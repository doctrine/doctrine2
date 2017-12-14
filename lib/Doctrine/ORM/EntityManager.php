<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Proxy\Factory\StaticProxyFactory;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\ORM\Utility\StaticClassNameConverter;

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
 *
 * @since   2.0
 * @author  Benjamin Eberlei <kontakt@beberlei.de>
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Roman Borschel <roman@code-factory.org>
 */
final class EntityManager implements EntityManagerInterface
{
    /**
     * The used Configuration.
     *
     * @var \Doctrine\ORM\Configuration
     */
    private $config;

    /**
     * The database connection used by the EntityManager.
     *
     * @var \Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     *
     * @var \Doctrine\ORM\Mapping\ClassMetadataFactory
     */
    private $metadataFactory;

    /**
     * The UnitOfWork used to coordinate object-level transactions.
     *
     * @var \Doctrine\ORM\UnitOfWork
     */
    private $unitOfWork;

    /**
     * The event manager that is the central point of the event system.
     *
     * @var \Doctrine\Common\EventManager
     */
    private $eventManager;

    /**
     * The proxy factory used to create dynamic proxies.
     *
     * @var \Doctrine\ORM\Proxy\Factory\ProxyFactory
     */
    private $proxyFactory;

    /**
     * The repository factory used to create dynamic repositories.
     *
     * @var \Doctrine\ORM\Repository\RepositoryFactory
     */
    private $repositoryFactory;

    /**
     * The expression builder instance used to generate query expressions.
     *
     * @var \Doctrine\ORM\Query\Expr
     */
    private $expressionBuilder;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     *
     * @var \Doctrine\ORM\Utility\IdentifierFlattener
     */
    private $identifierFlattener;

    /**
     * Whether the EntityManager is closed or not.
     *
     * @var bool
     */
    private $closed = false;

    /**
     * Collection of query filters.
     *
     * @var \Doctrine\ORM\Query\FilterCollection
     */
    private $filterCollection;

    /**
     * @var \Doctrine\ORM\Cache The second level cache regions API.
     */
    private $cache;

    /**
     * Creates a new EntityManager that operates on the given database connection
     * and uses the given Configuration and EventManager implementations.
     *
     * @param \Doctrine\DBAL\Connection     $conn
     * @param \Doctrine\ORM\Configuration   $config
     * @param \Doctrine\Common\EventManager $eventManager
     */
    protected function __construct(Connection $conn, Configuration $config, EventManager $eventManager)
    {
        $this->conn              = $conn;
        $this->config            = $config;
        $this->eventManager      = $eventManager;

        $metadataFactoryClassName = $config->getClassMetadataFactoryName();

        $this->metadataFactory = new $metadataFactoryClassName;

        $this->metadataFactory->setEntityManager($this);
        $this->metadataFactory->setCacheDriver($this->config->getMetadataCacheImpl());

        $this->repositoryFactory   = $config->getRepositoryFactory();
        $this->unitOfWork          = new UnitOfWork($this);
        $this->proxyFactory        = new StaticProxyFactory($this, $this->config->buildGhostObjectFactory());
        $this->identifierFlattener = new IdentifierFlattener($this->unitOfWork, $this->metadataFactory);

        if ($config->isSecondLevelCacheEnabled()) {
            $cacheConfig    = $config->getSecondLevelCacheConfiguration();
            $cacheFactory   = $cacheConfig->getCacheFactory();
            $this->cache    = $cacheFactory->createCache($this);
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
     * @return \Doctrine\ORM\Mapping\ClassMetadataFactory
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
            $this->expressionBuilder = new Query\Expr;
        }

        return $this->expressionBuilder;
    }

    /**
     * @return IdentifierFlattener
     */
    public function getIdentifierFlattener() : IdentifierFlattener
    {
        return $this->identifierFlattener;
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
    public function transactional(callable $func)
    {
        $this->conn->beginTransaction();

        try {
            $return = $func($this);

            $this->flush();
            $this->conn->commit();

            return $return;
        } catch (\Throwable $e) {
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
     * @param string $className
     *
     * @return Mapping\ClassMetadata
     */
    public function getClassMetadata($className) : Mapping\ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    /**
     * {@inheritDoc}
     */
    public function createQuery($dql = '')
    {
        $query = new Query($this);

        if ( ! empty($dql)) {
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
        list($sql, $rsm) = $this->config->getNamedNativeQuery($name);

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
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function merge($object)
    {
        throw new \BadMethodCallException('@TODO method disabled - will be removed in 3.0 with a release of doctrine/common');
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function detach($object)
    {
        throw new \BadMethodCallException('@TODO method disabled - will be removed in 3.0 with a release of doctrine/common');
    }

    /**
     * Flushes all changes to objects that have been queued up to now to the database.
     * This effectively synchronizes the in-memory state of managed objects with the
     * database.
     *
     * If an entity is explicitly passed to this method only this entity and
     * the cascade-persist semantics + scheduled inserts/removals are synchronized.
     *
     * @return void
     *
     * @throws \Doctrine\ORM\OptimisticLockException If a version check on an entity that
     *         makes use of optimistic locking fails.
     * @throws ORMException
     */
    public function flush()
    {
        $this->errorIfClosed();

        $this->unitOfWork->commit();
    }

    /**
     * Finds an Entity by its identifier.
     *
     * @param string       $entityName  The class name of the entity to find.
     * @param mixed        $id          The identity of the entity to find.
     * @param integer|null $lockMode    One of the \Doctrine\DBAL\LockMode::* constants
     *                                  or NULL if no specific lock mode should be used
     *                                  during the search.
     * @param integer|null $lockVersion The version of the entity to find when using
     *                                  optimistic locking.
     *
     * @return object|null The entity instance or NULL if the entity can not be found.
     *
     * @throws OptimisticLockException
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws ORMException
     */
    public function find($entityName, $id, $lockMode = null, $lockVersion = null)
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($entityName, '\\'));
        $className = $class->getClassName();

        if (! is_array($id)) {
            if ($class->isIdentifierComposite()) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        foreach ($id as $i => $value) {
            if (is_object($value) && $this->metadataFactory->hasMetadataFor(StaticClassNameConverter::getClass($value))) {
                $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value);

                if ($id[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if ( ! isset($id[$identifier])) {
                throw ORMException::missingIdentifierField($className, $identifier);
            }

            $sortedId[$identifier] = $id[$identifier];
            unset($id[$identifier]);
        }

        if ($id) {
            throw ORMException::unrecognizedIdentifierFields($className, array_keys($id));
        }

        $unitOfWork = $this->getUnitOfWork();

        // Check identity map first
        if (($entity = $unitOfWork->tryGetById($sortedId, $class->getRootClassName())) !== false) {
            if ( ! ($entity instanceof $className)) {
                return null;
            }

            switch (true) {
                case $lockMode === LockMode::OPTIMISTIC:
                    $this->lock($entity, $lockMode, $lockVersion);
                    break;

                case $lockMode === LockMode::NONE:
                case $lockMode === LockMode::PESSIMISTIC_READ:
                case $lockMode === LockMode::PESSIMISTIC_WRITE:
                    $persister = $unitOfWork->getEntityPersister($className);
                    $persister->refresh($sortedId, $entity, $lockMode);
                    break;
            }

            return $entity; // Hit!
        }

        $persister = $unitOfWork->getEntityPersister($className);

        switch (true) {
            case $lockMode === LockMode::OPTIMISTIC:
                if ( ! $class->isVersioned()) {
                    throw OptimisticLockException::notVersioned($className);
                }

                $entity = $persister->load($sortedId);

                $unitOfWork->lock($entity, $lockMode, $lockVersion);

                return $entity;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if ( ! $this->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }

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
        $className = $class->getClassName();

        if ( ! is_array($id)) {
            if ($class->isIdentifierComposite()) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        $scalarId = [];

        foreach ($id as $i => $value) {
            $scalarId[$i] = $value;

            if (is_object($value) && $this->metadataFactory->hasMetadataFor(StaticClassNameConverter::getClass($value))) {
                $scalarId[$i] = $this->unitOfWork->getSingleIdentifierValue($value);

                if ($scalarId[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if ( ! isset($scalarId[$identifier])) {
                throw ORMException::missingIdentifierField($className, $identifier);
            }

            $sortedId[$identifier] = $scalarId[$identifier];
            unset($scalarId[$identifier]);
        }

        if ($scalarId) {
            throw ORMException::unrecognizedIdentifierFields($className, array_keys($scalarId));
        }

        // Check identity map first, if its already in there just return it.
        if (($entity = $this->unitOfWork->tryGetById($sortedId, $class->getRootClassName())) !== false) {
            return ($entity instanceof $className) ? $entity : null;
        }

        if ($class->getSubClasses()) {
            return $this->find($entityName, $sortedId);
        }

        $entity = $this->proxyFactory->getProxy($class, $id);

        $this->unitOfWork->registerManaged($entity, $sortedId, []);

        if ($entity instanceof EntityManagerAware) {
            $entity->injectEntityManager($this, $class);
        }

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function getPartialReference($entityName, $id)
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($entityName, '\\'));
        $className = $class->getClassName();

        if ( ! is_array($id)) {
            if ($class->isIdentifierComposite()) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        foreach ($id as $i => $value) {
            if (is_object($value) && $this->metadataFactory->hasMetadataFor(StaticClassNameConverter::getClass($value))) {
                $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value);

                if ($id[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if ( ! isset($id[$identifier])) {
                throw ORMException::missingIdentifierField($className, $identifier);
            }

            $sortedId[$identifier] = $id[$identifier];
            unset($id[$identifier]);
        }

        if ($id) {
            throw ORMException::unrecognizedIdentifierFields($className, array_keys($id));
        }

        // Check identity map first, if its already in there just return it.
        if (($entity = $this->unitOfWork->tryGetById($sortedId, $class->getRootClassName())) !== false) {
            return ($entity instanceof $className) ? $entity : null;
        }

        $persister = $this->unitOfWork->getEntityPersister($class->getClassName());
        $entity    = $this->unitOfWork->newInstance($class);

        $persister->setIdentifier($entity, $sortedId);

        $this->unitOfWork->registerManaged($entity, $sortedId, []);
        $this->unitOfWork->markReadOnly($entity);

        return $entity;
    }

    /**
     * Clears the EntityManager. All entities that are currently managed
     * by this EntityManager become detached.
     *
     * @param null $entityName Unused. @todo Remove from ObjectManager.
     *
     * @return void
     */
    public function clear($entityName = null)
    {
        $this->unitOfWork->clear();
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
        if ( ! is_object($entity)) {
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
        if ( ! is_object($entity)) {
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
        if ( ! is_object($entity)) {
            throw ORMInvalidArgumentException::invalidObject('EntityManager#refresh()', $entity);
        }

        $this->errorIfClosed();

        $this->unitOfWork->refresh($entity);
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
     *
     * @return \Doctrine\ORM\EntityRepository The repository class.
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
     * @return boolean TRUE if this EntityManager currently manages the given entity, FALSE otherwise.
     */
    public function contains($entity)
    {
        return $this->unitOfWork->isScheduledForInsert($entity)
            || ($this->unitOfWork->isInIdentityMap($entity) && !$this->unitOfWork->isScheduledForDelete($entity));
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
     * @return void
     *
     * @throws ORMException If the EntityManager is closed.
     */
    private function errorIfClosed()
    {
        if ($this->closed) {
            throw ORMException::entityManagerClosed();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isOpen()
    {
        return (!$this->closed);
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
                if (($class = $this->config->getCustomHydrationMode($hydrationMode)) !== null) {
                    return new $class($this);
                }
        }

        throw ORMException::invalidHydrationMode($hydrationMode);
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
     * @param array|Connection $connection   An array with the connection parameters or an existing Connection instance.
     * @param Configuration    $config       The Configuration instance to use.
     * @param EventManager     $eventManager The EventManager instance to use.
     *
     * @return EntityManager The created EntityManager.
     *
     * @throws \InvalidArgumentException
     * @throws ORMException
     */
    public static function create($connection, Configuration $config, EventManager $eventManager = null)
    {
        if ( ! $config->getMetadataDriverImpl()) {
            throw ORMException::missingMappingDriverImpl();
        }

        $connection = static::createConnection($connection, $config, $eventManager);

        return new EntityManager($connection, $config, $connection->getEventManager());
    }

    /**
     * Factory method to create Connection instances.
     *
     * @param array|Connection $connection   An array with the connection parameters or an existing Connection instance.
     * @param Configuration    $config       The Configuration instance to use.
     * @param EventManager     $eventManager The EventManager instance to use.
     *
     * @return Connection
     *
     * @throws \InvalidArgumentException
     * @throws ORMException
     */
    protected static function createConnection($connection, Configuration $config, EventManager $eventManager = null)
    {
        if (is_array($connection)) {
            return DriverManager::getConnection($connection, $config, $eventManager ?: new EventManager());
        }

        if ( ! $connection instanceof Connection) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid $connection argument of type %s given%s.',
                    is_object($connection) ? get_class($connection) : gettype($connection),
                    is_object($connection) ? '' : ': "' . $connection . '"'
                )
            );
        }

        if ($eventManager !== null && $connection->getEventManager() !== $eventManager) {
            throw ORMException::mismatchedEventManager();
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
}
