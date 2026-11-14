<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use BackedEnum;
use BadMethodCallException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use Doctrine\Instantiator\Instantiator;
use Doctrine\Instantiator\InstantiatorInterface;
use Doctrine\ORM\Cache\Exception\NonCacheableEntityAssociation;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Mapping\PropertyAccessors\EmbeddablePropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\EnumPropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\Mapping\ReflectionService;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SortDirection;
use Stringable;

use function array_column;
use function array_count_values;
use function array_diff;
use function array_filter;
use function array_flip;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_values;
use function assert;
use function class_exists;
use function count;
use function defined;
use function enum_exists;
use function explode;
use function func_get_arg;
use function func_num_args;
use function implode;
use function in_array;
use function interface_exists;
use function is_string;
use function is_subclass_of;
use function ltrim;
use function method_exists;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;
use function trait_exists;
use function trim;
use function usort;

/**
 * A <tt>ClassMetadata</tt> instance holds all the object-relational mapping metadata
 * of an entity and its associations.
 *
 * Once populated, ClassMetadata instances are usually cached in a serialized form.
 *
 * <b>IMPORTANT NOTE:</b>
 *
 * The fields of this class are only public for 2 reasons:
 * 1) To allow fast READ access.
 * 2) To drastically reduce the size of a serialized instance (private/protected members
 *    get the whole class name, namespace inclusive, prepended to every property in
 *    the serialized representation).
 *
 * @phpstan-type ConcreteAssociationMapping = OneToOneOwningSideMapping|OneToOneInverseSideMapping|ManyToOneAssociationMapping|OneToManyAssociationMapping|ManyToManyOwningSideMapping|ManyToManyInverseSideMapping
 * @template-covariant T of object
 * @template-implements PersistenceClassMetadata<T>
 */
class ClassMetadata implements PersistenceClassMetadata, Stringable
{
    use GetReflectionClassImplementation;

    /* The inheritance mapping types */
    /**
     * NONE means the class does not participate in an inheritance hierarchy
     * and therefore does not need an inheritance mapping type.
     */
    public const INHERITANCE_TYPE_NONE = 1;

    /**
     * JOINED means the class will be persisted according to the rules of
     * <tt>Class Table Inheritance</tt>.
     */
    public const INHERITANCE_TYPE_JOINED = 2;

    /**
     * SINGLE_TABLE means the class will be persisted according to the rules of
     * <tt>Single Table Inheritance</tt>.
     */
    public const INHERITANCE_TYPE_SINGLE_TABLE = 3;

    /* The Id generator types. */
    /**
     * AUTO means the generator type will depend on what the used platform prefers.
     * Offers full portability.
     */
    public const GENERATOR_TYPE_AUTO = 1;

    /**
     * SEQUENCE means a separate sequence object will be used. Platforms that do
     * not have native sequence support may emulate it. Full portability is currently
     * not guaranteed.
     */
    public const GENERATOR_TYPE_SEQUENCE = 2;

    /**
     * IDENTITY means an identity column is used for id generation. The database
     * will fill in the id column on insertion. Platforms that do not support
     * native identity columns may emulate them. Full portability is currently
     * not guaranteed.
     */
    public const GENERATOR_TYPE_IDENTITY = 4;

    /**
     * NONE means the class does not have a generated id. That means the class
     * must have a natural, manually assigned id.
     */
    public const GENERATOR_TYPE_NONE = 5;

    /**
     * CUSTOM means that customer will use own ID generator that supposedly work
     */
    public const GENERATOR_TYPE_CUSTOM = 7;

    /**
     * DEFERRED_IMPLICIT means that changes of entities are calculated at commit-time
     * by doing a property-by-property comparison with the original data. This will
     * be done for all entities that are in MANAGED state at commit-time.
     *
     * This is the default change tracking policy.
     */
    public const CHANGETRACKING_DEFERRED_IMPLICIT = 1;

    /**
     * DEFERRED_EXPLICIT means that changes of entities are calculated at commit-time
     * by doing a property-by-property comparison with the original data. This will
     * be done only for entities that were explicitly saved (through persist() or a cascade).
     */
    public const CHANGETRACKING_DEFERRED_EXPLICIT = 2;

    /**
     * Specifies that an association is to be fetched when it is first accessed.
     */
    public const FETCH_LAZY = 2;

    /**
     * Specifies that an association is to be fetched when the owner of the
     * association is fetched.
     */
    public const FETCH_EAGER = 3;

    /**
     * Specifies that an association is to be fetched lazy (on first access) and that
     * commands such as Collection#count, Collection#slice are issued directly against
     * the database if the collection is not yet initialized.
     */
    public const FETCH_EXTRA_LAZY = 4;

    /**
     * Identifies a one-to-one association.
     */
    public const ONE_TO_ONE = 1;

    /**
     * Identifies a many-to-one association.
     */
    public const MANY_TO_ONE = 2;

    /**
     * Identifies a one-to-many association.
     */
    public const ONE_TO_MANY = 4;

    /**
     * Identifies a many-to-many association.
     */
    public const MANY_TO_MANY = 8;

    /**
     * Combined bitmask for to-one (single-valued) associations.
     */
    public const TO_ONE = 3;

    /**
     * Combined bitmask for to-many (collection-valued) associations.
     */
    public const TO_MANY = 12;

    /**
     * ReadOnly cache can do reads, inserts and deletes, cannot perform updates or employ any locks,
     */
    public const CACHE_USAGE_READ_ONLY = 1;

    /**
     * Nonstrict Read Write Cache doesn’t employ any locks but can do inserts, update and deletes.
     */
    public const CACHE_USAGE_NONSTRICT_READ_WRITE = 2;

    /**
     * Read Write Attempts to lock the entity before update/delete.
     */
    public const CACHE_USAGE_READ_WRITE = 3;

    /**
     * The value of this column is never generated by the database.
     */
    public const GENERATED_NEVER = 0;

    /**
     * The value of this column is generated by the database on INSERT, but not on UPDATE.
     */
    public const GENERATED_INSERT = 1;

    /**
     * The value of this column is generated by the database on both INSERT and UDPATE statements.
     */
    public const GENERATED_ALWAYS = 2;

    /**
     * READ-ONLY: The namespace the entity class is contained in.
     *
     * @todo Not really needed. Usage could be localized.
     */
    public string|null $namespace = null;

    /**
     * READ-ONLY: The name of the entity class that is at the root of the mapped entity inheritance
     * hierarchy. If the entity is not part of a mapped inheritance hierarchy this is the same
     * as {@link $name}.
     *
     * @phpstan-var class-string
     */
    public string $rootEntityName;

    /**
     * READ-ONLY: The definition of custom generator. Only used for CUSTOM
     * generator type
     *
     * The definition has the following structure:
     * <code>
     * array(
     *     'class' => 'ClassName',
     * )
     * </code>
     *
     * @todo Merge with tableGeneratorDefinition into generic generatorDefinition
     * @var array<string, string>|null
     */
    public array|null $customGeneratorDefinition = null;

    /**
     * The name of the custom repository class used for the entity class.
     * (Optional).
     *
     * @phpstan-var ?class-string<EntityRepository>
     */
    public string|null $customRepositoryClassName = null;

    /**
     * READ-ONLY: Whether this class describes the mapping of a mapped superclass.
     */
    public bool $isMappedSuperclass = false;

    /**
     * READ-ONLY: Whether this class describes the mapping of an embeddable class.
     */
    public bool $isEmbeddedClass = false;

    /**
     * READ-ONLY: The names of the parent <em>entity</em> classes (ancestors), starting with the
     * nearest one and ending with the root entity class.
     *
     * @phpstan-var list<class-string>
     */
    public array $parentClasses = [];

    /**
     * READ-ONLY: For classes in inheritance mapping hierarchies, this field contains the names of all
     * <em>entity</em> subclasses of this class. These may also be abstract classes.
     *
     * This list is used, for example, to enumerate all necessary tables in JTI when querying for root
     * or subclass entities, or to gather all fields comprised in an entity inheritance tree.
     *
     * For classes that do not use STI/JTI, this list is empty.
     *
     * Implementation note:
     *
     * In PHP, there is no general way to discover all subclasses of a given class at runtime. For that
     * reason, the list of classes given in the discriminator map at the root entity is considered
     * authoritative. The discriminator map must contain all <em>concrete</em> classes that can
     * appear in the particular inheritance hierarchy tree. Since there can be no instances of abstract
     * entity classes, users are not required to list such classes with a discriminator value.
     *
     * The possibly remaining "gaps" for abstract entity classes are filled after the class metadata for the
     * root entity has been loaded.
     *
     * For subclasses of such root entities, the list can be reused/passed downwards, it only needs to
     * be filtered accordingly (only keep remaining subclasses)
     *
     * @phpstan-var list<class-string>
     */
    public array $subClasses = [];

    /**
     * READ-ONLY: The names of all embedded classes based on properties.
     *
     * @phpstan-var array<string, EmbeddedClassMapping>
     */
    public array $embeddedClasses = [];

    /**
     * READ-ONLY: The field names of all fields that are part of the identifier/primary key
     * of the mapped entity class.
     *
     * @phpstan-var list<string>
     */
    public array $identifier = [];

    /**
     * READ-ONLY: The position of each identifier field, used to control the order of composite primary key fields.
     *
     * @phpstan-var array<string, int>
     */
    public array $identifierPositions = [];

    /**
     * READ-ONLY: The inheritance mapping type used by the class.
     *
     * @phpstan-var self::INHERITANCE_TYPE_*
     */
    public int $inheritanceType = self::INHERITANCE_TYPE_NONE;

    /**
     * READ-ONLY: The Id generator type used by the class.
     *
     * @phpstan-var self::GENERATOR_TYPE_*
     */
    public int $generatorType = self::GENERATOR_TYPE_NONE;

    /**
     * READ-ONLY: The field mappings of the class.
     * Keys are field names and values are FieldMapping instances
     *
     * @var array<string, FieldMapping>
     */
    public array $fieldMappings = [];

    /**
     * READ-ONLY: An array of field names. Used to look up field names from column names.
     * Keys are column names and values are field names.
     *
     * @phpstan-var array<string, string>
     */
    public array $fieldNames = [];

