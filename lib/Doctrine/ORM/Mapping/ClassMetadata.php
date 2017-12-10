<?php


declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\ORM\Cache\CacheException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Factory\NamingStrategy;
use Doctrine\ORM\Reflection\ReflectionService;
use Doctrine\ORM\Sequencing\Planning\ValueGenerationPlan;
use Doctrine\ORM\Utility\PersisterHelper;

/**
 * A <tt>ClassMetadata</tt> instance holds all the object-relational mapping metadata
 * of an entity and its associations.
 *
 * @author Roman Borschel <roman@code-factory.org>
 * @author Jonathan H. Wage <jonwage@gmail.com>
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @since 2.0
 */
class ClassMetadata extends ComponentMetadata implements TableOwner
{
    /**
     * The name of the custom repository class used for the entity class.
     * (Optional).
     *
     * @var string
     */
    protected $customRepositoryClassName;

    /**
     * READ-ONLY: Whether this class describes the mapping of a mapped superclass.
     *
     * @var boolean
     */
    public $isMappedSuperclass = false;

    /**
     * READ-ONLY: Whether this class describes the mapping of an embeddable class.
     *
     * @var boolean
     */
    public $isEmbeddedClass = false;

    /**
     * Whether this class describes the mapping of a read-only class.
     * That means it is never considered for change-tracking in the UnitOfWork.
     * It is a very helpful performance optimization for entities that are immutable,
     * either in your domain or through the relation database (coming from a view,
     * or a history table for example).
     *
     * @var boolean
     */
    private $readOnly = false;

    /**
     * The names of all subclasses (descendants).
     *
     * @var array
     */
    protected $subClasses = [];

    /**
     * READ-ONLY: The names of all embedded classes based on properties.
     *
     * @var array
     */
    //public $embeddedClasses = [];

    /**
     * The named queries allowed to be called directly from Repository.
     *
     * @var array
     */
    protected $namedQueries = [];

    /**
     * READ-ONLY: The named native queries allowed to be called directly from Repository.
     *
     * A native SQL named query definition has the following structure:
     * <pre>
     * array(
     *     'name'               => <query name>,
     *     'query'              => <sql query>,
     *     'resultClass'        => <class of the result>,
     *     'resultSetMapping'   => <name of a SqlResultSetMapping>
     * )
     * </pre>
     *
     * @var array
     */
    public $namedNativeQueries = [];

    /**
     * READ-ONLY: The mappings of the results of native SQL queries.
     *
     * A native result mapping definition has the following structure:
     * <pre>
     * array(
     *     'name'               => <result name>,
     *     'entities'           => array(<entity result mapping>),
     *     'columns'            => array(<column result mapping>)
     * )
     * </pre>
     *
     * @var array
     */
    public $sqlResultSetMappings = [];

    /**
     * READ-ONLY: The registered lifecycle callbacks for entities of this class.
     *
     * @var array<string, array<string>>
     */
    public $lifecycleCallbacks = [];

    /**
     * READ-ONLY: The registered entity listeners.
     *
     * @var array
     */
    public $entityListeners = [];

    /**
     * READ-ONLY: The field names of all fields that are part of the identifier/primary key
     * of the mapped entity class.
     *
     * @var string[]
     */
    public $identifier = [];

    /**
     * READ-ONLY: The inheritance mapping type used by the class.
     *
     * @var string
     */
    public $inheritanceType = InheritanceType::NONE;

    /**
     * READ-ONLY: The policy used for change-tracking on entities of this class.
     *
     * @var string
     */
    public $changeTrackingPolicy = ChangeTrackingPolicy::DEFERRED_IMPLICIT;

    /**
     * READ-ONLY: The discriminator value of this class.
     *
     * <b>This does only apply to the JOINED and SINGLE_TABLE inheritance mapping strategies
     * where a discriminator column is used.</b>
     *
     * @var mixed
     *
     * @see discriminatorColumn
     */
    public $discriminatorValue;

    /**
     * READ-ONLY: The discriminator map of all mapped classes in the hierarchy.
     *
     * <b>This does only apply to the JOINED and SINGLE_TABLE inheritance mapping strategies
     * where a discriminator column is used.</b>
     *
     * @var array<string, string>
     *
     * @see discriminatorColumn
     */
    public $discriminatorMap = [];

    /**
     * READ-ONLY: The definition of the discriminator column used in JOINED and SINGLE_TABLE
     * inheritance mappings.
     *
     * @var DiscriminatorColumnMetadata
     */
    public $discriminatorColumn;

    /**
     * READ-ONLY: The primary table metadata.
     *
     * @var TableMetadata
     */
    public $table;

    /**
     * READ-ONLY: An array of field names. Used to look up field names from column names.
     * Keys are column names and values are field names.
     *
     * @var array<string, string>
     */
    public $fieldNames = [];

    /**
     * READ-ONLY: The field which is used for versioning in optimistic locking (if any).
     *
     * @var FieldMetadata|null
     */
    public $versionProperty;

    /**
     * NamingStrategy determining the default column and table names.
     *
     * @var NamingStrategy
     */
    protected $namingStrategy;

    /**
     * Value generation plan is responsible for generating values for auto-generated fields.
     *
     * @var ValueGenerationPlan
     */
    protected $valueGenerationPlan;

    /**
     * Initializes a new ClassMetadata instance that will hold the object-relational mapping
     * metadata of the class with the given name.
     *
     * @param string                       $entityName              The name of the entity class.
     * @param ClassMetadataBuildingContext $metadataBuildingContext
     */
    public function __construct(
        string $entityName,
        ClassMetadataBuildingContext $metadataBuildingContext
    )
    {
        parent::__construct($entityName, $metadataBuildingContext);

        $this->namingStrategy = $metadataBuildingContext->getNamingStrategy();
    }

    /**
     * @todo guilhermeblanco Remove once ClassMetadataFactory is finished
     *
     * @param string $className
     */
    public function setClassName(string $className)
    {
        $this->className = $className;
    }

    /**
     * @return \ArrayIterator
     */
    public function getColumnsIterator() : \ArrayIterator
    {
        $iterator = parent::getColumnsIterator();

        if ($this->discriminatorColumn) {
            $iterator->offsetSet($this->discriminatorColumn->getColumnName(), $this->discriminatorColumn);
        }

        return $iterator;
    }

