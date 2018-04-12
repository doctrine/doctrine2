<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\MappingDriver;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Sequencing;
use Doctrine\ORM\Sequencing\Planning\AssociationValueGeneratorExecutor;
use Doctrine\ORM\Sequencing\Planning\ColumnValueGeneratorExecutor;
use Doctrine\ORM\Sequencing\Planning\CompositeValueGenerationPlan;
use Doctrine\ORM\Sequencing\Planning\NoopValueGenerationPlan;
use Doctrine\ORM\Sequencing\Planning\SingleValueGenerationPlan;
use Doctrine\ORM\Sequencing\Planning\ValueGenerationExecutor;
use ReflectionException;
use function array_map;
use function class_exists;
use function count;
use function end;
use function explode;
use function is_subclass_of;
use function sprintf;
use function strpos;
use function strtolower;
use function var_export;

/**
 * The ClassMetadataFactory is used to create ClassMetadata objects that contain all the
 * metadata mapping information of a class which describes how a class should be mapped
 * to a relational database.
 */
class ClassMetadataFactory extends AbstractClassMetadataFactory
{

    /** @var AbstractPlatform */
    private $targetPlatform;

    /**
     * @var Configuration
     */
    private $configuration;
    /**
     * @var Connection
     */
    private $connection;
    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * @var MappingDriver
     */
    private $driver;

    public function __construct(Configuration $configuration, Connection $connection, EventManager $eventManager)
    {
        $this->configuration = $configuration;
        $this->connection = $connection;
        $this->eventManager = $eventManager;
        $this->driver      = $this->configuration->getMetadataDriverImpl();
    }

    /**
     * {@inheritdoc}
     */
    protected function loadMetadata(string $name, ClassMetadataBuildingContext $metadataBuildingContext) : array
    {
        $loaded = parent::loadMetadata($name, $metadataBuildingContext);

        array_map([$this, 'resolveDiscriminatorValue'], $loaded);

        return $loaded;
    }

    /**
     * {@inheritdoc}
     *
     * @throws MappingException
     * @throws ORMException
     */
    protected function doLoadMetadata(
        string $className,
        ?ClassMetadata $parent,
        ClassMetadataBuildingContext $metadataBuildingContext
    ) : ClassMetadata {
        $classMetadata = new ClassMetadata($className, $metadataBuildingContext);

        if ($parent) {
            $classMetadata->setParent($parent);

            foreach ($parent->getDeclaredPropertiesIterator() as $fieldName => $property) {
                $classMetadata->addInheritedProperty($property);
            }

            $classMetadata->setInheritanceType($parent->inheritanceType);
            $classMetadata->setIdentifier($parent->identifier);

            if ($parent->discriminatorColumn) {
                $classMetadata->setDiscriminatorColumn($parent->discriminatorColumn);
                $classMetadata->setDiscriminatorMap($parent->discriminatorMap);
            }

            $classMetadata->setLifecycleCallbacks($parent->lifecycleCallbacks);
            $classMetadata->setChangeTrackingPolicy($parent->changeTrackingPolicy);

            if ($parent->isMappedSuperclass) {
                $classMetadata->setCustomRepositoryClassName($parent->getCustomRepositoryClassName());
            }
        }

        // Invoke driver
        try {
            $this->driver->loadMetadataForClass($classMetadata->getClassName(), $classMetadata, $metadataBuildingContext);
        } catch (ReflectionException $e) {
            throw MappingException::reflectionFailure($classMetadata->getClassName(), $e);
        }

        $this->completeIdentifierGeneratorMappings($classMetadata);

        if ($parent) {
            if ($parent->getCache()) {
                $classMetadata->setCache(clone $parent->getCache());
            }

            if (! empty($parent->entityListeners) && empty($classMetadata->entityListeners)) {
                $classMetadata->entityListeners = $parent->entityListeners;
            }
        }

        if (! $classMetadata->discriminatorMap && $classMetadata->inheritanceType !== InheritanceType::NONE && $classMetadata->isRootEntity()) {
            $this->addDefaultDiscriminatorMap($classMetadata);
        }

        $this->completeRuntimeMetadata($classMetadata, $parent);

        $this->buildValueGenerationPlan($classMetadata);
        $this->validateRuntimeMetadata($classMetadata, $parent);

        return $classMetadata;
    }