    /**
     * READ-ONLY: A map of field names to column names. Keys are field names and values column names.
     * Used to look up column names from field names.
     * This is the reverse lookup map of $_fieldNames.
     *
     * @deprecated 3.0 Remove this.
     *
     * @var mixed[]
     */
    public array $columnNames = [];

    /**
     * READ-ONLY: The discriminator value of this class.
     *
     * <b>This does only apply to the JOINED and SINGLE_TABLE inheritance mapping strategies
     * where a discriminator column is used.</b>
     *
     * @see discriminatorColumn
     */
    public mixed $discriminatorValue = null;

    /**
     * READ-ONLY: The discriminator map of all mapped classes in the hierarchy.
     *
     * <b>This does only apply to the JOINED and SINGLE_TABLE inheritance mapping strategies
     * where a discriminator column is used.</b>
     *
     * @see discriminatorColumn
     *
     * @var array<int|string, string>
     *
     * @phpstan-var array<int|string, class-string>
     */
    public array $discriminatorMap = [];

    /**
     * READ-ONLY: The definition of the discriminator column used in JOINED and SINGLE_TABLE
     * inheritance mappings.
     */
    public DiscriminatorColumnMapping|null $discriminatorColumn = null;

    /**
     * READ-ONLY: The primary table definition.
     *
     * "quoted" indicates whether the table name is quoted (with backticks) or not
     *
     * @var mixed[]
     * @phpstan-var array{
     *               name: string,
     *               schema?: string,
     *               indexes?: array,
     *               uniqueConstraints?: array,
     *               options?: array<string, mixed>,
     *               quoted?: bool
     *           }
     */
    public array $table;

    /**
     * READ-ONLY: The registered lifecycle callbacks for entities of this class.
     *
     * @phpstan-var array<string, list<string>>
     */
    public array $lifecycleCallbacks = [];

    /**
     * READ-ONLY: The registered entity listeners.
     *
     * @phpstan-var array<string, list<array{class: class-string, method: string}>>
     */
    public array $entityListeners = [];

    /**
     * READ-ONLY: The association mappings of this class.
     *
     * A join table definition has the following structure:
     * <pre>
     * array(
     *     'name' => <join table name>,
     *      'joinColumns' => array(<join column mapping from join table to source table>),
     *      'inverseJoinColumns' => array(<join column mapping from join table to target table>)
     * )
     * </pre>
     *
     * @phpstan-var array<string, ConcreteAssociationMapping>
     */
    public array $associationMappings = [];

    /**
     * READ-ONLY: Flag indicating whether the identifier/primary key of the class is composite.
     */
    public bool $isIdentifierComposite = false;

    /**
     * READ-ONLY: Flag indicating whether the identifier/primary key contains at least one foreign key association.
     *
     * This flag is necessary because some code blocks require special treatment of this cases.
     */
    public bool $containsForeignIdentifier = false;

    /**
     * READ-ONLY: Flag indicating whether the identifier/primary key contains at least one ENUM type.
     *
     * This flag is necessary because some code blocks require special treatment of this cases.
     */
    public bool $containsEnumIdentifier = false;

    /**
     * READ-ONLY: The ID generator used for generating IDs for this class.
     *
     * @todo Remove!
     */
    public AbstractIdGenerator $idGenerator;

    /**
     * READ-ONLY: The definition of the sequence generator of this class. Only used for the
     * SEQUENCE generation strategy.
     *
     * The definition has the following structure:
     * <code>
     * array(
     *     'sequenceName' => 'name',
     *     'allocationSize' => '20',
     *     'initialValue' => '1'
     * )
     * </code>
     *
     * @var array<string, mixed>|null
     * @phpstan-var array{sequenceName: string, allocationSize: string, initialValue: string, quoted?: mixed}|null
     * @todo Merge with tableGeneratorDefinition into generic generatorDefinition
     */
    public array|null $sequenceGeneratorDefinition = null;

    /**
     * READ-ONLY: The policy used for change-tracking on entities of this class.
     */
    public int $changeTrackingPolicy = self::CHANGETRACKING_DEFERRED_IMPLICIT;

    /**
     * READ-ONLY: A Flag indicating whether one or more columns of this class
     * have to be reloaded after insert / update operations.
     */
    public bool $requiresFetchAfterChange = false;

    /**
     * READ-ONLY: A flag for whether or not instances of this class are to be versioned
     * with optimistic locking.
     */
    public bool $isVersioned = false;

    /**
     * READ-ONLY: The name of the field which is used for versioning in optimistic locking (if any).
     */
    public string|null $versionField = null;

    /** @var mixed[]|null */
    public array|null $cache = null;

    /**
     * The ReflectionClass instance of the mapped class.
     *
     * @var ReflectionClass<T>|null
     */
    public ReflectionClass|null $reflClass = null;

    /**
     * Is this entity marked as "read-only"?
     *
     * That means it is never considered for change-tracking in the UnitOfWork. It is a very helpful performance
     * optimization for entities that are immutable, either in your domain or through the relation database
     * (coming from a view, or a history table for example).
     */
    public bool $isReadOnly = false;

    /**
     * NamingStrategy determining the default column and table names.
     */
    protected NamingStrategy $namingStrategy;

    /**
     * The ReflectionProperty instances of the mapped class.
     *
     * @deprecated Use $propertyAccessors instead.
     *
     * @var LegacyReflectionFields|array<string, ReflectionProperty>
     */
    public LegacyReflectionFields|array $reflFields = [];

    /** @var array<string, PropertyAccessor> */
    public array $propertyAccessors = [];

    private InstantiatorInterface|null $instantiator = null;

    private readonly TypedFieldMapper $typedFieldMapper;

    /**
     * Initializes a new ClassMetadata instance that will hold the object-relational mapping
     * metadata of the class with the given name.
     *
     * @param string $name The name of the entity class the new instance is used for.
     * @phpstan-param class-string<T> $name
     */
    public function __construct(public string $name, NamingStrategy|null $namingStrategy = null, TypedFieldMapper|null $typedFieldMapper = null)
    {
        $this->rootEntityName   = $name;
        $this->namingStrategy   = $namingStrategy ?? new DefaultNamingStrategy();
        $this->instantiator     = new Instantiator();
        $this->typedFieldMapper = $typedFieldMapper ?? new DefaultTypedFieldMapper();
    }

    /**
     * Gets the ReflectionProperties of the mapped class.
     *
     * @deprecated Use getPropertyAccessors() instead.
     *
     * @return LegacyReflectionFields|ReflectionProperty[] An array of ReflectionProperty instances.
     * @phpstan-return LegacyReflectionFields|array<string, ReflectionProperty>
     */
    public function getReflectionProperties(): array|LegacyReflectionFields
    {
        return $this->reflFields;
    }

    /**
     * Gets the ReflectionProperties of the mapped class.
     *
     * @return array<string, PropertyAccessor> An array of PropertyAccessor instances by name.
     */
    public function getPropertyAccessors(): array
    {
        return $this->propertyAccessors;
    }

    /**
     * Gets a ReflectionProperty for a specific field of the mapped class.
     *
     * @deprecated Use getPropertyAccessor() instead.
     */
    public function getReflectionProperty(string $name): ReflectionProperty|null
    {
        return $this->reflFields[$name];
    }

    public function getPropertyAccessor(string $name): PropertyAccessor|null
    {
        return $this->propertyAccessors[$name] ?? null;
    }

    /**
     * @deprecated Use getPropertyAccessor() instead.
     *
     * @throws BadMethodCallException If the class has a composite identifier.
     */
    public function getSingleIdReflectionProperty(): ReflectionProperty|null
    {
        if ($this->isIdentifierComposite) {
            throw new BadMethodCallException('Class ' . $this->name . ' has a composite identifier.');
        }

        return $this->reflFields[$this->identifier[0]];
    }

    /** @throws BadMethodCallException If the class has a composite identifier. */
    public function getSingleIdPropertyAccessor(): PropertyAccessor|null
    {
        if ($this->isIdentifierComposite) {
            throw new BadMethodCallException('Class ' . $this->name . ' has a composite identifier.');
        }

        return $this->propertyAccessors[$this->identifier[0]];
    }

    /**
     * Extracts the identifier values of an entity of this class.
     *
     * For composite identifiers, the identifier values are returned as an array
     * with the same order as the field order in {@link identifier}.
     *
     * @return array<string, mixed>
     */
    public function getIdentifierValues(object $entity): array
    {
        if ($this->isIdentifierComposite) {
            $id = [];

            foreach ($this->identifier as $idField) {
                $value = $this->propertyAccessors[$idField]->getValue($entity);

                if ($value !== null) {
                    $id[$idField] = $value;
                }
            }

            return $id;
        }

        $id    = $this->identifier[0];
        $value = $this->propertyAccessors[$id]->getValue($entity);

        if ($value === null) {
            return [];
        }

        return [$id => $value];
    }

    /**
     * Populates the entity identifier of an entity.
     *
     * @phpstan-param array<string, mixed> $id
     *
     * @todo Rename to assignIdentifier()
     */
    public function setIdentifierValues(object $entity, array $id): void
    {
        foreach ($id as $idField => $idValue) {
            $this->propertyAccessors[$idField]->setValue($entity, $idValue);
        }
    }

    /**
     * Sets the specified field to the specified value on the given entity.
     */
    public function setFieldValue(object $entity, string $field, mixed $value): void
    {
        $this->propertyAccessors[$field]->setValue($entity, $value);
    }

    /**
     * Gets the specified field's value off the given entity.
     */
    public function getFieldValue(object $entity, string $field): mixed
    {
        return $this->propertyAccessors[$field]->getValue($entity);
    }

    /**
     * Creates a string representation of this instance.
     *
     * @return string The string representation of this instance.
     *
     * @todo Construct meaningful string representation.
     */
    public function __toString(): string
    {
        return self::class . '@' . spl_object_id($this);
    }