    /**
     * @return \ArrayIterator
     */
    public function getAncestorsIterator() : \ArrayIterator
    {
        $ancestors = new \ArrayIterator();
        $parent    = $this;

        while (($parent = $parent->parent) !== null) {
            if ($parent instanceof ClassMetadata && $parent->isMappedSuperclass) {
                continue;
            }

            $ancestors->append($parent);
        }

        return $ancestors;
    }

    /**
     * @return string
     */
    public function getRootClassName() : string
    {
        return ($this->parent instanceof ClassMetadata && ! $this->parent->isMappedSuperclass)
            ? $this->parent->getRootClassName()
            : $this->className
        ;
    }

    /**
     * Handles metadata cloning nicely.
     */
    public function __clone()
    {
        if ($this->cache) {
            $this->cache = clone $this->cache;
        }

        foreach ($this->declaredProperties as $name => $property) {
            $this->declaredProperties[$name] = clone $property;
        }
    }

    /**
     * Creates a string representation of this instance.
     *
     * @return string The string representation of this instance.
     *
     * @todo Construct meaningful string representation.
     */
    public function __toString()
    {
        return __CLASS__ . '@' . spl_object_hash($this);
    }

    /**
     * Determines which fields get serialized.
     *
     * It is only serialized what is necessary for best unserialization performance.
     * That means any metadata properties that are not set or empty or simply have
     * their default value are NOT serialized.
     *
     * Parts that are also NOT serialized because they can not be properly unserialized:
     * - reflectionClass
     *
     * @return array The names of all the fields that should be serialized.
     */
    public function __sleep()
    {
        $serialized = [];

        // This metadata is always serialized/cached.
        $serialized = array_merge($serialized, [
            'declaredProperties',
            'fieldNames',
            //'embeddedClasses',
            'identifier',
            'className',
            'parent',
            'table',
            'valueGenerationPlan',
        ]);

        // The rest of the metadata is only serialized if necessary.
        if ($this->changeTrackingPolicy !== ChangeTrackingPolicy::DEFERRED_IMPLICIT) {
            $serialized[] = 'changeTrackingPolicy';
        }

        if ($this->customRepositoryClassName) {
            $serialized[] = 'customRepositoryClassName';
        }

        if ($this->inheritanceType !== InheritanceType::NONE) {
            $serialized[] = 'inheritanceType';
            $serialized[] = 'discriminatorColumn';
            $serialized[] = 'discriminatorValue';
            $serialized[] = 'discriminatorMap';
            $serialized[] = 'subClasses';
        }

        if ($this->isMappedSuperclass) {
            $serialized[] = 'isMappedSuperclass';
        }

        if ($this->isEmbeddedClass) {
            $serialized[] = 'isEmbeddedClass';
        }

        if ($this->isVersioned()) {
            $serialized[] = 'versionProperty';
        }

        if ($this->lifecycleCallbacks) {
            $serialized[] = 'lifecycleCallbacks';
        }

        if ($this->entityListeners) {
            $serialized[] = 'entityListeners';
        }

        if ($this->namedQueries) {
            $serialized[] = 'namedQueries';
        }

        if ($this->namedNativeQueries) {
            $serialized[] = 'namedNativeQueries';
        }

        if ($this->sqlResultSetMappings) {
            $serialized[] = 'sqlResultSetMappings';
        }

        if ($this->cache) {
            $serialized[] = 'cache';
        }

        if ($this->readOnly) {
            $serialized[] = 'readOnly';
        }

        return $serialized;
    }

    /**
     * Restores some state that can not be serialized/unserialized.
     *
     * @param ReflectionService $reflectionService
     *
     * @return void
     */
    public function wakeupReflection(ReflectionService $reflectionService) : void
    {
        // Restore ReflectionClass and properties
        $this->reflectionClass = $reflectionService->getClass($this->className);

        if (! $this->reflectionClass) {
            return;
        }

        $this->className = $this->reflectionClass->getName();

        foreach ($this->declaredProperties as $property) {
            /** @var Property $property */
            $property->wakeupReflection($reflectionService);
        }
    }

    /**
     * Validates Identifier.
     *
     * @return void
     *
     * @throws MappingException
     */
    public function validateIdentifier() : void
    {
        if ($this->isMappedSuperclass || $this->isEmbeddedClass) {
            return;
        }

        // Verify & complete identifier mapping
        if (! $this->identifier) {
            throw MappingException::identifierRequired($this->className);
        }

        $explicitlyGeneratedProperties = array_filter($this->declaredProperties, function (Property $property) : bool {
            return $property instanceof FieldMetadata
                && $property->isPrimaryKey()
                && $property->hasValueGenerator();
        });

        if ($explicitlyGeneratedProperties && $this->isIdentifierComposite()) {
            throw MappingException::compositeKeyAssignedIdGeneratorRequired($this->className);
        }
    }

    /**
     * Validates association targets actually exist.
     *
     * @return void
     *
     * @throws MappingException
     */
    public function validateAssociations() : void
    {
        array_map(
            function (Property $property) {
                if (! ($property instanceof AssociationMetadata)) {
                    return;
                }

                $targetEntity = $property->getTargetEntity();

                if (! class_exists($targetEntity)) {
                    throw MappingException::invalidTargetEntityClass($targetEntity, $this->className, $property->getName());
                }
            },
            $this->declaredProperties
        );
    }

    /**
     * Validates lifecycle callbacks.
     *
     * @param ReflectionService $reflectionService
     *
     * @return void
     *
     * @throws MappingException
     */
    public function validateLifecycleCallbacks(ReflectionService $reflectionService) : void
    {
        foreach ($this->lifecycleCallbacks as $callbacks) {
            /** @var array $callbacks */
            foreach ($callbacks as $callbackFuncName) {
                if (! $reflectionService->hasPublicMethod($this->className, $callbackFuncName)) {
                    throw MappingException::lifecycleCallbackMethodNotFound($this->className, $callbackFuncName);
                }
            }
        }
    }

    /**
     * Sets the change tracking policy used by this class.
     *
     * @param string $policy
     *
     * @return void
     */
    public function setChangeTrackingPolicy(string $policy) : void
    {
        $this->changeTrackingPolicy = $policy;
    }

