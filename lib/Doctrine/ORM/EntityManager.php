<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\EventManager;
use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\MetadataCollection;
use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use Doctrine\ORM\Proxy\Factory\StaticProxyFactory;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\ORM\Utility\StaticClassNameConverter;
use function array_keys;
use function get_class;
use function gettype;
use function is_array;
use function is_object;
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
final class EntityManager implements EntityManagerInterface
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
     * The IdentifierFlattener used for manipulating identifiers
     *
     * @var IdentifierFlattener
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
     * @var FilterCollection
     */
    private $filterCollection;

    /** @var Cache The second level cache regions API. */
    private $cache;

    /** @var MetadataCollection */
    private $mappings;

    /**
     * Creates a new EntityManager that operates on the given database connection
     * and uses the given Configuration and EventManager implementations.
     *
     */
    protected function __construct(Connection $conn, Configuration $config, EventManager $eventManager, MetadataCollection $metadataCollection)
    {
        $this->conn                = $conn;
        $this->config              = $config;
        $this->eventManager        = $eventManager;
        $this->mappings            = $metadataCollection;

        $this->repositoryFactory   = $config->getRepositoryFactory();
        $this->unitOfWork          = new UnitOfWork($this, $metadataCollection);
        $this->proxyFactory        = new StaticProxyFactory($this, $this->config->buildGhostObjectFactory());
        $this->identifierFlattener = new IdentifierFlattener($this->unitOfWork, $this->mappings);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->conn;
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
     * {@internal Performance-sensitive method. }}
     *
     * @param string $className
     *
     * @throws \ReflectionException
     * @throws \InvalidArgumentException
     * @throws MappingException
     */
    public function getClassMetadata($className) : Mapping\ClassMetadata
    {
        return $this->mappings->get($className);
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
     * @throws OptimisticLockException If a version check on an entity that
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
     * @param string   $entityName  The class name of the entity to find.
     * @param mixed    $id          The identity of the entity to find.
     * @param int|null $lockMode    One of the \Doctrine\DBAL\LockMode::* constants
     *                              or NULL if no specific lock mode should be used
     *                              during the search.
     * @param int|null $lockVersion The version of the entity to find when using
     *                              optimistic locking.
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
        $class     = $this->mappings->get($entityName);
        $className = $class->getClassName();

        if (! is_array($id)) {
            if ($class->isIdentifierComposite()) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        foreach ($id as $i => $value) {
            if (is_object($value)) {
                try{
                    $this->mappings->get($value);
                    $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value);
                } catch (\Exception $e) {
                    $id[$i] = null;
                }

                if ($id[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if (! isset($id[$identifier])) {
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
        $entity = $unitOfWork->tryGetById($sortedId, $class->getRootClassName());
        if ($entity !== false) {
            if (! ($entity instanceof $className)) {
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
                if (! $class->isVersioned()) {
                    throw OptimisticLockException::notVersioned($className);
                }

                $entity = $persister->load($sortedId);

                $unitOfWork->lock($entity, $lockMode, $lockVersion);

                return $entity;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if (! $this->getConnection()->isTransactionActive()) {
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
        $class     = $this->mappings->get($entityName);
        $className = $class->getClassName();

        if (! is_array($id)) {
            if ($class->isIdentifierComposite()) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        $scalarId = [];

        foreach ($id as $i => $value) {
            $scalarId[$i] = $value;

            if (is_object($value)) {
                try{
                    $this->mappings->get($value);
                    $scalarId[$i] = $this->unitOfWork->getSingleIdentifierValue($value);
                } catch (\Exception $e) {
                    $scalarId[$i] = null;
                }

                if ($scalarId[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if (! isset($scalarId[$identifier])) {
                throw ORMException::missingIdentifierField($className, $identifier);
            }

            $sortedId[$identifier] = $scalarId[$identifier];
            unset($scalarId[$identifier]);
        }

        if ($scalarId) {
            throw ORMException::unrecognizedIdentifierFields($className, array_keys($scalarId));
        }

        // Check identity map first, if its already in there just return it.
        $entity = $this->unitOfWork->tryGetById($sortedId, $class->getRootClassName());
        if ($entity !== false) {
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
        $class     = $this->mappings->get($entityName);
        $className = $class->getClassName();

        if (! is_array($id)) {
            if ($class->isIdentifierComposite()) {
                throw ORMInvalidArgumentException::invalidCompositeIdentifier();
            }

            $id = [$class->identifier[0] => $id];
        }

        foreach ($id as $i => $value) {
            if (is_object($value)) {
                try{
                    $this->mappings->get($value);
                    $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value);
                } catch (\Exception $e) {
                    $id[$i] = null;
                }

                if ($id[$i] === null) {
                    throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
                }
            }
        }

        $sortedId = [];

        foreach ($class->identifier as $identifier) {
            if (! isset($id[$identifier])) {
                throw ORMException::missingIdentifierField($className, $identifier);
            }

            $sortedId[$identifier] = $id[$identifier];
            unset($id[$identifier]);
        }

        if ($id) {
            throw ORMException::unrecognizedIdentifierFields($className, array_keys($id));
        }

        // Check identity map first, if its already in there just return it.
        $entity = $this->unitOfWork->tryGetById($sortedId, $class->getRootClassName());
        if ($entity !== false) {
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
     */
    public function clear($entityName = null)
    {
        $this->unitOfWork->clear();

        $this->unitOfWork = new UnitOfWork($this, $this->mappings);

        if ($this->eventManager->hasListeners(Events::onClear)) {
            $this->eventManager->dispatchEvent(Events::onClear, new Event\OnClearEventArgs($this));
        }
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
     * @return ObjectRepository|EntityRepository The repository class.
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
            || ($this->unitOfWork->isInIdentityMap($entity) && ! $this->unitOfWork->isScheduledForDelete($entity));
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
                $class = $this->config->getCustomHydrationMode((string) $hydrationMode);
                if ($class !== null) {
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
     * @param Connection|mixed[] $connection   An array with the connection parameters or an existing Connection instance.
     * @param Configuration      $config       The Configuration instance to use.
     * @param EventManager       $eventManager The EventManager instance to use.
     *
     * @return EntityManager The created EntityManager.
     *
     * @throws \InvalidArgumentException
     * @throws ORMException
     */
    public static function create($connection, Configuration $config, EventManager $eventManager)
    {
        if (! $config->getMetadataDriverImpl()) {
            throw ORMException::missingMappingDriverImpl();
        }

        $connection = static::createConnection($connection, $config, $eventManager);

        $metadataFactoryClassName = $config->getClassMetadataFactoryName();
        /** @var \Doctrine\Common\Persistence\Mapping\ClassMetadataFactory $metadataFactory */
        $metadataFactory          = new $metadataFactoryClassName($config, $connection, $eventManager);

        return self::createWithClassMetadataFactory($connection, $config, $eventManager, $metadataFactory);
    }

    public static function createWithClassMetadataFactory(Connection $connection, Configuration $config, EventManager $eventManager, ClassMetadataFactory $metadataFactory)
    {
        $metadatas = MetadataCollection::fromClassMetadatas(...$metadataFactory->getAllMetadata());

        return new self($connection, $config, $eventManager, $metadatas);
    }

    public static function createWithClassMetadata(Connection $conn, Configuration $config, EventManager $eventManager, MetadataCollection $metadatas)
    {
        return new self($conn, $config, $eventManager, $metadatas);
    }

    /**
     * Factory method to create Connection instances.
     *
     * @param Connection|mixed[] $connection   An array with the connection parameters or an existing Connection instance.
     * @param Configuration      $config       The Configuration instance to use.
     * @param EventManager       $eventManager The EventManager instance to use.
     *
     * @return Connection
     *
     * @throws \InvalidArgumentException
     * @throws ORMException
     */
    protected static function createConnection($connection, Configuration $config, ?EventManager $eventManager = null)
    {
        if (is_array($connection)) {
            return DriverManager::getConnection($connection, $config, $eventManager ?: new EventManager());
        }

        if (! $connection instanceof Connection) {
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