    /**
     * Determines which fields get serialized.
     *
     * It is only serialized what is necessary for best unserialization performance.
     * That means any metadata properties that are not set or empty or simply have
     * their default value are NOT serialized.
     *
     * Parts that are also NOT serialized because they can not be properly unserialized:
     *      - reflClass (ReflectionClass)
     *      - reflFields (ReflectionProperty array)
     *
     * @return string[] The names of all the fields that should be serialized.
     */
    public function __sleep(): array
    {
        // This metadata is always serialized/cached.
        $serialized = [
            'associationMappings',
            'columnNames', //TODO: 3.0 Remove this. Can use fieldMappings[$fieldName]['columnName']
            'fieldMappings',
            'fieldNames',
            'embeddedClasses',
            'identifier',
            'identifierPositions',
            'isIdentifierComposite', // TODO: REMOVE
            'name',
            'namespace', // TODO: REMOVE
            'table',
            'rootEntityName',
            'idGenerator', //TODO: Does not really need to be serialized. Could be moved to runtime.
        ];

        // The rest of the metadata is only serialized if necessary.
        if ($this->changeTrackingPolicy !== self::CHANGETRACKING_DEFERRED_IMPLICIT) {
            $serialized[] = 'changeTrackingPolicy';
        }

        if ($this->customRepositoryClassName) {
            $serialized[] = 'customRepositoryClassName';
        }

        if ($this->inheritanceType !== self::INHERITANCE_TYPE_NONE) {
            $serialized[] = 'inheritanceType';
            $serialized[] = 'discriminatorColumn';
            $serialized[] = 'discriminatorValue';
            $serialized[] = 'discriminatorMap';
            $serialized[] = 'parentClasses';
            $serialized[] = 'subClasses';
        }

        if ($this->generatorType !== self::GENERATOR_TYPE_NONE) {
            $serialized[] = 'generatorType';
            if ($this->generatorType === self::GENERATOR_TYPE_SEQUENCE) {
                $serialized[] = 'sequenceGeneratorDefinition';
            }
        }

        if ($this->isMappedSuperclass) {
            $serialized[] = 'isMappedSuperclass';
        }

        if ($this->isEmbeddedClass) {
            $serialized[] = 'isEmbeddedClass';
        }

        if ($this->containsForeignIdentifier) {
            $serialized[] = 'containsForeignIdentifier';
        }

        if ($this->containsEnumIdentifier) {
            $serialized[] = 'containsEnumIdentifier';
        }

        if ($this->isVersioned) {
            $serialized[] = 'isVersioned';
            $serialized[] = 'versionField';
        }

        if ($this->lifecycleCallbacks) {
            $serialized[] = 'lifecycleCallbacks';
        }

        if ($this->entityListeners) {
            $serialized[] = 'entityListeners';
        }

        if ($this->isReadOnly) {
            $serialized[] = 'isReadOnly';
        }

        if ($this->customGeneratorDefinition) {
            $serialized[] = 'customGeneratorDefinition';
        }

        if ($this->cache) {
            $serialized[] = 'cache';
        }

        if ($this->requiresFetchAfterChange) {
            $serialized[] = 'requiresFetchAfterChange';
        }

        return $serialized;
    }

    /**
     * Creates a new instance of the mapped class, without invoking the constructor.
     */
    public function newInstance(): object
    {
        return $this->instantiator->instantiate($this->name);
    }

    /**
     * Restores some state that can not be serialized/unserialized.
     */
    public function wakeupReflection(ReflectionService $reflService): void
    {
        // Restore ReflectionClass and properties
        $this->reflClass = $reflService->getClass($this->name);
        /** @phpstan-ignore property.deprecated */
        $this->reflFields   = new LegacyReflectionFields($this, $reflService);
        $this->instantiator = $this->instantiator ?: new Instantiator();

        $parentAccessors = [];

        foreach ($this->embeddedClasses as $property => $embeddedClass) {
            if (isset($embeddedClass->declaredField)) {
                assert($embeddedClass->originalField !== null);
                $childAccessor = PropertyAccessorFactory::createPropertyAccessor(
                    $this->embeddedClasses[$embeddedClass->declaredField]->class,
                    $embeddedClass->originalField,
                );

                $parentAccessors[$property] = new EmbeddablePropertyAccessor(
                    $parentAccessors[$embeddedClass->declaredField],
                    $childAccessor,
                    $this->embeddedClasses[$embeddedClass->declaredField]->class,
                );

                continue;
            }

            $accessor = PropertyAccessorFactory::createPropertyAccessor(
                $embeddedClass->declared ?? $this->name,
                $property,
            );

            $parentAccessors[$property]         = $accessor;
            $this->propertyAccessors[$property] = $accessor;
        }

        foreach ($this->fieldMappings as $field => $mapping) {
            if (isset($mapping->declaredField) && isset($parentAccessors[$mapping->declaredField])) {
                assert($mapping->originalField !== null);
                assert($mapping->originalClass !== null);
                $accessor = PropertyAccessorFactory::createPropertyAccessor($mapping->originalClass, $mapping->originalField);

                if ($mapping->enumType !== null) {
                    $accessor = new EnumPropertyAccessor(
                        $accessor,
                        $mapping->enumType,
                    );
                }

                $this->propertyAccessors[$field] = new EmbeddablePropertyAccessor(
                    $parentAccessors[$mapping->declaredField],
                    $accessor,
                    $mapping->originalClass,
                );
                continue;
            }

            $this->propertyAccessors[$field] = isset($mapping->declared)
                ? PropertyAccessorFactory::createPropertyAccessor($mapping->declared, $field, $mapping->id === true)
                : PropertyAccessorFactory::createPropertyAccessor($this->name, $field, $mapping->id === true);

            if ($mapping->enumType !== null) {
                $this->propertyAccessors[$field] = new EnumPropertyAccessor(
                    $this->propertyAccessors[$field],
                    $mapping->enumType,
                );
            }
        }

        foreach ($this->associationMappings as $field => $mapping) {
            $this->propertyAccessors[$field] = isset($mapping->declared)
                ? PropertyAccessorFactory::createPropertyAccessor($mapping->declared, $field)
                : PropertyAccessorFactory::createPropertyAccessor($this->name, $field);
        }
    }

    /**
     * Initializes a new ClassMetadata instance that will hold the object-relational mapping
     * metadata of the class with the given name.
     *
     * @param ReflectionService $reflService The reflection service.
     */
    public function initializeReflection(ReflectionService $reflService): void
    {
        $this->reflClass = $reflService->getClass($this->name);
        $this->namespace = $reflService->getClassNamespace($this->name);

        if ($this->reflClass) {
            $this->name = $this->rootEntityName = $this->reflClass->name;
        }

        $this->table['name'] = $this->namingStrategy->classToTableName($this->name);
    }

    /**
     * Validates Identifier.
     *
     * @throws MappingException
     */
    public function validateIdentifier(): void
    {
        if ($this->isMappedSuperclass || $this->isEmbeddedClass) {
            return;
        }

        // Verify & complete identifier mapping
        if (! $this->identifier) {
            throw MappingException::identifierRequired($this->name);
        }

        if ($this->usesIdGenerator() && $this->isIdentifierComposite) {
            throw MappingException::compositeKeyAssignedIdGeneratorRequired($this->name);
        }
    }

    /**
     * Validates association targets actually exist.
     *
     * @throws MappingException
     */
    public function validateAssociations(): void
    {
        foreach ($this->associationMappings as $mapping) {
            if (
                ! class_exists($mapping->targetEntity)
                && ! interface_exists($mapping->targetEntity)
                && ! trait_exists($mapping->targetEntity)
            ) {
                throw MappingException::invalidTargetEntityClass($mapping->targetEntity, $this->name, $mapping->fieldName);
            }
        }
    }

    /**
     * Validates lifecycle callbacks.
     *
     * @throws MappingException
     */
    public function validateLifecycleCallbacks(ReflectionService $reflService): void
    {
        foreach ($this->lifecycleCallbacks as $callbacks) {
            foreach ($callbacks as $callbackFuncName) {
                if (! $reflService->hasPublicMethod($this->name, $callbackFuncName)) {
                    throw MappingException::lifecycleCallbackMethodNotFound($this->name, $callbackFuncName);
                }
            }
        }
    }

    /** @phpstan-param array{usage?: mixed, region?: mixed} $cache */
    public function enableCache(array $cache): void
    {
        if (! isset($cache['usage'])) {
            $cache['usage'] = self::CACHE_USAGE_READ_ONLY;
        }

        if (! isset($cache['region'])) {
            $cache['region'] = strtolower(str_replace('\\', '_', $this->rootEntityName));
        }

        $this->cache = $cache;
    }

    /** @phpstan-param array{usage?: int, region?: string} $cache */
    public function enableAssociationCache(string $fieldName, array $cache): void
    {
        $this->associationMappings[$fieldName]->cache = $this->getAssociationCacheDefaults($fieldName, $cache);
    }

    /**
     * @phpstan-param array{usage?: int, region?: string|null} $cache
     *
     * @return int[]|string[]
     * @phpstan-return array{usage: int, region: string|null}
     */
    public function getAssociationCacheDefaults(string $fieldName, array $cache): array
    {
        if (! isset($cache['usage'])) {
            $cache['usage'] = $this->cache['usage'] ?? self::CACHE_USAGE_READ_ONLY;
        }

        if (! isset($cache['region'])) {
            $cache['region'] = strtolower(str_replace('\\', '_', $this->rootEntityName)) . '__' . $fieldName;
        }

        return $cache;
    }

    /**
     * Sets the change tracking policy used by this class.
     */
    public function setChangeTrackingPolicy(int $policy): void
    {
        $this->changeTrackingPolicy = $policy;
    }

    /**
     * Whether the change tracking policy of this class is "deferred explicit".
     */
    public function isChangeTrackingDeferredExplicit(): bool
    {
        return $this->changeTrackingPolicy === self::CHANGETRACKING_DEFERRED_EXPLICIT;
    }

    /**
     * Whether the change tracking policy of this class is "deferred implicit".
     */
    public function isChangeTrackingDeferredImplicit(): bool
    {
        return $this->changeTrackingPolicy === self::CHANGETRACKING_DEFERRED_IMPLICIT;
    }

    /**
     * Checks whether a field is part of the identifier/primary key field(s).
     */
    public function isIdentifier(string $fieldName): bool
    {
        if (! $this->identifier) {
            return false;
        }

        if (! $this->isIdentifierComposite) {
            return $fieldName === $this->identifier[0];
        }

        return in_array($fieldName, $this->identifier, true);
    }

    public function isUniqueField(string $fieldName): bool
    {
        $mapping = $this->getFieldMapping($fieldName);

        return $mapping !== false && isset($mapping->unique) && $mapping->unique;
    }