    /**
     * Checks whether a field is part of the identifier/primary key field(s).
     *
     * @param string $fieldName The field name.
     *
     * @return bool TRUE if the field is part of the table identifier/primary key field(s), FALSE otherwise.
     */
    public function isIdentifier(string $fieldName) : bool
    {
        if (! $this->identifier) {
            return false;
        }

        if (! $this->isIdentifierComposite()) {
            return $fieldName === $this->identifier[0];
        }

        return in_array($fieldName, $this->identifier, true);
    }

    /**
     * @return bool
     */
    public function isIdentifierComposite() : bool
    {
        return isset($this->identifier[1]);
    }

    /**
     * Gets the named query.
     *
     * @see ClassMetadata::$namedQueries
     *
     * @param string $queryName The query name.
     *
     * @return string
     *
     * @throws MappingException
     */
    public function getNamedQuery($queryName) : string
    {
        if (! isset($this->namedQueries[$queryName])) {
            throw MappingException::queryNotFound($this->className, $queryName);
        }

        return $this->namedQueries[$queryName];
    }

    /**
     * Gets all named queries of the class.
     *
     * @return array
     */
    public function getNamedQueries() : array
    {
        return $this->namedQueries;
    }

    /**
     * Gets the named native query.
     *
     * @see ClassMetadata::$namedNativeQueries
     *
     * @param string $queryName The query name.
     *
     * @return array
     *
     * @throws MappingException
     */
    public function getNamedNativeQuery($queryName) : array
    {
        if ( ! isset($this->namedNativeQueries[$queryName])) {
            throw MappingException::queryNotFound($this->className, $queryName);
        }

        return $this->namedNativeQueries[$queryName];
    }

    /**
     * Gets all named native queries of the class.
     *
     * @return array
     */
    public function getNamedNativeQueries() : array
    {
        return $this->namedNativeQueries;
    }

    /**
     * Gets the result set mapping.
     *
     * @see ClassMetadata::$sqlResultSetMappings
     *
     * @param string $name The result set mapping name.
     *
     * @return array
     *
     * @throws MappingException
     */
    public function getSqlResultSetMapping($name)
    {
        if (! isset($this->sqlResultSetMappings[$name])) {
            throw MappingException::resultMappingNotFound($this->className, $name);
        }

        return $this->sqlResultSetMappings[$name];
    }

    /**
     * Gets all sql result set mappings of the class.
     *
     * @return array
     */
    public function getSqlResultSetMappings()
    {
        return $this->sqlResultSetMappings;
    }

    /**
     * Validates & completes the basic mapping information for field mapping.
     *
     * @param FieldMetadata $property
     *
     * @throws MappingException If something is wrong with the mapping.
     */
    protected function validateAndCompleteFieldMapping(FieldMetadata $property)
    {
        $fieldName  = $property->getName();
        $columnName = $property->getColumnName();

        if (empty($columnName)) {
            $columnName = $this->namingStrategy->propertyToColumnName($fieldName, $this->className);

            $property->setColumnName($columnName);
        }

        if (! $this->isMappedSuperclass) {
            $property->setTableName($this->getTableName());
        }

        // Check for already declared column
        if (isset($this->fieldNames[$columnName]) ||
            ($this->discriminatorColumn !== null && $this->discriminatorColumn->getColumnName() === $columnName)) {
            throw MappingException::duplicateColumnName($this->className, $columnName);
        }

        // Complete id mapping
        if ($property->isPrimaryKey()) {
            if ($this->versionProperty !== null && $this->versionProperty->getName() === $fieldName) {
                throw MappingException::cannotVersionIdField($this->className, $fieldName);
            }

            if ($property->getType()->canRequireSQLConversion()) {
                throw MappingException::sqlConversionNotAllowedForPrimaryKeyProperties($property);
            };

            if (! in_array($fieldName, $this->identifier)) {
                $this->identifier[] = $fieldName;
            }
        }

        $this->fieldNames[$columnName] = $fieldName;
    }

    /**
     * Validates & completes the basic mapping information for field mapping.
     *
     * @param VersionFieldMetadata $property
     *
     * @throws MappingException If something is wrong with the mapping.
     */
    protected function validateAndCompleteVersionFieldMapping(VersionFieldMetadata $property)
    {
        $this->versionProperty = $property;

        $options = $property->getOptions();

        if (isset($options['default'])) {
            return;
        }

        if (in_array($property->getTypeName(), ['integer', 'bigint', 'smallint'])) {
            $property->setOptions(array_merge($options, ['default' => 1]));

            return;
        }

        if ($property->getTypeName() === 'datetime') {
            $property->setOptions(array_merge($options, ['default' => 'CURRENT_TIMESTAMP']));

            return;
        }

        throw MappingException::unsupportedOptimisticLockingType($property->getType());
    }

    /**
     * Validates & completes the basic mapping information that is common to all
     * association mappings (one-to-one, many-ot-one, one-to-many, many-to-many).
     *
     * @param AssociationMetadata $property
     *
     * @throws MappingException If something is wrong with the mapping.
     * @throws CacheException   If entity is not cacheable.
     */
    protected function validateAndCompleteAssociationMapping(AssociationMetadata $property)
    {
        $fieldName    = $property->getName();
        $targetEntity = $property->getTargetEntity();

        if (! $targetEntity) {
            throw MappingException::missingTargetEntity($fieldName);
        }

        $property->setSourceEntity($this->className);
        $property->setOwningSide($property->getMappedBy() === null);
        $property->setTargetEntity($targetEntity);

        // Complete id mapping
        if ($property->isPrimaryKey()) {
            if ($property->isOrphanRemoval()) {
                throw MappingException::illegalOrphanRemovalOnIdentifierAssociation($this->className, $fieldName);
            }

            if ( ! in_array($property->getName(), $this->identifier)) {
                if ($property instanceof ToOneAssociationMetadata && count($property->getJoinColumns()) >= 2) {
                    throw MappingException::cannotMapCompositePrimaryKeyEntitiesAsForeignId(
                        $property->getTargetEntity(),
                        $this->className,
                        $fieldName
                    );
                }

                $this->identifier[] = $property->getName();
            }

            if ($this->cache && !$property->getCache()) {
                throw CacheException::nonCacheableEntityAssociation($this->className, $fieldName);
            }

            if ($property instanceof ToManyAssociationMetadata) {
                throw MappingException::illegalToManyIdentifierAssociation($this->className, $property->getName());
            }
        }

        // Cascades
        $cascadeTypes = ['remove', 'persist', 'refresh'];
        $cascades     = array_map('strtolower', $property->getCascade());

        if (in_array('all', $cascades)) {
            $cascades = $cascadeTypes;
        }

        if (count($cascades) !== count(array_intersect($cascades, $cascadeTypes))) {
            $diffCascades = array_diff($cascades, array_intersect($cascades, $cascadeTypes));

            throw MappingException::invalidCascadeOption($diffCascades, $this->className, $fieldName);
        }

        $property->setCascade($cascades);
    }