    protected function completeRuntimeMetadata(ClassMetadata $class, ?ClassMetadata $parent = null) : void
    {
        if (! $parent || ! $parent->isMappedSuperclass) {
            return;
        }

        if ($class->isMappedSuperclass) {
            return;
        }

        $tableName = $class->getTableName();

        // Resolve column table names
        foreach ($class->getDeclaredPropertiesIterator() as $property) {
            if ($property instanceof FieldMetadata) {
                $property->setTableName($property->getTableName() ?? $tableName);

                continue;
            }

            if (! ($property instanceof ToOneAssociationMetadata)) {
                continue;
            }

            // Resolve association join column table names
            foreach ($property->getJoinColumns() as $joinColumn) {
                /** @var JoinColumnMetadata $joinColumn */
                $joinColumn->setTableName($joinColumn->getTableName() ?? $tableName);
            }
        }
    }

    /**
     * Validate runtime metadata is correctly defined.
     *
     * @throws MappingException
     */
    protected function validateRuntimeMetadata(ClassMetadata $class, ?ClassMetadata $parent = null) : void
    {
        if (! $class->getReflectionClass()) {
            // only validate if there is a reflection class instance
            return;
        }

        $class->validateIdentifier();
        $class->validateAssociations();
        $class->validateLifecycleCallbacks($this->getReflectionService());

        // verify inheritance
        if (! $class->isMappedSuperclass && $class->inheritanceType !== InheritanceType::NONE) {
            if (! $parent) {
                if (! $class->discriminatorMap) {
                    throw MappingException::missingDiscriminatorMap($class->getClassName());
                }

                if (! $class->discriminatorColumn) {
                    throw MappingException::missingDiscriminatorColumn($class->getClassName());
                }
            }
        } elseif (($class->discriminatorMap || $class->discriminatorColumn) && $class->isMappedSuperclass && $class->isRootEntity()) {
            // second condition is necessary for mapped superclasses in the middle of an inheritance hierarchy
            throw MappingException::noInheritanceOnMappedSuperClass($class->getClassName());
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function newClassMetadataBuildingContext() : ClassMetadataBuildingContext
    {
        return new ClassMetadataBuildingContext(
            $this,
            $this->getReflectionService(),
            $this->configuration->getNamingStrategy()
        );
    }

    /**
     * Populates the discriminator value of the given metadata (if not set) by iterating over discriminator
     * map classes and looking for a fitting one.
     *
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     * @throws MappingException
     */
    private function resolveDiscriminatorValue(ClassMetadata $metadata) : void
    {
        if ($metadata->discriminatorValue || ! $metadata->discriminatorMap || $metadata->isMappedSuperclass ||
            ! $metadata->getReflectionClass() || $metadata->getReflectionClass()->isAbstract()) {
            return;
        }

        // minor optimization: avoid loading related metadata when not needed
        foreach ($metadata->discriminatorMap as $discriminatorValue => $discriminatorClass) {
            if ($discriminatorClass === $metadata->getClassName()) {
                $metadata->discriminatorValue = $discriminatorValue;

                return;
            }
        }

        // iterate over discriminator mappings and resolve actual referenced classes according to existing metadata
        foreach ($metadata->discriminatorMap as $discriminatorValue => $discriminatorClass) {
            if ($metadata->getClassName() === $this->getMetadataFor($discriminatorClass)->getClassName()) {
                $metadata->discriminatorValue = $discriminatorValue;

                return;
            }
        }

        throw MappingException::mappedClassNotPartOfDiscriminatorMap($metadata->getClassName(), $metadata->getRootClassName());
    }

    /**
     * Adds a default discriminator map if no one is given
     *
     * If an entity is of any inheritance type and does not contain a
     * discriminator map, then the map is generated automatically. This process
     * is expensive computation wise.
     *
     * The automatically generated discriminator map contains the lowercase short name of
     * each class as key.
     *
     * @throws MappingException
     */
    private function addDefaultDiscriminatorMap(ClassMetadata $class) : void
    {
        $allClasses = $this->driver->getAllClassNames();
        $fqcn       = $class->getClassName();
        $map        = [$this->getShortName($fqcn) => $fqcn];
        $duplicates = [];

        foreach ($allClasses as $subClassCandidate) {
            if (is_subclass_of($subClassCandidate, $fqcn)) {
                $shortName = $this->getShortName($subClassCandidate);

                if (isset($map[$shortName])) {
                    $duplicates[] = $shortName;
                }

                $map[$shortName] = $subClassCandidate;
            }
        }

        if ($duplicates) {
            throw MappingException::duplicateDiscriminatorEntry($class->getClassName(), $duplicates, $map);
        }

        $class->setDiscriminatorMap($map);
    }

    /**
     * Gets the lower-case short name of a class.
     *
     * @param string $className
     */
    private function getShortName($className) : string
    {
        if (strpos($className, '\\') === false) {
            return strtolower($className);
        }

        $parts = explode('\\', $className);

        return strtolower(end($parts));
    }

    /**
     * Completes the ID generator mapping. If "auto" is specified we choose the generator
     * most appropriate for the targeted database platform.
     *
     * @throws ORMException
     */
    private function completeIdentifierGeneratorMappings(ClassMetadata $class) : void
    {
        foreach ($class->getDeclaredPropertiesIterator() as $property) {
            if (! $property instanceof FieldMetadata /*&& ! $property instanceof AssocationMetadata*/) {
                continue;
            }

            $this->completeFieldIdentifierGeneratorMapping($property);
        }
    }

    private function completeFieldIdentifierGeneratorMapping(FieldMetadata $field)
    {
        if (! $field->hasValueGenerator()) {
            return;
        }

        $platform  = $this->getTargetPlatform();
        $class     = $field->getDeclaringClass();
        $generator = $field->getValueGenerator();

        if ($generator->getType() === GeneratorType::AUTO) {
            $generator = new ValueGeneratorMetadata(
                $platform->prefersSequences()
                    ? GeneratorType::SEQUENCE
                    : ($platform->prefersIdentityColumns()
                        ? GeneratorType::IDENTITY
                        : GeneratorType::TABLE
                ),
                $field->getValueGenerator()->getDefinition()
            );
            $field->setValueGenerator($generator);
        }

        // Validate generator definition and set defaults where needed
        switch ($generator->getType()) {
            case GeneratorType::SEQUENCE:
                // If there is no sequence definition yet, create a default definition
                if ($generator->getDefinition()) {
                    break;
                }

                // @todo guilhermeblanco Move sequence generation to DBAL
                $sequencePrefix = $platform->getSequencePrefix($field->getTableName(), $field->getSchemaName());
                $idSequenceName = sprintf('%s_%s_seq', $sequencePrefix, $field->getColumnName());
                $sequenceName   = $platform->fixSchemaElementName($idSequenceName);

                $field->setValueGenerator(
                    new ValueGeneratorMetadata(
                        $generator->getType(),
                        [
                            'sequenceName'   => $sequenceName,
                            'allocationSize' => 1,
                        ]
                    )
                );

                break;

            case GeneratorType::TABLE:
                throw new ORMException('TableGenerator not yet implemented.');
                break;

            case GeneratorType::CUSTOM:
                $definition = $generator->getDefinition();
                if (! isset($definition['class'])) {
                    throw new ORMException(sprintf('Cannot instantiate custom generator, no class has been defined'));
                }
                if (! class_exists($definition['class'])) {
                    throw new ORMException(sprintf('Cannot instantiate custom generator : %s', var_export($definition, true))); //$definition['class']));
                }

                break;

            case GeneratorType::IDENTITY:
            case GeneratorType::NONE:
            case GeneratorType::UUID:
                break;

            default:
                throw new ORMException('Unknown generator type: ' . $generator->getType());
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function getDriver() : Driver\MappingDriver
    {
        return $this->driver;
    }

    /**
     * {@inheritDoc}
     */
    protected function isEntity(ClassMetadata $class) : bool
    {
        return isset($class->isMappedSuperclass) && $class->isMappedSuperclass === false;
    }

    private function getTargetPlatform() : Platforms\AbstractPlatform
    {
        if (! $this->targetPlatform) {
            $this->targetPlatform = $this->connection->getDatabasePlatform();
        }

        return $this->targetPlatform;
    }

    private function buildValueGenerationPlan(ClassMetadata $class) : void
    {
        $executors = $this->buildValueGenerationExecutorList($class);

        switch (count($executors)) {
            case 0:
                $class->setValueGenerationPlan(new NoopValueGenerationPlan());
                break;

            case 1:
                $class->setValueGenerationPlan(new SingleValueGenerationPlan($class, $executors[0]));
                break;

            default:
                $class->setValueGenerationPlan(new CompositeValueGenerationPlan($class, $executors));
                break;
        }
    }

    /**
     * @return ValueGenerationExecutor[]
     */
    private function buildValueGenerationExecutorList(ClassMetadata $class) : array
    {
        $executors = [];

        foreach ($class->getDeclaredPropertiesIterator() as $property) {
            $executor = $this->buildValueGenerationExecutorForProperty($class, $property);

            if ($executor instanceof ValueGenerationExecutor) {
                $executors[] = $executor;
            }
        }

        return $executors;
    }

    private function buildValueGenerationExecutorForProperty(
        ClassMetadata $class,
        Property $property
    ) : ?ValueGenerationExecutor {
        if ($property instanceof LocalColumnMetadata && $property->hasValueGenerator()) {
            return new ColumnValueGeneratorExecutor($property, $this->createPropertyValueGenerator($class, $property));
        }

        if ($property instanceof ToOneAssociationMetadata && $property->isPrimaryKey()) {
            return new AssociationValueGeneratorExecutor();
        }

        return null;
    }

    private function createPropertyValueGenerator(
        ClassMetadata $class,
        LocalColumnMetadata $property
    ) : Sequencing\Generator {
        $platform = $this->getTargetPlatform();

        switch ($property->getValueGenerator()->getType()) {
            case GeneratorType::IDENTITY:
                $sequenceName = null;

                // Platforms that do not have native IDENTITY support need a sequence to emulate this behaviour.
                if ($platform->usesSequenceEmulatedIdentityColumns()) {
                    $sequencePrefix = $platform->getSequencePrefix($class->getTableName(), $class->getSchemaName());
                    $idSequenceName = $platform->getIdentitySequenceName($sequencePrefix, $property->getColumnName());
                    $sequenceName   = $platform->quoteIdentifier($platform->fixSchemaElementName($idSequenceName));
                }

                return $property->getTypeName() === 'bigint'
                    ? new Sequencing\BigIntegerIdentityGenerator($sequenceName)
                    : new Sequencing\IdentityGenerator($sequenceName);

            case GeneratorType::SEQUENCE:
                $definition = $property->getValueGenerator()->getDefinition();
                return new Sequencing\SequenceGenerator(
                    $platform->quoteIdentifier($definition['sequenceName']),
                    $definition['allocationSize']
                );
                break;

            case GeneratorType::UUID:
                return new Sequencing\UuidGenerator();
                break;

            case GeneratorType::CUSTOM:
                $class = $property->getValueGenerator()->getDefinition()['class'];
                return new $class();
                break;
        }
    }
}