    public function isNullable(string $fieldName): bool
    {
        $mapping = $this->getFieldMapping($fieldName);

        return $mapping !== false && isset($mapping->nullable) && $mapping->nullable;
    }

    public function isIndexed(string $fieldName): bool
    {
        $mapping = $this->getFieldMapping($fieldName);

        return isset($mapping->index) && $mapping->index;
    }

    /**
     * Gets a column name for a field name.
     * If the column name for the field cannot be found, the given field name
     * is returned.
     */
    public function getColumnName(string $fieldName): string
    {
        // @phpstan-ignore property.deprecated
        return $this->columnNames[$fieldName] ?? $fieldName;
    }

    /**
     * Gets the mapping of a (regular) field that holds some data but not a
     * reference to another object.
     *
     * @throws MappingException
     */
    public function getFieldMapping(string $fieldName): FieldMapping
    {
        if (! isset($this->fieldMappings[$fieldName])) {
            throw MappingException::mappingNotFound($this->name, $fieldName);
        }

        return $this->fieldMappings[$fieldName];
    }

    /**
     * Gets the mapping of an association.
     *
     * @see ClassMetadata::$associationMappings
     *
     * @param string $fieldName The field name that represents the association in
     *                          the object model.
     *
     * @throws MappingException
     */
    public function getAssociationMapping(string $fieldName): AssociationMapping
    {
        if (! isset($this->associationMappings[$fieldName])) {
            throw MappingException::mappingNotFound($this->name, $fieldName);
        }

        return $this->associationMappings[$fieldName];
    }

    /**
     * Gets all association mappings of the class.
     *
     * @phpstan-return array<string, AssociationMapping>
     */
    public function getAssociationMappings(): array
    {
        return $this->associationMappings;
    }

    /**
     * Gets the field name for a column name.
     * If no field name can be found the column name is returned.
     *
     * @return string The column alias.
     */
    public function getFieldName(string $columnName): string
    {
        return $this->fieldNames[$columnName] ?? $columnName;
    }

    /**
     * Checks whether given property has type
     */
    private function isTypedProperty(string $name): bool
    {
        return isset($this->reflClass)
               && $this->reflClass->hasProperty($name)
               && $this->reflClass->getProperty($name)->hasType();
    }

    /**
     * Validates & completes the given field mapping based on typed property.
     *
     * @param  array{fieldName: string, type?: string} $mapping The field mapping to validate & complete.
     *
     * @return array{fieldName: string, enumType?: class-string<BackedEnum>, type?: string} The updated mapping.
     */
    private function validateAndCompleteTypedFieldMapping(array $mapping): array
    {
        $field = $this->reflClass->getProperty($mapping['fieldName']);

        return $this->typedFieldMapper->validateAndComplete($mapping, $field);
    }

    /**
     * Validates & completes the basic mapping information based on typed property.
     *
     * @param array{type: self::ONE_TO_ONE|self::MANY_TO_ONE|self::ONE_TO_MANY|self::MANY_TO_MANY, fieldName: string, targetEntity?: class-string} $mapping The mapping.
     *
     * @return mixed[] The updated mapping.
     */
    private function validateAndCompleteTypedAssociationMapping(array $mapping): array
    {
        $type = $this->reflClass->getProperty($mapping['fieldName'])->getType();

        if ($type === null || ($mapping['type'] & self::TO_ONE) === 0) {
            return $mapping;
        }

        if (! isset($mapping['targetEntity']) && $type instanceof ReflectionNamedType) {
            $mapping['targetEntity'] = $type->getName();
        }

        return $mapping;
    }

    /**
     * Validates & completes the given field mapping.
     *
     * @phpstan-param array{
     *     fieldName?: string,
     *     columnName?: string,
     *     id?: bool,
     *     generated?: self::GENERATED_*,
     *     enumType?: class-string,
     * } $mapping The field mapping to validate & complete.
     *
     * @return FieldMapping The updated mapping.
     *
     * @throws MappingException
     */
    protected function validateAndCompleteFieldMapping(array $mapping): FieldMapping
    {
        // Check mandatory fields
        if (! isset($mapping['fieldName']) || ! $mapping['fieldName']) {
            throw MappingException::missingFieldName($this->name);
        }

        if ($this->isTypedProperty($mapping['fieldName'])) {
            $mapping = $this->validateAndCompleteTypedFieldMapping($mapping);
        }

        if (! isset($mapping['type'])) {
            // Default to string
            $mapping['type'] = 'string';
        }

        // Complete fieldName and columnName mapping
        if (! isset($mapping['columnName'])) {
            $mapping['columnName'] = $this->namingStrategy->propertyToColumnName($mapping['fieldName'], $this->name);
        }

        $mapping = FieldMapping::fromMappingArray($mapping);

        if ($mapping->columnName[0] === '`') {
            $mapping->columnName = trim($mapping->columnName, '`');
            $mapping->quoted     = true;
        }

        // @phpstan-ignore property.deprecated
        $this->columnNames[$mapping->fieldName] = $mapping->columnName;

        if (isset($this->fieldNames[$mapping->columnName]) || ($this->discriminatorColumn && $this->discriminatorColumn->name === $mapping->columnName)) {
            throw MappingException::duplicateColumnName($this->name, $mapping->columnName);
        }

        $this->fieldNames[$mapping->columnName] = $mapping->fieldName;

        // Complete id mapping
        if (isset($mapping->id) && $mapping->id === true) {
            if ($this->versionField === $mapping->fieldName) {
                throw MappingException::cannotVersionIdField($this->name, $mapping->fieldName);
            }

            if (! in_array($mapping->fieldName, $this->identifier, true)) {
                $this->registerIdentifierField($mapping->fieldName, $mapping->idPosition);
            }

            // Check for composite key
            if (! $this->isIdentifierComposite && count($this->identifier) > 1) {
                $this->isIdentifierComposite = true;
            }
        }

        if (isset($mapping->generated)) {
            if (! in_array($mapping->generated, [self::GENERATED_NEVER, self::GENERATED_INSERT, self::GENERATED_ALWAYS])) {
                throw MappingException::invalidGeneratedMode($mapping->generated);
            }

            if ($mapping->generated === self::GENERATED_NEVER) {
                unset($mapping->generated);
            }
        }

        if (isset($mapping->enumType)) {
            if (! enum_exists($mapping->enumType)) {
                throw MappingException::nonEnumTypeMapped($this->name, $mapping->fieldName, $mapping->enumType);
            }

            if (! empty($mapping->id)) {
                $this->containsEnumIdentifier = true;
            }

            if (
                defined('Doctrine\DBAL\Types\Types::ENUM')
                && $mapping->type === Types::ENUM
                && ! isset($mapping->options['values'])
            ) {
                $mapping->options['values'] = array_column($mapping->enumType::cases(), 'value');
            }
        }

        return $mapping;
    }