    /**
     * Validates & completes a to-one association mapping.
     *
     * @param ToOneAssociationMetadata $property The association mapping to validate & complete.
     *
     * @throws \RuntimeException
     * @throws MappingException
     */
    protected function validateAndCompleteToOneAssociationMetadata(ToOneAssociationMetadata $property)
    {
        $fieldName = $property->getName();

        if ($property->getJoinColumns()) {
            $property->setOwningSide(true);
        }

        if ($property->isOwningSide()) {
            if (empty($property->getJoinColumns())) {
                // Apply default join column
                $property->addJoinColumn(new JoinColumnMetadata());
            }

            $uniqueConstraintColumns = [];

            foreach ($property->getJoinColumns() as $joinColumn) {
                /** @var JoinColumnMetadata $joinColumn */
                if ($property instanceof OneToOneAssociationMetadata && $this->inheritanceType !== InheritanceType::SINGLE_TABLE) {
                    if (1 === count($property->getJoinColumns())) {
                        if (! $property->isPrimaryKey()) {
                            $joinColumn->setUnique(true);
                        }
                    } else {
                        $uniqueConstraintColumns[] = $joinColumn->getColumnName();
                    }
                }

                $joinColumn->setTableName(! $this->isMappedSuperclass ? $this->getTableName() : null);

                if (! $joinColumn->getColumnName()) {
                    $joinColumn->setColumnName($this->namingStrategy->joinColumnName($fieldName, $this->className));
                }

                if (! $joinColumn->getReferencedColumnName()) {
                    $joinColumn->setReferencedColumnName($this->namingStrategy->referenceColumnName());
                }

                $this->fieldNames[$joinColumn->getColumnName()] = $fieldName;
            }

            if ($uniqueConstraintColumns) {
                if ( ! $this->table) {
                    throw new \RuntimeException(
                        "ClassMetadata::setTable() has to be called before defining a one to one relationship."
                    );
                }

                $this->table->addUniqueConstraint(
                    [
                        'name'    => sprintf('%s_uniq', $fieldName),
                        'columns' => $uniqueConstraintColumns,
                        'options' => [],
                        'flags'   => [],
                    ]
                );
            }
        }

        if ($property->isOrphanRemoval()) {
            $cascades = $property->getCascade();

            if (! in_array('remove', $cascades)) {
                $cascades[] = 'remove';

                $property->setCascade($cascades);
            }

            // @todo guilhermeblanco where is this used?
            // @todo guilhermeblanco Shouldn￿'t we iterate through JoinColumns to set non-uniqueness?
            //$property->setUnique(false);
        }

        if ($property->isPrimaryKey() && ! $property->isOwningSide()) {
            throw MappingException::illegalInverseIdentifierAssociation($this->className, $fieldName);
        }
    }

    /**
     * Validates & completes a to-many association mapping.
     *
     * @param ToManyAssociationMetadata $property The association mapping to validate & complete.
     *
     * @throws MappingException
     */
    protected function validateAndCompleteToManyAssociationMetadata(ToManyAssociationMetadata $property)
    {
        // Do nothing
    }

    /**
     * Validates & completes a one-to-one association mapping.
     *
     * @param OneToOneAssociationMetadata $property The association mapping to validate & complete.
     */
    protected function validateAndCompleteOneToOneMapping(OneToOneAssociationMetadata $property)
    {
        // Do nothing
    }

    /**
     * Validates & completes a many-to-one association mapping.
     *
     * @param ManyToOneAssociationMetadata $property The association mapping to validate & complete.
     *
     * @throws MappingException
     */
    protected function validateAndCompleteManyToOneMapping(ManyToOneAssociationMetadata $property)
    {
        // A many-to-one mapping is essentially a one-one backreference
        if ($property->isOrphanRemoval()) {
            throw MappingException::illegalOrphanRemoval($this->className, $property->getName());
        }
    }

    /**
     * Validates & completes a one-to-many association mapping.
     *
     * @param OneToManyAssociationMetadata $property The association mapping to validate & complete.
     *
     * @throws MappingException
     */
    protected function validateAndCompleteOneToManyMapping(OneToManyAssociationMetadata $property)
    {
        // OneToMany MUST be inverse side
        $property->setOwningSide(false);

        // OneToMany MUST have mappedBy
        if (! $property->getMappedBy()) {
            throw MappingException::oneToManyRequiresMappedBy($property->getName());
        }

        if ($property->isOrphanRemoval()) {
            $cascades = $property->getCascade();

            if (! in_array('remove', $cascades)) {
                $cascades[] = 'remove';

                $property->setCascade($cascades);
            }
        }
    }

    /**
     * Validates & completes a many-to-many association mapping.
     *
     * @param ManyToManyAssociationMetadata $property The association mapping to validate & complete.
     *
     * @throws MappingException
     */
    protected function validateAndCompleteManyToManyMapping(ManyToManyAssociationMetadata $property)
    {
        if ($property->isOwningSide()) {
            // owning side MUST have a join table
            $joinTable = $property->getJoinTable() ?: new JoinTableMetadata();

            $property->setJoinTable($joinTable);

            if (! $joinTable->getName()) {
                $joinTableName = $this->namingStrategy->joinTableName(
                    $property->getSourceEntity(),
                    $property->getTargetEntity(),
                    $property->getName()
                );

                $joinTable->setName($joinTableName);
            }

            $selfReferencingEntityWithoutJoinColumns = $property->getSourceEntity() == $property->getTargetEntity() && ! $joinTable->hasColumns();

            if (! $joinTable->getJoinColumns()) {
                $referencedColumnName = $this->namingStrategy->referenceColumnName();
                $sourceReferenceName  = $selfReferencingEntityWithoutJoinColumns ? 'source' : $referencedColumnName;
                $columnName           = $this->namingStrategy->joinKeyColumnName($property->getSourceEntity(), $sourceReferenceName);
                $joinColumn           = new JoinColumnMetadata();

                $joinColumn->setColumnName($columnName);
                $joinColumn->setReferencedColumnName($referencedColumnName);
                $joinColumn->setOnDelete('CASCADE');

                $joinTable->addJoinColumn($joinColumn);
            }

            if (! $joinTable->getInverseJoinColumns()) {
                $referencedColumnName = $this->namingStrategy->referenceColumnName();
                $targetReferenceName  = $selfReferencingEntityWithoutJoinColumns ? 'target' : $referencedColumnName;
                $columnName           = $this->namingStrategy->joinKeyColumnName($property->getTargetEntity(), $targetReferenceName);
                $joinColumn           = new JoinColumnMetadata();

                $joinColumn->setColumnName($columnName);
                $joinColumn->setReferencedColumnName($referencedColumnName);
                $joinColumn->setOnDelete('CASCADE');

                $joinTable->addInverseJoinColumn($joinColumn);
            }

            foreach ($joinTable->getJoinColumns() as $joinColumn) {
                /** @var JoinColumnMetadata $joinColumn */
                if (! $joinColumn->getReferencedColumnName()) {
                    $joinColumn->setReferencedColumnName($this->namingStrategy->referenceColumnName());
                }

                $referencedColumnName = $joinColumn->getReferencedColumnName();

                if (! $joinColumn->getColumnName()) {
                    $columnName = $this->namingStrategy->joinKeyColumnName(
                        $property->getSourceEntity(),
                        $referencedColumnName
                    );

                    $joinColumn->setColumnName($columnName);
                }
            }

            foreach ($joinTable->getInverseJoinColumns() as $inverseJoinColumn) {
                /** @var JoinColumnMetadata $inverseJoinColumn */
                if (! $inverseJoinColumn->getReferencedColumnName()) {
                    $inverseJoinColumn->setReferencedColumnName($this->namingStrategy->referenceColumnName());
                }

                $referencedColumnName = $inverseJoinColumn->getReferencedColumnName();

                if (! $inverseJoinColumn->getColumnName()) {
                    $columnName = $this->namingStrategy->joinKeyColumnName(
                        $property->getTargetEntity(),
                        $referencedColumnName
                    );

                    $inverseJoinColumn->setColumnName($columnName);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifierFieldNames()
    {
        return $this->identifier;
    }

    /**
     * Gets the name of the single id field. Note that this only works on
     * entity classes that have a single-field pk.
     *
     * @return string
     *
     * @throws MappingException If the class has a composite primary key.
     */
    public function getSingleIdentifierFieldName()
    {
        if ($this->isIdentifierComposite()) {
            throw MappingException::singleIdNotAllowedOnCompositePrimaryKey($this->className);
        }

        if ( ! isset($this->identifier[0])) {
            throw MappingException::noIdDefined($this->className);
        }

        return $this->identifier[0];
    }

    /**
     * INTERNAL:
     * Sets the mapped identifier/primary key fields of this class.
     * Mainly used by the ClassMetadataFactory to assign inherited identifiers.
     *
     * @param array $identifier
     *
     * @return void
     */
    public function setIdentifier(array $identifier)
    {
        $this->identifier = $identifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * {@inheritDoc}
     */
    public function hasField($fieldName)
    {
        return isset($this->declaredProperties[$fieldName])
            && $this->declaredProperties[$fieldName] instanceof FieldMetadata;
    }

    /**
     * Returns an array with identifier column names and their corresponding ColumnMetadata.
     *
     * @param EntityManagerInterface $em
     *
     * @return array
     */
    public function getIdentifierColumns(EntityManagerInterface $em) : array
    {
        $columns = [];

        foreach ($this->identifier as $idProperty) {
            $property = $this->getProperty($idProperty);

            if ($property instanceof FieldMetadata) {
                $columns[$property->getColumnName()] = $property;

                continue;
            }

            /** @var AssociationMetadata $property */

            // Association defined as Id field
            $targetClass = $em->getClassMetadata($property->getTargetEntity());

            if (! $property->isOwningSide()) {
                $property    = $targetClass->getProperty($property->getMappedBy());
                $targetClass = $em->getClassMetadata($property->getTargetEntity());
            }

            $joinColumns = $property instanceof ManyToManyAssociationMetadata
                ? $property->getJoinTable()->getInverseJoinColumns()
                : $property->getJoinColumns()
            ;

            foreach ($joinColumns as $joinColumn) {
                /** @var JoinColumnMetadata $joinColumn */
                $columnName           = $joinColumn->getColumnName();
                $referencedColumnName = $joinColumn->getReferencedColumnName();

                if (! $joinColumn->getType()) {
                    $joinColumn->setType(PersisterHelper::getTypeOfColumn($referencedColumnName, $targetClass, $em));
                }

                $columns[$columnName] = $joinColumn;
            }
        }

        return $columns;
    }

    /**
     * Gets the name of the primary table.
     *
     * @return string|null
     */
    public function getTableName() : ?string
    {
        return $this->table->getName();
    }

    /**
     * Gets primary table's schema name.
     *
     * @return string|null
     */
    public function getSchemaName() : ?string
    {
        return $this->table->getSchema();
    }

    /**
     * Gets the table name to use for temporary identifier tables of this class.
     *
     * @return string
     */
    public function getTemporaryIdTableName() : string
    {
        $schema = null === $this->getSchemaName()
            ? ''
            : $this->getSchemaName() . '_'
        ;

        // replace dots with underscores because PostgreSQL creates temporary tables in a special schema
        return $schema . $this->getTableName() . '_id_tmp';
    }

    /**
     * Sets the mapped subclasses of this class.
     *
     * @todo guilhermeblanco Only used for ClassMetadataTest. Remove if possible!
     *
     * @param array $subclasses The names of all mapped subclasses.
     *
     * @return void
     */
    public function setSubclasses(array $subclasses) : void
    {
        foreach ($subclasses as $subclass) {
            $this->subClasses[] = $subclass;
        }
    }

    /**
     * @return array
     */
    public function getSubClasses() : array
    {
        return $this->subClasses;
    }

    /**
     * Sets the inheritance type used by the class and its subclasses.
     *
     * @param integer $type
     *
     * @return void
     *
     * @throws MappingException
     */
    public function setInheritanceType($type) : void
    {
        if ( ! $this->isInheritanceType($type)) {
            throw MappingException::invalidInheritanceType($this->className, $type);
        }

        $this->inheritanceType = $type;
    }

    /**
     * Sets the override property mapping for an entity relationship.
     *
     * @param Property $property
     *
     * @return void
     *
     * @throws \RuntimeException
     * @throws MappingException
     * @throws CacheException
     */
    public function setPropertyOverride(Property $property) : void
    {
        $fieldName = $property->getName();

        if (! isset($this->declaredProperties[$fieldName])) {
            throw MappingException::invalidOverrideFieldName($this->className, $fieldName);
        }

        $originalProperty          = $this->getProperty($fieldName);
        $originalPropertyClassName = get_class($originalProperty);

        // If moving from transient to persistent, assume it's a new property
        if ($originalPropertyClassName === TransientMetadata::class) {
            unset($this->declaredProperties[$fieldName]);

            $this->addProperty($property);

            return;
        }

        // Do not allow to change property type
        if ($originalPropertyClassName !== get_class($property)) {
            throw MappingException::invalidOverridePropertyType($this->className, $fieldName);
        }

        // Do not allow to change version property
        if ($originalProperty instanceof VersionFieldMetadata) {
            throw MappingException::invalidOverrideVersionField($this->className, $fieldName);
        }

        unset($this->declaredProperties[$fieldName]);

        if ($property instanceof FieldMetadata) {
            // Unset defined fieldName prior to override
            unset($this->fieldNames[$originalProperty->getColumnName()]);

            // Revert what should not be allowed to change
            $property->setDeclaringClass($originalProperty->getDeclaringClass());
            $property->setPrimaryKey($originalProperty->isPrimaryKey());
        } else if ($property instanceof AssociationMetadata) {
            // Unset all defined fieldNames prior to override
            if ($originalProperty instanceof ToOneAssociationMetadata && $originalProperty->isOwningSide()) {
                foreach ($originalProperty->getJoinColumns() as $joinColumn) {
                    unset($this->fieldNames[$joinColumn->getColumnName()]);
                }
            }

            // Override what it should be allowed to change
            if ($property->getInversedBy()) {
                $originalProperty->setInversedBy($property->getInversedBy());
            }

            if ($property->getFetchMode() !== $originalProperty->getFetchMode()) {
                $originalProperty->setFetchMode($property->getFetchMode());
            }

            if ($originalProperty instanceof ToOneAssociationMetadata && $property->getJoinColumns()) {
                $originalProperty->setJoinColumns($property->getJoinColumns());
            } else if ($originalProperty instanceof ManyToManyAssociationMetadata && $property->getJoinTable()) {
                $originalProperty->setJoinTable($property->getJoinTable());
            }

            $property = $originalProperty;
        }

        $this->addProperty($property);
    }

    /**
     * Checks if this entity is the root in any entity-inheritance-hierarchy.
     *
     * @return bool
     */
    public function isRootEntity()
    {
        return $this->className === $this->getRootClassName();
    }

    /**
     * Checks whether a mapped field is inherited from a superclass.
     *
     * @param string $fieldName
     *
     * @return boolean TRUE if the field is inherited, FALSE otherwise.
     */
    public function isInheritedProperty($fieldName)
    {
        $declaringClass = $this->declaredProperties[$fieldName]->getDeclaringClass();

        return ! ($declaringClass->className === $this->className);
    }

    /**
     * {@inheritdoc}
     */
    public function setTable(TableMetadata $table) : void
    {
        $this->table = $table;

        if (empty($table->getName())) {
            $table->setName($this->namingStrategy->classToTableName($this->className));
        }
    }

    /**
     * Checks whether the given type identifies an inheritance type.
     *
     * @param integer $type
     *
     * @return boolean TRUE if the given type identifies an inheritance type, FALSe otherwise.
     */
    private function isInheritanceType($type)
    {
        return $type == InheritanceType::NONE
            || $type == InheritanceType::SINGLE_TABLE
            || $type == InheritanceType::JOINED
            || $type == InheritanceType::TABLE_PER_CLASS;
    }

    /**
     * @param string $columnName
     *
     * @return LocalColumnMetadata|null
     */
    public function getColumn(string $columnName): ?LocalColumnMetadata
    {
        foreach ($this->declaredProperties as $property) {
            if ($property instanceof LocalColumnMetadata && $property->getColumnName() === $columnName) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Add a property mapping.
     *
     * @param Property $property
     *
     * @throws \RuntimeException
     * @throws MappingException
     * @throws CacheException
     */
    public function addProperty(Property $property)
    {
        $fieldName = $property->getName();

        // Check for empty field name
        if (empty($fieldName)) {
            throw MappingException::missingFieldName($this->className);
        }

        $property->setDeclaringClass($this);

        switch (true) {
            case ($property instanceof VersionFieldMetadata):
                $this->validateAndCompleteFieldMapping($property);
                $this->validateAndCompleteVersionFieldMapping($property);
                break;

            case ($property instanceof FieldMetadata):
                $this->validateAndCompleteFieldMapping($property);
                break;

            case ($property instanceof OneToOneAssociationMetadata):
                $this->validateAndCompleteAssociationMapping($property);
                $this->validateAndCompleteToOneAssociationMetadata($property);
                $this->validateAndCompleteOneToOneMapping($property);
                break;

            case ($property instanceof OneToManyAssociationMetadata):
                $this->validateAndCompleteAssociationMapping($property);
                $this->validateAndCompleteToManyAssociationMetadata($property);
                $this->validateAndCompleteOneToManyMapping($property);
                break;

            case ($property instanceof ManyToOneAssociationMetadata):
                $this->validateAndCompleteAssociationMapping($property);
                $this->validateAndCompleteToOneAssociationMetadata($property);
                $this->validateAndCompleteManyToOneMapping($property);
                break;

            case ($property instanceof ManyToManyAssociationMetadata):
                $this->validateAndCompleteAssociationMapping($property);
                $this->validateAndCompleteToManyAssociationMetadata($property);
                $this->validateAndCompleteManyToManyMapping($property);
                break;

            default:
                // Transient properties are ignored on purpose here! =)
                break;
        }

        $this->addDeclaredProperty($property);
    }

    /**
     * INTERNAL:
     * Adds a property mapping without completing/validating it.
     * This is mainly used to add inherited property mappings to derived classes.
     *
     * @param Property $property
     *
     * @return void
     */
    public function addInheritedProperty(Property $property)
    {
        $inheritedProperty = clone $property;
        $declaringClass    = $property->getDeclaringClass();

        if ($inheritedProperty instanceof FieldMetadata) {
            if (! $declaringClass->isMappedSuperclass) {
                $inheritedProperty->setTableName($property->getTableName());
            }

            $this->fieldNames[$property->getColumnName()] = $property->getName();
        } else if ($inheritedProperty instanceof AssociationMetadata) {
            if ($declaringClass->isMappedSuperclass) {
                $inheritedProperty->setSourceEntity($this->className);
            }

            // Need to add inherited fieldNames
            if ($inheritedProperty instanceof ToOneAssociationMetadata && $inheritedProperty->isOwningSide()) {
                foreach ($inheritedProperty->getJoinColumns() as $joinColumn) {
                    /** @var JoinColumnMetadata $joinColumn */
                    $this->fieldNames[$joinColumn->getColumnName()] = $property->getName();
                }
            }
        }

        if (isset($this->declaredProperties[$property->getName()])) {
            throw MappingException::duplicateProperty($this->className, $this->getProperty($property->getName()));
        }

        $this->declaredProperties[$property->getName()] = $inheritedProperty;

        if ($inheritedProperty instanceof VersionFieldMetadata) {
            $this->versionProperty = $inheritedProperty;
        }
    }

    /**
     * INTERNAL:
     * Adds a named query to this class.
     *
     * @param string $name
     * @param string $query
     *
     * @return void
     *
     * @throws MappingException
     */
    public function addNamedQuery(string $name, string $query)
    {
        if (isset($this->namedQueries[$name])) {
            throw MappingException::duplicateQueryMapping($this->className, $name);
        }

        $this->namedQueries[$name] = $query;
    }

    /**
     * INTERNAL:
     * Adds a named native query to this class.
     *
     * @param string $name
     * @param string $query
     * @param array  $queryMapping
     *
     * @return void
     *
     * @throws MappingException
     */
    public function addNamedNativeQuery(string $name, string $query, array $queryMapping)
    {
        if (isset($this->namedNativeQueries[$name])) {
            throw MappingException::duplicateQueryMapping($this->className, $name);
        }

        if (! isset($queryMapping['resultClass']) && ! isset($queryMapping['resultSetMapping'])) {
            throw MappingException::missingQueryMapping($this->className, $name);
        }

        if (isset($queryMapping['resultClass']) && $queryMapping['resultClass'] !== '__CLASS__') {
            $queryMapping['resultClass'] = $this->fullyQualifiedClassName($queryMapping['resultClass']);
        }

        $this->namedNativeQueries[$name] = array_merge(['query' => $query], $queryMapping);
    }

    /**
     * INTERNAL:
     * Adds a sql result set mapping to this class.
     *
     * @param array $resultMapping
     *
     * @return void
     *
     * @throws MappingException
     */
    public function addSqlResultSetMapping(array $resultMapping)
    {
        if (!isset($resultMapping['name'])) {
            throw MappingException::nameIsMandatoryForSqlResultSetMapping($this->className);
        }

        if (isset($this->sqlResultSetMappings[$resultMapping['name']])) {
            throw MappingException::duplicateResultSetMapping($this->className, $resultMapping['name']);
        }

        if (isset($resultMapping['entities'])) {
            foreach ($resultMapping['entities'] as $key => $entityResult) {
                if (! isset($entityResult['entityClass'])) {
                    throw MappingException::missingResultSetMappingEntity($this->className, $resultMapping['name']);
                }

                $entityClassName = ($entityResult['entityClass'] !== '__CLASS__')
                    ? $this->fullyQualifiedClassName($entityResult['entityClass'])
                    : $entityResult['entityClass']
                ;

                $resultMapping['entities'][$key]['entityClass'] = $entityClassName;

                if (isset($entityResult['fields'])) {
                    foreach ($entityResult['fields'] as $k => $field) {
                        if (! isset($field['name'])) {
                            throw MappingException::missingResultSetMappingFieldName($this->className, $resultMapping['name']);
                        }

                        if (! isset($field['column'])) {
                            $fieldName = $field['name'];

                            if (strpos($fieldName, '.')) {
                                list(, $fieldName) = explode('.', $fieldName);
                            }

                            $resultMapping['entities'][$key]['fields'][$k]['column'] = $fieldName;
                        }
                    }
                }
            }
        }

        $this->sqlResultSetMappings[$resultMapping['name']] = $resultMapping;
    }

    /**
     * Registers a custom repository class for the entity class.
     *
     * @param string|null $repositoryClassName The class name of the custom mapper.
     *
     * @return void
     */
    public function setCustomRepositoryClassName(?string $repositoryClassName)
    {
        $this->customRepositoryClassName = $repositoryClassName;
    }

    /**
     * @return string|null
     */
    public function getCustomRepositoryClassName() : ?string
    {
        return $this->customRepositoryClassName;
    }

    /**
     * Whether the class has any attached lifecycle listeners or callbacks for a lifecycle event.
     *
     * @param string $lifecycleEvent
     *
     * @return boolean
     */
    public function hasLifecycleCallbacks($lifecycleEvent)
    {
        return isset($this->lifecycleCallbacks[$lifecycleEvent]);
    }

    /**
     * Gets the registered lifecycle callbacks for an event.
     *
     * @param string $event
     *
     * @return array
     */
    public function getLifecycleCallbacks($event)
    {
        return $this->lifecycleCallbacks[$event] ?? [];
    }

    /**
     * Adds a lifecycle callback for entities of this class.
     *
     * @param string $callback
     * @param string $event
     *
     * @return void
     */
    public function addLifecycleCallback($callback, $event)
    {
        if (isset($this->lifecycleCallbacks[$event]) && in_array($callback, $this->lifecycleCallbacks[$event])) {
            return;
        }

        $this->lifecycleCallbacks[$event][] = $callback;
    }

    /**
     * Sets the lifecycle callbacks for entities of this class.
     * Any previously registered callbacks are overwritten.
     *
     * @param array $callbacks
     *
     * @return void
     */
    public function setLifecycleCallbacks(array $callbacks) : void
    {
        $this->lifecycleCallbacks = $callbacks;
    }

    /**
     * Adds a entity listener for entities of this class.
     *
     * @param string $eventName The entity lifecycle event.
     * @param string $class     The listener class.
     * @param string $method    The listener callback method.
     *
     * @return void
     *
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function addEntityListener(string $eventName, string $class, string $method) : void
    {
        $listener = [
            'class'  => $class,
            'method' => $method,
        ];

        if (! class_exists($class)) {
            throw MappingException::entityListenerClassNotFound($class, $this->className);
        }

        if (! method_exists($class, $method)) {
            throw MappingException::entityListenerMethodNotFound($class, $method, $this->className);
        }

        if (isset($this->entityListeners[$eventName]) && in_array($listener, $this->entityListeners[$eventName], true)) {
            throw MappingException::duplicateEntityListener($class, $method, $this->className);
        }

        $this->entityListeners[$eventName][] = $listener;
    }

    /**
     * Sets the discriminator column definition.
     *
     * @param DiscriminatorColumnMetadata $discriminatorColumn
     *
     * @return void
     *
     * @throws MappingException
     *
     * @see getDiscriminatorColumn()
     */
    public function setDiscriminatorColumn(DiscriminatorColumnMetadata $discriminatorColumn) : void
    {
        if (isset($this->fieldNames[$discriminatorColumn->getColumnName()])) {
            throw MappingException::duplicateColumnName($this->className, $discriminatorColumn->getColumnName());
        }

        $discriminatorColumn->setTableName($discriminatorColumn->getTableName() ?? $this->getTableName());

        $allowedTypeList = ['boolean', 'array', 'object', 'datetime', 'time', 'date'];

        if (in_array($discriminatorColumn->getTypeName(), $allowedTypeList, true)) {
            throw MappingException::invalidDiscriminatorColumnType($discriminatorColumn->getTypeName());
        }

        $this->discriminatorColumn = $discriminatorColumn;
    }

    /**
     * Sets the discriminator values used by this class.
     * Used for JOINED and SINGLE_TABLE inheritance mapping strategies.
     *
     * @param array $map
     *
     * @return void
     *
     * @throws MappingException
     */
    public function setDiscriminatorMap(array $map) : void
    {
        foreach ($map as $value => $className) {
            $this->addDiscriminatorMapClass($value, $className);
        }
    }

    /**
     * Adds one entry of the discriminator map with a new class and corresponding name.
     *
     * @param string|int $name
     * @param string     $className
     *
     * @return void
     *
     * @throws MappingException
     */
    public function addDiscriminatorMapClass($name, string $className) : void
    {
        $this->discriminatorMap[$name] = $className;

        if ($this->className === $className) {
            $this->discriminatorValue = $name;

            return;
        }

        if (! (class_exists($className) || interface_exists($className))) {
            throw MappingException::invalidClassInDiscriminatorMap($className, $this->className);
        }

        if (is_subclass_of($className, $this->className) && ! in_array($className, $this->subClasses, true)) {
            $this->subClasses[] = $className;
        }
    }

    /**
     * @return ValueGenerationPlan
     */
    public function getValueGenerationPlan() : ValueGenerationPlan
    {
        return $this->valueGenerationPlan;
    }

    /**
     * @param ValueGenerationPlan $valueGenerationPlan
     */
    public function setValueGenerationPlan(ValueGenerationPlan $valueGenerationPlan) : void
    {
        $this->valueGenerationPlan = $valueGenerationPlan;
    }

    /**
     * Checks whether the class has a named query with the given query name.
     *
     * @param string $queryName
     *
     * @return boolean
     */
    public function hasNamedQuery($queryName) : bool
    {
        return isset($this->namedQueries[$queryName]);
    }

    /**
     * Checks whether the class has a named native query with the given query name.
     *
     * @param string $queryName
     *
     * @return boolean
     */
    public function hasNamedNativeQuery($queryName) : bool
    {
        return isset($this->namedNativeQueries[$queryName]);
    }

    /**
     * Checks whether the class has a named native query with the given query name.
     *
     * @param string $name
     *
     * @return boolean
     */
    public function hasSqlResultSetMapping($name) : bool
    {
        return isset($this->sqlResultSetMappings[$name]);
    }

    /**
     * Marks this class as read only, no change tracking is applied to it.
     *
     * @return void
     */
    public function asReadOnly() : void
    {
        $this->readOnly = true;
    }

    /**
     * Whether this class is read only or not.
     *
     * @return bool
     */
    public function isReadOnly() : bool
    {
        return $this->readOnly;
    }

    /**
     * @return bool
     */
    public function isVersioned() : bool
    {
        return $this->versionProperty !== null;
    }

    /**
     * Map Embedded Class
     *
     * @param array $mapping
     *
     * @throws MappingException
     * @return void
     */
    public function mapEmbedded(array $mapping) : void
    {
        /*if (isset($this->declaredProperties[$mapping['fieldName']])) {
            throw MappingException::duplicateProperty($this->className, $this->getProperty($mapping['fieldName']));
        }

        $this->embeddedClasses[$mapping['fieldName']] = [
            'class'          => $this->fullyQualifiedClassName($mapping['class']),
            'columnPrefix'   => $mapping['columnPrefix'],
            'declaredField'  => $mapping['declaredField'] ?? null,
            'originalField'  => $mapping['originalField'] ?? null,
            'declaringClass' => $this,
        ];*/
    }

    /**
     * Inline the embeddable class
     *
     * @param string        $property
     * @param ClassMetadata $embeddable
     */
    public function inlineEmbeddable($property, ClassMetadata $embeddable) : void
    {
        /*foreach ($embeddable->fieldMappings as $fieldName => $fieldMapping) {
            $fieldMapping['fieldName']     = $property . "." . $fieldName;
            $fieldMapping['originalClass'] = $fieldMapping['originalClass'] ?? $embeddable->getClassName();
            $fieldMapping['originalField'] = $fieldMapping['originalField'] ?? $fieldName;
            $fieldMapping['declaredField'] = isset($fieldMapping['declaredField'])
                ? $property . '.' . $fieldMapping['declaredField']
                : $property;

            if (! empty($this->embeddedClasses[$property]['columnPrefix'])) {
                $fieldMapping['columnName'] = $this->embeddedClasses[$property]['columnPrefix'] . $fieldMapping['columnName'];
            } elseif ($this->embeddedClasses[$property]['columnPrefix'] !== false) {
                $fieldMapping['columnName'] = $this->namingStrategy->embeddedFieldToColumnName(
                    $property,
                    $fieldMapping['columnName'],
                    $this->reflectionClass->getName(),
                    $embeddable->reflectionClass->getName()
                );
            }

            $this->mapField($fieldMapping);
        }*/
    }
}