    /**
     * Validates & completes the basic mapping information that is common to all
     * association mappings (one-to-one, many-ot-one, one-to-many, many-to-many).
     *
     * @phpstan-param array<string, mixed> $mapping The mapping.
     *
     * @return ConcreteAssociationMapping
     *
     * @throws MappingException If something is wrong with the mapping.
     */
    protected function _validateAndCompleteAssociationMapping(array $mapping): AssociationMapping
    {
        if (array_key_exists('mappedBy', $mapping) && $mapping['mappedBy'] === null) {
            unset($mapping['mappedBy']);
        }

        if (array_key_exists('inversedBy', $mapping) && $mapping['inversedBy'] === null) {
            unset($mapping['inversedBy']);
        }

        if (array_key_exists('joinColumns', $mapping) && in_array($mapping['joinColumns'], [null, []], true)) {
            unset($mapping['joinColumns']);
        }

        $mapping['isOwningSide'] = true; // assume owning side until we hit mappedBy

        if (empty($mapping['indexBy'])) {
            unset($mapping['indexBy']);
        }

        // If targetEntity is unqualified, assume it is in the same namespace as
        // the sourceEntity.
        $mapping['sourceEntity'] = $this->name;

        if ($this->isTypedProperty($mapping['fieldName'])) {
            $mapping = $this->validateAndCompleteTypedAssociationMapping($mapping);
        }

        if (isset($mapping['targetEntity'])) {
            $mapping['targetEntity'] = $this->fullyQualifiedClassName($mapping['targetEntity']);
            $mapping['targetEntity'] = ltrim($mapping['targetEntity'], '\\');
        }

        if (($mapping['type'] & self::MANY_TO_ONE) > 0 && isset($mapping['orphanRemoval']) && $mapping['orphanRemoval']) {
            throw MappingException::illegalOrphanRemoval($this->name, $mapping['fieldName']);
        }

        // Complete id mapping
        if (isset($mapping['id']) && $mapping['id'] === true) {
            if (isset($mapping['orphanRemoval']) && $mapping['orphanRemoval']) {
                throw MappingException::illegalOrphanRemovalOnIdentifierAssociation($this->name, $mapping['fieldName']);
            }

            if (! in_array($mapping['fieldName'], $this->identifier, true)) {
                if (isset($mapping['joinColumns']) && count($mapping['joinColumns']) >= 2) {
                    throw MappingException::cannotMapCompositePrimaryKeyEntitiesAsForeignId(
                        $mapping['targetEntity'],
                        $this->name,
                        $mapping['fieldName'],
                    );
                }

                assert(is_string($mapping['fieldName']));
                $this->registerIdentifierField($mapping['fieldName'], $mapping['idPosition'] ?? null);
                $this->containsForeignIdentifier = true;
            }

            // Check for composite key
            if (! $this->isIdentifierComposite && count($this->identifier) > 1) {
                $this->isIdentifierComposite = true;
            }

            if ($this->cache && ! isset($mapping['cache'])) {
                throw NonCacheableEntityAssociation::fromEntityAndField(
                    $this->name,
                    $mapping['fieldName'],
                );
            }
        }

        unset($mapping['idPosition']);

        // Mandatory attributes for both sides
        // Mandatory: fieldName, targetEntity
        if (! isset($mapping['fieldName']) || ! $mapping['fieldName']) {
            throw MappingException::missingFieldName($this->name);
        }

        if (! isset($mapping['targetEntity'])) {
            throw MappingException::missingTargetEntity($mapping['fieldName']);
        }

        // Mandatory and optional attributes for either side
        if (! isset($mapping['mappedBy'])) {
            if (isset($mapping['joinTable'])) {
                if (isset($mapping['joinTable']['name']) && $mapping['joinTable']['name'][0] === '`') {
                    $mapping['joinTable']['name']   = trim($mapping['joinTable']['name'], '`');
                    $mapping['joinTable']['quoted'] = true;
                }
            }
        } else {
            $mapping['isOwningSide'] = false;
        }

        if (isset($mapping['id']) && $mapping['id'] === true && $mapping['type'] & self::TO_MANY) {
            throw MappingException::illegalToManyIdentifierAssociation($this->name, $mapping['fieldName']);
        }

        // Fetch mode. Default fetch mode to LAZY, if not set.
        if (! isset($mapping['fetch'])) {
            $mapping['fetch'] = self::FETCH_LAZY;
        }

        // Cascades
        $cascades = isset($mapping['cascade']) ? array_map('strtolower', $mapping['cascade']) : [];

        $allCascades = ['remove', 'persist', 'refresh', 'detach'];
        if (in_array('all', $cascades, true)) {
            $cascades = $allCascades;
        } elseif (count($cascades) !== count(array_intersect($cascades, $allCascades))) {
            throw MappingException::invalidCascadeOption(
                array_diff($cascades, $allCascades),
                $this->name,
                $mapping['fieldName'],
            );
        }

        $mapping['cascade'] = $cascades;

        switch ($mapping['type']) {
            case self::ONE_TO_ONE:
                if (isset($mapping['joinColumns']) && $mapping['joinColumns'] && ! $mapping['isOwningSide']) {
                    throw MappingException::joinColumnNotAllowedOnOneToOneInverseSide(
                        $this->name,
                        $mapping['fieldName'],
                    );
                }

                return $mapping['isOwningSide'] ?
                    OneToOneOwningSideMapping::fromMappingArrayAndName(
                        $mapping,
                        $this->namingStrategy,
                        $this->name,
                        $this->table ?? null,
                        $this->isInheritanceTypeSingleTable(),
                    ) :
                    OneToOneInverseSideMapping::fromMappingArrayAndName($mapping, $this->name);

            case self::MANY_TO_ONE:
                return ManyToOneAssociationMapping::fromMappingArrayAndName(
                    $mapping,
                    $this->namingStrategy,
                    $this->name,
                    $this->table ?? null,
                    $this->isInheritanceTypeSingleTable(),
                );

            case self::ONE_TO_MANY:
                return OneToManyAssociationMapping::fromMappingArrayAndName($mapping, $this->name);

            case self::MANY_TO_MANY:
                if (isset($mapping['joinColumns'])) {
                    unset($mapping['joinColumns']);
                }

                return $mapping['isOwningSide'] ?
                    ManyToManyOwningSideMapping::fromMappingArrayAndNamingStrategy($mapping, $this->namingStrategy) :
                    ManyToManyInverseSideMapping::fromMappingArray($mapping);

            default:
                throw MappingException::invalidAssociationType(
                    $this->name,
                    $mapping['fieldName'],
                    $mapping['type'],
                );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifierFieldNames(): array
    {
        return $this->identifier;
    }

    /**
     * Gets the name of the single id field. Note that this only works on
     * entity classes that have a single-field pk.
     *
     * @throws MappingException If the class doesn't have an identifier or it has a composite primary key.
     */
    public function getSingleIdentifierFieldName(): string
    {
        if ($this->isIdentifierComposite) {
            throw MappingException::singleIdNotAllowedOnCompositePrimaryKey($this->name);
        }

        if (! isset($this->identifier[0])) {
            throw MappingException::noIdDefined($this->name);
        }

        return $this->identifier[0];
    }

    /**
     * Gets the column name of the single id column. Note that this only works on
     * entity classes that have a single-field pk.
     *
     * @throws MappingException If the class doesn't have an identifier or it has a composite primary key.
     */
    public function getSingleIdentifierColumnName(): string
    {
        return $this->getColumnName($this->getSingleIdentifierFieldName());
    }

    /**
     * INTERNAL:
     * Sets the mapped identifier/primary key fields of this class.
     * Mainly used by the ClassMetadataFactory to assign inherited identifiers.
     *
     * @phpstan-param list<mixed> $identifier
     */
    public function setIdentifier(array $identifier/*, array $positions = []*/): void
    {
        $positions = func_num_args() > 1 ? func_get_arg(1) : [];

        $this->identifier          = [];
        $this->identifierPositions = [];

        foreach ($identifier as $fieldName) {
            $this->registerIdentifierField($fieldName, $positions[$fieldName] ?? null);
        }

        $this->isIdentifierComposite = (count($this->identifier) > 1);
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier(): array
    {
        return $this->identifier;
    }

    public function hasField(string $fieldName): bool
    {
        return isset($this->fieldMappings[$fieldName]) || isset($this->embeddedClasses[$fieldName]);
    }

    /**
     * Gets an array containing all the column names.
     *
     * @phpstan-param list<string>|null $fieldNames
     *
     * @return mixed[]
     * @phpstan-return list<string>
     */
    public function getColumnNames(array|null $fieldNames = null): array
    {
        if ($fieldNames === null) {
            return array_keys($this->fieldNames);
        }

        return array_values(array_map($this->getColumnName(...), $fieldNames));
    }

    /**
     * Returns an array with all the identifier column names.
     *
     * @phpstan-return list<string>
     */
    public function getIdentifierColumnNames(): array
    {
        $columnNames = [];

        foreach ($this->identifier as $idProperty) {
            if (isset($this->fieldMappings[$idProperty])) {
                $columnNames[] = $this->fieldMappings[$idProperty]->columnName;

                continue;
            }

            // Association defined as Id field
            assert($this->associationMappings[$idProperty]->isToOneOwningSide());
            $joinColumns      = $this->associationMappings[$idProperty]->joinColumns;
            $assocColumnNames = array_map(static fn (JoinColumnMapping $joinColumn): string => $joinColumn->name, $joinColumns);

            $columnNames = array_merge($columnNames, $assocColumnNames);
        }

        return $columnNames;
    }

    /**
     * Sets the type of Id generator to use for the mapped class.
     *
     * @phpstan-param self::GENERATOR_TYPE_* $generatorType
     */
    public function setIdGeneratorType(int $generatorType): void
    {
        $this->generatorType = $generatorType;
    }

    /**
     * Checks whether the mapped class uses an Id generator.
     */
    public function usesIdGenerator(): bool
    {
        return $this->generatorType !== self::GENERATOR_TYPE_NONE;
    }

    public function isInheritanceTypeNone(): bool
    {
        return $this->inheritanceType === self::INHERITANCE_TYPE_NONE;
    }

    /**
     * Checks whether the mapped class uses the JOINED inheritance mapping strategy.
     *
     * @return bool TRUE if the class participates in a JOINED inheritance mapping,
     * FALSE otherwise.
     */
    public function isInheritanceTypeJoined(): bool
    {
        return $this->inheritanceType === self::INHERITANCE_TYPE_JOINED;
    }

    /**
     * Checks whether the mapped class uses the SINGLE_TABLE inheritance mapping strategy.
     *
     * @return bool TRUE if the class participates in a SINGLE_TABLE inheritance mapping,
     * FALSE otherwise.
     */
    public function isInheritanceTypeSingleTable(): bool
    {
        return $this->inheritanceType === self::INHERITANCE_TYPE_SINGLE_TABLE;
    }

    /**
     * Checks whether the class uses an identity column for the Id generation.
     */
    public function isIdGeneratorIdentity(): bool
    {
        return $this->generatorType === self::GENERATOR_TYPE_IDENTITY;
    }

    /**
     * Checks whether the class uses a sequence for id generation.
     *
     * @phpstan-assert-if-true !null $this->sequenceGeneratorDefinition
     */
    public function isIdGeneratorSequence(): bool
    {
        return $this->generatorType === self::GENERATOR_TYPE_SEQUENCE;
    }

    /**
     * Checks whether the class has a natural identifier/pk (which means it does
     * not use any Id generator.
     */
    public function isIdentifierNatural(): bool
    {
        return $this->generatorType === self::GENERATOR_TYPE_NONE;
    }

    /**
     * Gets the type of a field.
     *
     * @todo 3.0 Remove this. PersisterHelper should fix it somehow
     */
    public function getTypeOfField(string $fieldName): string|null
    {
        return isset($this->fieldMappings[$fieldName])
            ? $this->fieldMappings[$fieldName]->type
            : null;
    }

    /**
     * Gets the name of the primary table.
     */
    public function getTableName(): string
    {
        return $this->table['name'];
    }

    /**
     * Gets primary table's schema name.
     */
    public function getSchemaName(): string|null
    {
        return $this->table['schema'] ?? null;
    }

    /**
     * Gets the table name to use for temporary identifier tables of this class.
     */
    public function getTemporaryIdTableName(): string
    {
        // replace dots with underscores because PostgreSQL creates temporary tables in a special schema
        return str_replace('.', '_', $this->getTableName() . '_id_tmp');
    }

    /**
     * Sets the mapped subclasses of this class.
     *
     * @phpstan-param list<string> $subclasses The names of all mapped subclasses.
     */
    public function setSubclasses(array $subclasses): void
    {
        foreach ($subclasses as $subclass) {
            $this->subClasses[] = $this->fullyQualifiedClassName($subclass);
        }
    }

    /**
     * Sets the parent class names. Only <em>entity</em> classes may be given.
     *
     * Assumes that the class names in the passed array are in the order:
     * directParent -> directParentParent -> directParentParentParent ... -> root.
     *
     * @phpstan-param list<class-string> $classNames
     */
    public function setParentClasses(array $classNames): void
    {
        $this->parentClasses = $classNames;

        if (count($classNames) > 0) {
            $this->rootEntityName = array_pop($classNames);
        }
    }

    /**
     * Sets the inheritance type used by the class and its subclasses.
     *
     * @phpstan-param self::INHERITANCE_TYPE_* $type
     *
     * @throws MappingException
     */
    public function setInheritanceType(int $type): void
    {
        if (! $this->isInheritanceType($type)) {
            throw MappingException::invalidInheritanceType($this->name, $type);
        }

        $this->inheritanceType = $type;
    }

    /**
     * Sets the association to override association mapping of property for an entity relationship.
     *
     * @phpstan-param array{joinColumns?: array, inversedBy?: ?string, joinTable?: array, fetch?: ?string, cascade?: string[]} $overrideMapping
     *
     * @throws MappingException
     */
    public function setAssociationOverride(string $fieldName, array $overrideMapping): void
    {
        if (! isset($this->associationMappings[$fieldName])) {
            throw MappingException::invalidOverrideFieldName($this->name, $fieldName);
        }

        $mapping = $this->associationMappings[$fieldName]->toArray();

        if (isset($mapping['inherited'])) {
            throw MappingException::illegalOverrideOfInheritedProperty(
                $this->name,
                $fieldName,
                $mapping['inherited'],
            );
        }

        if (isset($overrideMapping['joinColumns'])) {
            $mapping['joinColumns'] = $overrideMapping['joinColumns'];
        }

        if (isset($overrideMapping['inversedBy'])) {
            $mapping['inversedBy'] = $overrideMapping['inversedBy'];
        }

        if (isset($overrideMapping['joinTable'])) {
            $mapping['joinTable'] = $overrideMapping['joinTable'];
        }

        if (isset($overrideMapping['fetch'])) {
            $mapping['fetch'] = $overrideMapping['fetch'];
        }

        if (isset($overrideMapping['cascade'])) {
            $mapping['cascade'] = $overrideMapping['cascade'];
        }

        switch ($mapping['type']) {
            case self::ONE_TO_ONE:
            case self::MANY_TO_ONE:
                $mapping['joinColumnFieldNames']     = [];
                $mapping['sourceToTargetKeyColumns'] = [];
                break;
            case self::MANY_TO_MANY:
                $mapping['relationToSourceKeyColumns'] = [];
                $mapping['relationToTargetKeyColumns'] = [];
                break;
        }

        $this->associationMappings[$fieldName] = $this->_validateAndCompleteAssociationMapping($mapping);
    }

    /**
     * Sets the override for a mapped field.
     *
     * @phpstan-param array<string, mixed> $overrideMapping
     *
     * @throws MappingException
     */
    public function setAttributeOverride(string $fieldName, array $overrideMapping): void
    {
        if (! isset($this->fieldMappings[$fieldName])) {
            throw MappingException::invalidOverrideFieldName($this->name, $fieldName);
        }

        $mapping = $this->fieldMappings[$fieldName];

        if (isset($mapping->inherited)) {
            throw MappingException::illegalOverrideOfInheritedProperty($this->name, $fieldName, $mapping->inherited);
        }

        if (isset($mapping->id)) {
            $overrideMapping['id'] = $mapping->id;
        }

        if (isset($mapping->declared)) {
            $overrideMapping['declared'] = $mapping->declared;
        }

        if (! isset($overrideMapping['type'])) {
            $overrideMapping['type'] = $mapping->type;
        }

        if (! isset($overrideMapping['fieldName'])) {
            $overrideMapping['fieldName'] = $mapping->fieldName;
        }

        if ($overrideMapping['type'] !== $mapping->type) {
            throw MappingException::invalidOverrideFieldType($this->name, $fieldName);
        }

        unset($this->fieldMappings[$fieldName]);
        unset($this->fieldNames[$mapping->columnName]);
        // @phpstan-ignore property.deprecated
        unset($this->columnNames[$mapping->fieldName]);

        $overrideMapping = $this->validateAndCompleteFieldMapping($overrideMapping);

        $this->fieldMappings[$fieldName] = $overrideMapping;
    }

    /**
     * Checks whether a mapped field is inherited from an entity superclass.
     */
    public function isInheritedField(string $fieldName): bool
    {
        return isset($this->fieldMappings[$fieldName]->inherited);
    }

    /**
     * Checks if this entity is the root in any entity-inheritance-hierarchy.
     */
    public function isRootEntity(): bool
    {
        return $this->name === $this->rootEntityName;
    }

    /**
     * Checks whether a mapped association field is inherited from a superclass.
     */
    public function isInheritedAssociation(string $fieldName): bool
    {
        return isset($this->associationMappings[$fieldName]->inherited);
    }

    public function isInheritedEmbeddedClass(string $fieldName): bool
    {
        return isset($this->embeddedClasses[$fieldName]->inherited);
    }

    /**
     * Sets the name of the primary table the class is mapped to.
     *
     * @deprecated Use {@link setPrimaryTable}.
     */
    public function setTableName(string $tableName): void
    {
        $this->table['name'] = $tableName;
    }

    /**
     * Sets the primary table definition. The provided array supports the
     * following structure:
     *
     * name => <tableName> (optional, defaults to class name)
     * indexes => array of indexes (optional)
     * uniqueConstraints => array of constraints (optional)
     *
     * If a key is omitted, the current value is kept.
     *
     * @phpstan-param array<string, mixed> $table The table description.
     */
    public function setPrimaryTable(array $table): void
    {
        if (isset($table['name'])) {
            // Split schema and table name from a table name like "myschema.mytable"
            if (str_contains($table['name'], '.')) {
                [$this->table['schema'], $table['name']] = explode('.', $table['name'], 2);
            }

            if ($table['name'][0] === '`') {
                $table['name']         = trim($table['name'], '`');
                $this->table['quoted'] = true;
            }

            $this->table['name'] = $table['name'];
        }

        if (isset($table['quoted'])) {
            $this->table['quoted'] = $table['quoted'];
        }

        if (isset($table['schema'])) {
            $this->table['schema'] = $table['schema'];
        }

        if (isset($table['indexes'])) {
            $this->table['indexes'] = $table['indexes'];
        }

        if (isset($table['uniqueConstraints'])) {
            $this->table['uniqueConstraints'] = $table['uniqueConstraints'];
        }

        if (isset($table['options'])) {
            $this->table['options'] = $table['options'];
        }
    }

    /**
     * Checks whether the given type identifies an inheritance type.
     */
    private function isInheritanceType(int $type): bool
    {
        return $type === self::INHERITANCE_TYPE_NONE ||
                $type === self::INHERITANCE_TYPE_SINGLE_TABLE ||
                $type === self::INHERITANCE_TYPE_JOINED;
    }

    /**
     * Adds a mapped field to the class.
     *
     * @phpstan-param array<string, mixed> $mapping The field mapping.
     *
     * @throws MappingException
     */
    public function mapField(array $mapping): void
    {
        $mapping = $this->validateAndCompleteFieldMapping($mapping);
        $this->assertFieldNotMapped($mapping->fieldName);

        if (isset($mapping->generated)) {
            $this->requiresFetchAfterChange = true;
        }

        $this->fieldMappings[$mapping->fieldName] = $mapping;
    }

    /**
     * INTERNAL:
     * Adds an association mapping without completing/validating it.
     * This is mainly used to add inherited association mappings to derived classes.
     *
     * @param ConcreteAssociationMapping $mapping
     *
     * @throws MappingException
     */
    public function addInheritedAssociationMapping(AssociationMapping $mapping/*, $owningClassName = null*/): void
    {
        if (isset($this->associationMappings[$mapping->fieldName])) {
            throw MappingException::duplicateAssociationMapping($this->name, $mapping->fieldName);
        }

        $this->associationMappings[$mapping->fieldName] = $mapping;
    }

    /**
     * INTERNAL:
     * Adds a field mapping without completing/validating it.
     * This is mainly used to add inherited field mappings to derived classes.
     */
    public function addInheritedFieldMapping(FieldMapping $fieldMapping): void
    {
        $this->fieldMappings[$fieldMapping->fieldName] = $fieldMapping;
        // @phpstan-ignore property.deprecated
        $this->columnNames[$fieldMapping->fieldName] = $fieldMapping->columnName;
        $this->fieldNames[$fieldMapping->columnName] = $fieldMapping->fieldName;

        if (isset($fieldMapping->generated)) {
            $this->requiresFetchAfterChange = true;
        }
    }

    /**
     * Adds a one-to-one mapping.
     *
     * @param array<string, mixed> $mapping The mapping.
     */
    public function mapOneToOne(array $mapping): void
    {
        $mapping['type'] = self::ONE_TO_ONE;

        $mapping = $this->_validateAndCompleteAssociationMapping($mapping);

        $this->_storeAssociationMapping($mapping);
    }

    /**
     * Adds a one-to-many mapping.
     *
     * @phpstan-param array<string, mixed> $mapping The mapping.
     */
    public function mapOneToMany(array $mapping): void
    {
        $mapping['type'] = self::ONE_TO_MANY;

        if (isset($mapping['orderBy'])) {
            $mapping['orderBy'] = $this->normalizeOrderBy($mapping['orderBy'], $mapping['fieldName']);
        }

        $mapping = $this->_validateAndCompleteAssociationMapping($mapping);

        $this->_storeAssociationMapping($mapping);
    }

    /**
     * Adds a many-to-one mapping.
     *
     * @phpstan-param array<string, mixed> $mapping The mapping.
     */
    public function mapManyToOne(array $mapping): void
    {
        $mapping['type'] = self::MANY_TO_ONE;

        $mapping = $this->_validateAndCompleteAssociationMapping($mapping);

        $this->_storeAssociationMapping($mapping);
    }

    /**
     * Adds a many-to-many mapping.
     *
     * @phpstan-param array<string, mixed> $mapping The mapping.
     */
    public function mapManyToMany(array $mapping): void
    {
        $mapping['type'] = self::MANY_TO_MANY;

        if (isset($mapping['orderBy'])) {
            $mapping['orderBy'] = $this->normalizeOrderBy($mapping['orderBy'], $mapping['fieldName']);
        }

        $mapping = $this->_validateAndCompleteAssociationMapping($mapping);

        $this->_storeAssociationMapping($mapping);
    }

    /**
     * Stores the association mapping.
     *
     * @param ConcreteAssociationMapping $assocMapping
     *
     * @throws MappingException
     */
    protected function _storeAssociationMapping(AssociationMapping $assocMapping): void
    {
        $sourceFieldName = $assocMapping->fieldName;

        $this->assertFieldNotMapped($sourceFieldName);

        $this->associationMappings[$sourceFieldName] = $assocMapping;
    }

    /**
     * Registers a custom repository class for the entity class.
     *
     * @param string|null $repositoryClassName The class name of the custom mapper.
     * @phpstan-param class-string<EntityRepository>|null $repositoryClassName
     */
    public function setCustomRepositoryClass(string|null $repositoryClassName): void
    {
        if ($repositoryClassName === null) {
            $this->customRepositoryClassName = null;

            return;
        }

        $this->customRepositoryClassName = $this->fullyQualifiedClassName($repositoryClassName);
    }

    /**
     * Dispatches the lifecycle event of the given entity to the registered
     * lifecycle callbacks and lifecycle listeners.
     *
     * @deprecated Deprecated since version 2.4 in favor of \Doctrine\ORM\Event\ListenersInvoker
     *
     * @param string $lifecycleEvent The lifecycle event.
     */
    public function invokeLifecycleCallbacks(string $lifecycleEvent, object $entity): void
    {
        foreach ($this->lifecycleCallbacks[$lifecycleEvent] as $callback) {
            $entity->$callback();
        }
    }

    /**
     * Whether the class has any attached lifecycle listeners or callbacks for a lifecycle event.
     */
    public function hasLifecycleCallbacks(string $lifecycleEvent): bool
    {
        return isset($this->lifecycleCallbacks[$lifecycleEvent]);
    }

    /**
     * Gets the registered lifecycle callbacks for an event.
     *
     * @return string[]
     * @phpstan-return list<string>
     */
    public function getLifecycleCallbacks(string $event): array
    {
        return $this->lifecycleCallbacks[$event] ?? [];
    }

    /**
     * Adds a lifecycle callback for entities of this class.
     */
    public function addLifecycleCallback(string $callback, string $event): void
    {
        if ($this->isEmbeddedClass) {
            throw MappingException::illegalLifecycleCallbackOnEmbeddedClass($callback, $this->name);
        }

        if (isset($this->lifecycleCallbacks[$event]) && in_array($callback, $this->lifecycleCallbacks[$event], true)) {
            return;
        }

        $this->lifecycleCallbacks[$event][] = $callback;
    }

    /**
     * Sets the lifecycle callbacks for entities of this class.
     * Any previously registered callbacks are overwritten.
     *
     * @phpstan-param array<string, list<string>> $callbacks
     */
    public function setLifecycleCallbacks(array $callbacks): void
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
     * @throws MappingException
     */
    public function addEntityListener(string $eventName, string $class, string $method): void
    {
        $class = $this->fullyQualifiedClassName($class);

        $listener = [
            'class'  => $class,
            'method' => $method,
        ];

        if (! class_exists($class)) {
            throw MappingException::entityListenerClassNotFound($class, $this->name);
        }

        if (! method_exists($class, $method)) {
            throw MappingException::entityListenerMethodNotFound($class, $method, $this->name);
        }

        if (isset($this->entityListeners[$eventName]) && in_array($listener, $this->entityListeners[$eventName], true)) {
            throw MappingException::duplicateEntityListener($class, $method, $this->name);
        }

        $this->entityListeners[$eventName][] = $listener;
    }

    /**
     * Sets the discriminator column definition.
     *
     * @see getDiscriminatorColumn()
     *
     * @param DiscriminatorColumnMapping|mixed[]|null $columnDef
     * @phpstan-param DiscriminatorColumnMapping|array{
     *     name: string|null,
     *     fieldName?: string|null,
     *     type?: string|null,
     *     length?: int|null,
     *     columnDefinition?: string|null,
     *     enumType?: class-string<BackedEnum>|null,
     *     options?: array<string, mixed>|null
     * }|null $columnDef
     *
     * @throws MappingException
     */
    public function setDiscriminatorColumn(DiscriminatorColumnMapping|array|null $columnDef): void
    {
        if ($columnDef instanceof DiscriminatorColumnMapping) {
            $this->discriminatorColumn = $columnDef;

            return;
        }

        if ($columnDef !== null) {
            if (! isset($columnDef['name'])) {
                throw MappingException::nameIsMandatoryForDiscriminatorColumns($this->name);
            }

            if (isset($this->fieldNames[$columnDef['name']])) {
                throw MappingException::duplicateColumnName($this->name, $columnDef['name']);
            }

            $columnDef['fieldName'] ??= $columnDef['name'];
            $columnDef['type']      ??= 'string';
            $columnDef['options']   ??= [];

            if (in_array($columnDef['type'], ['boolean', 'array', 'object', 'datetime', 'time', 'date'], true)) {
                throw MappingException::invalidDiscriminatorColumnType($this->name, $columnDef['type']);
            }

            if (isset($columnDef['enumType'])) {
                if (! enum_exists($columnDef['enumType'])) {
                    throw MappingException::nonEnumTypeMapped($this->name, $columnDef['fieldName'], $columnDef['enumType']);
                }

                if (
                    defined('Doctrine\DBAL\Types\Types::ENUM')
                    && $columnDef['type'] === Types::ENUM
                    && ! isset($columnDef['options']['values'])
                ) {
                    $columnDef['options']['values'] = array_column($columnDef['enumType']::cases(), 'value');
                }
            }

            $this->discriminatorColumn = DiscriminatorColumnMapping::fromMappingArray($columnDef);
        }
    }

    final public function getDiscriminatorColumn(): DiscriminatorColumnMapping
    {
        if ($this->discriminatorColumn === null) {
            throw new LogicException('The discriminator column was not set.');
        }

        return $this->discriminatorColumn;
    }

    /**
     * Sets the discriminator values used by this class.
     * Used for JOINED and SINGLE_TABLE inheritance mapping strategies.
     *
     * @param array<int|string, string> $map
     *
     * @throws MappingException
     */
    public function setDiscriminatorMap(array $map): void
    {
        if (count(array_flip($map)) !== count($map)) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/issues/3519',
                <<<'DEPRECATION'
                Mapping a class to multiple discriminator values is deprecated,
                and the discriminator mapping of %s contains duplicate values
                for the following discriminator values: %s.
                DEPRECATION,
                $this->name,
                implode(', ', array_keys(array_filter(array_count_values($map), static function (int $value): bool {
                    return $value > 1;
                }))),
            );
        }

        $values = $this->discriminatorColumn->options['values'] ?? null;

        if ($values !== null) {
            $diff = array_diff(array_keys($map), $values);

            if ($diff !== []) {
                throw MappingException::invalidEntriesInDiscriminatorMap(array_values($diff), $this->name, $this->discriminatorColumn->enumType);
            }
        }

        foreach ($map as $value => $className) {
            $this->addDiscriminatorMapClass($value, $className);
        }
    }

    /**
     * Adds one entry of the discriminator map with a new class and corresponding name.
     *
     * @throws MappingException
     */
    public function addDiscriminatorMapClass(int|string $name, string $className): void
    {
        $className = $this->fullyQualifiedClassName($className);
        $className = ltrim($className, '\\');

        $this->discriminatorMap[$name] = $className;

        if ($this->name === $className) {
            $this->discriminatorValue = $name;

            return;
        }

        if (! (class_exists($className) || interface_exists($className))) {
            throw MappingException::invalidClassInDiscriminatorMap($className, $this->name);
        }

        $this->addSubClass($className);
    }

    /** @param array<class-string> $classes */
    public function addSubClasses(array $classes): void
    {
        foreach ($classes as $className) {
            $this->addSubClass($className);
        }
    }

    public function addSubClass(string $className): void
    {
        // By ignoring classes that are not subclasses of the current class, we simplify inheriting
        // the subclass list from a parent class at the beginning of \Doctrine\ORM\Mapping\ClassMetadataFactory::doLoadMetadata.

        if (is_subclass_of($className, $this->name) && ! in_array($className, $this->subClasses, true)) {
            $this->subClasses[] = $className;
        }
    }

    public function hasAssociation(string $fieldName): bool
    {
        return isset($this->associationMappings[$fieldName]);
    }

    public function isSingleValuedAssociation(string $fieldName): bool
    {
        return isset($this->associationMappings[$fieldName])
            && ($this->associationMappings[$fieldName]->isToOne());
    }

    public function isCollectionValuedAssociation(string $fieldName): bool
    {
        return isset($this->associationMappings[$fieldName])
            && ! $this->associationMappings[$fieldName]->isToOne();
    }

    /**
     * Is this an association that only has a single join column?
     */
    public function isAssociationWithSingleJoinColumn(string $fieldName): bool
    {
        return isset($this->associationMappings[$fieldName])
            && isset($this->associationMappings[$fieldName]->joinColumns[0])
            && ! isset($this->associationMappings[$fieldName]->joinColumns[1]);
    }

    /**
     * Returns the single association join column (if any).
     *
     * @throws MappingException
     */
    public function getSingleAssociationJoinColumnName(string $fieldName): string
    {
        if (! $this->isAssociationWithSingleJoinColumn($fieldName)) {
            throw MappingException::noSingleAssociationJoinColumnFound($this->name, $fieldName);
        }

        $assoc = $this->associationMappings[$fieldName];

        assert($assoc->isToOneOwningSide());

        return $assoc->joinColumns[0]->name;
    }

    /**
     * Returns the single association referenced join column name (if any).
     *
     * @throws MappingException
     */
    public function getSingleAssociationReferencedJoinColumnName(string $fieldName): string
    {
        if (! $this->isAssociationWithSingleJoinColumn($fieldName)) {
            throw MappingException::noSingleAssociationJoinColumnFound($this->name, $fieldName);
        }

        $assoc = $this->associationMappings[$fieldName];

        assert($assoc->isToOneOwningSide());

        return $assoc->joinColumns[0]->referencedColumnName;
    }

    /**
     * Used to retrieve a fieldname for either field or association from a given column.
     *
     * This method is used in foreign-key as primary-key contexts.
     *
     * @throws MappingException
     */
    public function getFieldForColumn(string $columnName): string
    {
        if (isset($this->fieldNames[$columnName])) {
            return $this->fieldNames[$columnName];
        }

        foreach ($this->associationMappings as $assocName => $mapping) {
            if (
                $this->isAssociationWithSingleJoinColumn($assocName) &&
                assert($this->associationMappings[$assocName]->isToOneOwningSide()) &&
                $this->associationMappings[$assocName]->joinColumns[0]->name === $columnName
            ) {
                return $assocName;
            }
        }

        throw MappingException::noFieldNameFoundForColumn($this->name, $columnName);
    }

    /**
     * Sets the ID generator used to generate IDs for instances of this class.
     */
    public function setIdGenerator(AbstractIdGenerator $generator): void
    {
        $this->idGenerator = $generator;
    }

    /**
     * Sets definition.
     *
     * @phpstan-param array<string, string|null> $definition
     */
    public function setCustomGeneratorDefinition(array $definition): void
    {
        $this->customGeneratorDefinition = $definition;
    }

    /**
     * Sets the definition of the sequence ID generator for this class.
     *
     * The definition must have the following structure:
     * <code>
     * array(
     *     'sequenceName'   => 'name',
     *     'allocationSize' => 20,
     *     'initialValue'   => 1
     *     'quoted'         => 1
     * )
     * </code>
     *
     * @phpstan-param array{sequenceName?: string, allocationSize?: int|string, initialValue?: int|string, quoted?: mixed} $definition
     *
     * @throws MappingException
     */
    public function setSequenceGeneratorDefinition(array $definition): void
    {
        if (! isset($definition['sequenceName']) || trim($definition['sequenceName']) === '') {
            throw MappingException::missingSequenceName($this->name);
        }

        if ($definition['sequenceName'][0] === '`') {
            $definition['sequenceName'] = trim($definition['sequenceName'], '`');
            $definition['quoted']       = true;
        }

        if (! isset($definition['allocationSize']) || trim((string) $definition['allocationSize']) === '') {
            $definition['allocationSize'] = '1';
        }

        if (! isset($definition['initialValue']) || trim((string) $definition['initialValue']) === '') {
            $definition['initialValue'] = '1';
        }

        $definition['allocationSize'] = (string) $definition['allocationSize'];
        $definition['initialValue']   = (string) $definition['initialValue'];

        $this->sequenceGeneratorDefinition = $definition;
    }

    /**
     * Sets the version field mapping used for versioning. Sets the default
     * value to use depending on the column type.
     *
     * @phpstan-param array<string, mixed> $mapping The version field mapping array.
     *
     * @throws MappingException
     */
    public function setVersionMapping(array &$mapping): void
    {
        $this->isVersioned              = true;
        $this->versionField             = $mapping['fieldName'];
        $this->requiresFetchAfterChange = true;

        if (! isset($mapping['default'])) {
            if (in_array($mapping['type'], ['integer', 'bigint', 'smallint'], true)) {
                $mapping['options']['default'] = 1;
            } elseif ($mapping['type'] === 'datetime') {
                $mapping['options']['default'] = class_exists(CurrentTimestamp::class) ? new CurrentTimestamp() : 'CURRENT_TIMESTAMP';
            } else {
                throw MappingException::unsupportedOptimisticLockingType($this->name, $mapping['fieldName'], $mapping['type']);
            }
        }
    }

    /**
     * Sets whether this class is to be versioned for optimistic locking.
     */
    public function setVersioned(bool $bool): void
    {
        $this->isVersioned = $bool;

        if ($bool) {
            $this->requiresFetchAfterChange = true;
        }
    }

    /**
     * Sets the name of the field that is to be used for versioning if this class is
     * versioned for optimistic locking.
     */
    public function setVersionField(string|null $versionField): void
    {
        $this->versionField = $versionField;
    }

    /**
     * Marks this class as read only, no change tracking is applied to it.
     */
    public function markReadOnly(): void
    {
        $this->isReadOnly = true;
    }

    /**
     * {@inheritDoc}
     */
    public function getFieldNames(): array
    {
        return array_keys($this->fieldMappings);
    }

    /**
     * {@inheritDoc}
     */
    public function getAssociationNames(): array
    {
        return array_keys($this->associationMappings);
    }

    /**
     * {@inheritDoc}
     *
     * @phpstan-return class-string
     *
     * @throws InvalidArgumentException
     */
    public function getAssociationTargetClass(string $assocName): string
    {
        return $this->associationMappings[$assocName]->targetEntity
            ?? throw new InvalidArgumentException("Association name expected, '" . $assocName . "' is not an association.");
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isAssociationInverseSide(string $assocName): bool
    {
        return isset($this->associationMappings[$assocName])
            && ! $this->associationMappings[$assocName]->isOwningSide();
    }

    public function getAssociationMappedByTargetField(string $assocName): string
    {
        $assoc = $this->getAssociationMapping($assocName);

        if (! $assoc instanceof InverseSideMapping) {
            throw new LogicException(sprintf(
                <<<'EXCEPTION'
                Context: Calling %s() with "%s", which is the owning side of an association.
                Problem: The owning side of an association has no "mappedBy" field.
                Solution: Call %s::isAssociationInverseSide() to check first.
                EXCEPTION,
                __METHOD__,
                $assocName,
                self::class,
            ));
        }

        return $assoc->mappedBy;
    }

    /**
     * @param C $className
     *
     * @return string|null null if and only if the input value is null
     * @phpstan-return (C is class-string ? class-string : (C is string ? string : null))
     *
     * @template C of string|null
     */
    public function fullyQualifiedClassName(string|null $className): string|null
    {
        if ($className === null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/pull/11294',
                'Passing null to %s is deprecated and will not be supported in Doctrine ORM 4.0',
                __METHOD__,
            );

            return null;
        }

        if (! str_contains($className, '\\') && $this->namespace) {
            return $this->namespace . '\\' . $className;
        }

        return $className;
    }

    public function getMetadataValue(string $name): mixed
    {
        return $this->$name ?? null;
    }

    /**
     * Map Embedded Class
     *
     * @phpstan-param array{
     *     fieldName: string,
     *     class?: class-string,
     *     declaredField?: string,
     *     columnPrefix?: string|false|null,
     *     originalField?: string
     * } $mapping
     *
     * @throws MappingException
     */
    public function mapEmbedded(array $mapping): void
    {
        $this->assertFieldNotMapped($mapping['fieldName']);

        if (! isset($mapping['class']) && $this->isTypedProperty($mapping['fieldName'])) {
            $type = $this->reflClass->getProperty($mapping['fieldName'])->getType();
            if ($type instanceof ReflectionNamedType) {
                $mapping['class'] = $type->getName();
            }
        }

        if (! (isset($mapping['class']) && $mapping['class'])) {
            throw MappingException::missingEmbeddedClass($mapping['fieldName']);
        }

        $this->embeddedClasses[$mapping['fieldName']] = EmbeddedClassMapping::fromMappingArray([
            'class' => $this->fullyQualifiedClassName($mapping['class']),
            'columnPrefix' => $mapping['columnPrefix'] ?? null,
            'declaredField' => $mapping['declaredField'] ?? null,
            'originalField' => $mapping['originalField'] ?? null,
        ]);
    }

    /**
     * Inline the embeddable class
     */
    public function inlineEmbeddable(string $property, ClassMetadata $embeddable): void
    {
        foreach ($embeddable->fieldMappings as $originalFieldMapping) {
            $fieldMapping                    = (array) $originalFieldMapping;
            $fieldMapping['originalClass'] ??= $embeddable->name;
            $fieldMapping['declaredField']   = isset($fieldMapping['declaredField'])
                ? $property . '.' . $fieldMapping['declaredField']
                : $property;
            $fieldMapping['originalField'] ??= $fieldMapping['fieldName'];
            $fieldMapping['fieldName']       = $property . '.' . $fieldMapping['fieldName'];

            if (! empty($this->embeddedClasses[$property]->columnPrefix)) {
                $fieldMapping['columnName'] = $this->embeddedClasses[$property]->columnPrefix . $fieldMapping['columnName'];
            } elseif ($this->embeddedClasses[$property]->columnPrefix !== false) {
                assert($this->reflClass !== null);
                assert($embeddable->reflClass !== null);
                $fieldMapping['columnName'] = $this->namingStrategy
                    ->embeddedFieldToColumnName(
                        $property,
                        $fieldMapping['columnName'],
                        $this->reflClass->name,
                        $embeddable->reflClass->name,
                    );
            }

            $this->mapField($fieldMapping);
        }
    }

    /** @throws MappingException */
    private function assertFieldNotMapped(string $fieldName): void
    {
        if (
            isset($this->fieldMappings[$fieldName]) ||
            isset($this->associationMappings[$fieldName]) ||
            isset($this->embeddedClasses[$fieldName])
        ) {
            throw MappingException::duplicateFieldMapping($this->name, $fieldName);
        }
    }

    /**
     * Gets the sequence name based on class metadata.
     *
     * @todo Sequence names should be computed in DBAL depending on the platform
     */
    public function getSequenceName(AbstractPlatform $platform): string
    {
        $sequencePrefix = $this->getSequencePrefix($platform);
        $columnName     = $this->getSingleIdentifierColumnName();

        return $sequencePrefix . '_' . $columnName . '_seq';
    }

    /**
     * Gets the sequence name prefix based on class metadata.
     *
     * @todo Sequence names should be computed in DBAL depending on the platform
     */
    public function getSequencePrefix(AbstractPlatform $platform): string
    {
        $tableName      = $this->getTableName();
        $sequencePrefix = $tableName;

        // Prepend the schema name to the table name if there is one
        $schemaName = $this->getSchemaName();
        if ($schemaName) {
            $sequencePrefix = $schemaName . '.' . $tableName;
        }

        return $sequencePrefix;
    }

    private function registerIdentifierField(string $fieldName, int|null $idPosition): void
    {
        $this->identifier[]                    = $fieldName;
        $this->identifierPositions[$fieldName] = $idPosition ?? 0;

        usort(
            $this->identifier,
            fn (string $field1, string $field2) => $this->identifierPositions[$field1] <=> $this->identifierPositions[$field2],
        );
    }

    /**
     * Normalizes orderBy array values to SortDirection instances.
     *
     * @param array<string, SortDirection|string> $orderBy
     *
     * @return array<string, SortDirection>
     */
    private function normalizeOrderBy(array $orderBy, string $fieldName): array
    {
        $normalized = [];

        foreach ($orderBy as $field => $direction) {
            if ($direction instanceof SortDirection) {
                $normalized[$field] = $direction;
            } else {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/11313',
                    '%s::%s : Using "%s" as an order by direction is deprecated in favor of passing a SortDirection instance',
                    $this->name,
                    $fieldName,
                    $direction,
                );
                $normalized[$field] = match (strtoupper($direction)) {
                    'ASC' => SortDirection::Ascending,
                    'DESC' => SortDirection::Descending,
                    default => throw MappingException::invalidOrderByDirection(
                        $direction,
                        $field,
                        $fieldName,
                        $this->name,
                    ),
                };
            }
        }

        return $normalized;
    }
}
