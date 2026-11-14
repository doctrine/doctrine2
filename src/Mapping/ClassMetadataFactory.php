<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Common\EventDispatcher;
use Doctrine\DBAL\Platforms;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Id\BigIntegerIdentityGenerator;
use Doctrine\ORM\Id\IdentityGenerator;
use Doctrine\ORM\Id\SequenceGenerator;
use Doctrine\ORM\Mapping\Exception\InvalidCustomGenerator;
use Doctrine\ORM\Mapping\Exception\UnknownGeneratorType;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\ReflectionService;
use Override;
use ReflectionClass;
use ReflectionException;

use function assert;
use function class_exists;
use function count;
use function end;
use function explode;
use function in_array;
use function is_a;
use function is_int;
use function is_subclass_of;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;

/**
 * The ClassMetadataFactory is used to create ClassMetadata objects that contain all the
 * metadata mapping information of a class which describes how a class should be mapped
 * to a relational database.
 *
 * @extends AbstractClassMetadataFactory<ClassMetadata>
 */
class ClassMetadataFactory extends AbstractClassMetadataFactory
{
    private EntityManagerInterface|null $em       = null;
    private AbstractPlatform|null $targetPlatform = null;
    private MappingDriver|null $driver            = null;
    private EventDispatcher|null $eventDispatcher = null;

    /** @var mixed[] */
    private array $embeddablesActiveNesting = [];

    private const array NON_IDENTITY_DEFAULT_STRATEGY = [
        Platforms\OraclePlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
    ];

    public function setEntityManager(EntityManagerInterface $em): void
    {
        $this->em = $em;
    }

    /**
     * @param A $maybeOwningSide
     *
     * @return (A is ManyToManyAssociationMapping ? ManyToManyOwningSideMapping : (
     *     A is OneToOneAssociationMapping ? OneToOneOwningSideMapping : (
     *     A is OneToManyAssociationMapping ? ManyToOneAssociationMapping : (
     *     A is ManyToOneAssociationMapping ? ManyToOneAssociationMapping :
     *     ManyToManyOwningSideMapping|OneToOneOwningSideMapping|ManyToOneAssociationMapping
     * ))))
     *
     * @template A of AssociationMapping
     */
    final public function getOwningSide(AssociationMapping $maybeOwningSide): OwningSideMapping
    {
        if ($maybeOwningSide instanceof OwningSideMapping) {
            assert($maybeOwningSide instanceof ManyToManyOwningSideMapping ||
                $maybeOwningSide instanceof OneToOneOwningSideMapping ||
                $maybeOwningSide instanceof ManyToOneAssociationMapping);

            return $maybeOwningSide;
        }

        assert($maybeOwningSide instanceof InverseSideMapping);

        $owningSide = $this->getMetadataFor($maybeOwningSide->targetEntity)
            ->associationMappings[$maybeOwningSide->mappedBy];

        assert($owningSide instanceof ManyToManyOwningSideMapping ||
            $owningSide instanceof OneToOneOwningSideMapping ||
            $owningSide instanceof ManyToOneAssociationMapping);

        return $owningSide;
    }

    #[Override]
    protected function initialize(): void
    {
        $this->driver          = $this->em->getConfiguration()->getMetadataDriverImpl();
        $this->eventDispatcher = $this->em->getEventManager();
        $this->initialized     = true;
    }

    #[Override]
    protected function onNotFoundMetadata(string $className): ClassMetadata|null
    {
        $eventArgs = new OnClassMetadataNotFoundEventArgs($className, $this->em);

        $this->eventDispatcher->dispatchEvent(Events::onClassMetadataNotFound, $eventArgs);
        $classMetadata = $eventArgs->getFoundMetadata();
        assert($classMetadata instanceof ClassMetadata || $classMetadata === null);

        return $classMetadata;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function doLoadMetadata(
        ClassMetadataInterface $class,
        ClassMetadataInterface|null $parent,
        bool $rootEntityFound,
        array $nonSuperclassParents,
    ): void {
        if ($parent) {
            $class->setInheritanceType($parent->inheritanceType);
            $class->setDiscriminatorColumn($parent->discriminatorColumn === null ? null : clone $parent->discriminatorColumn);
            $class->setIdGeneratorType($parent->generatorType);
            $this->addInheritedFields($class, $parent);
            $this->addInheritedRelations($class, $parent);
            $this->addInheritedEmbeddedClasses($class, $parent);
            $class->setIdentifier($parent->identifier, $parent->identifierPositions);
            $class->setVersioned($parent->isVersioned);
            $class->setVersionField($parent->versionField);
            $class->setDiscriminatorMap($parent->discriminatorMap);
            $class->addSubClasses($parent->subClasses);
            $class->setLifecycleCallbacks($parent->lifecycleCallbacks);
            $class->setChangeTrackingPolicy($parent->changeTrackingPolicy);

            if (! empty($parent->customGeneratorDefinition)) {
                $class->setCustomGeneratorDefinition($parent->customGeneratorDefinition);
            }

            if ($parent->isMappedSuperclass) {
                $class->setCustomRepositoryClass($parent->customRepositoryClassName);
            }
        }

        // Invoke driver
        try {
            $this->driver->loadMetadataForClass($class->getName(), $class);
        } catch (ReflectionException $e) {
            throw MappingException::reflectionFailure($class->getName(), $e);
        }

        // If this class has a parent the id generator strategy is inherited.
        // However this is only true if the hierarchy of parents contains the root entity,
        // if it consists of mapped superclasses these don't necessarily include the id field.
        if ($parent && $rootEntityFound) {
            $this->inheritIdGeneratorMapping($class, $parent);
        } else {
            $this->completeIdGeneratorMapping($class);
        }

        if (! $class->isMappedSuperclass) {
            if ($rootEntityFound && $class->isInheritanceTypeNone() && ! $class->isEmbeddedClass) {
                throw MappingException::missingInheritanceTypeDeclaration(end($nonSuperclassParents), $class->name);
            }

            foreach ($class->embeddedClasses as $property => $embeddableClass) {
                if (isset($embeddableClass->inherited)) {
                    continue;
                }

                if (isset($this->embeddablesActiveNesting[$embeddableClass->class])) {
                    throw MappingException::infiniteEmbeddableNesting($class->name, $property);
                }

                $this->embeddablesActiveNesting[$class->name] = true;

                $embeddableMetadata = $this->getMetadataFor($embeddableClass->class);

                if ($embeddableMetadata->isEmbeddedClass) {
                    $this->addNestedEmbeddedClasses($embeddableMetadata, $class, $property);
                }

                $identifier = $embeddableMetadata->getIdentifier();

                if (! empty($identifier)) {
                    $this->inheritIdGeneratorMapping($class, $embeddableMetadata);
                }

                $class->inlineEmbeddable($property, $embeddableMetadata);

                unset($this->embeddablesActiveNesting[$class->name]);
            }
        }

        if ($parent) {
            if ($parent->isInheritanceTypeSingleTable()) {
                $class->setPrimaryTable($parent->table);
            }

            $this->addInheritedIndexes($class, $parent);

            if ($parent->cache) {
                $class->cache = $parent->cache;
            }

            if ($parent->containsForeignIdentifier) {
                $class->containsForeignIdentifier = true;
            }

            if ($parent->containsEnumIdentifier) {
                $class->containsEnumIdentifier = true;
            }

            if (! empty($parent->entityListeners) && empty($class->entityListeners)) {
                $class->entityListeners = $parent->entityListeners;
            }
        }

        $class->setParentClasses($nonSuperclassParents);

        if ($class->isRootEntity() && ! $class->isInheritanceTypeNone() && ! $class->discriminatorMap) {
            $this->addDefaultDiscriminatorMap($class);
        }

        // During the following event, there may also be updates to the discriminator map as per GH-1257/GH-8402.
        // So, we must not discover the missing subclasses before that.

        $this->eventDispatcher->dispatchEvent(
            Events::loadClassMetadata,
            new LoadClassMetadataEventArgs($class, $this->em),
        );

        $this->findAbstractEntityClassesNotListedInDiscriminatorMap($class);

        $this->validateRuntimeMetadata($class, $parent);
    }

    /**
     * Validate runtime metadata is correctly defined.
     *
     * @throws MappingException
     */
    protected function validateRuntimeMetadata(ClassMetadata $class, ClassMetadataInterface|null $parent): void
    {
        $class->validateIdentifier();
        $class->validateAssociations();
        $class->validateLifecycleCallbacks($this->getReflectionService());

        // verify inheritance
        if (! $class->isMappedSuperclass && ! $class->isInheritanceTypeNone()) {
            if (! $parent) {
                if (count($class->discriminatorMap) === 0) {
                    throw MappingException::missingDiscriminatorMap($class->name);
                }

                if (! $class->discriminatorColumn) {
                    throw MappingException::missingDiscriminatorColumn($class->name);
                }

                foreach ($class->subClasses as $subClass) {
                    if ((new ReflectionClass($subClass))->name !== $subClass) {
                        throw MappingException::invalidClassInDiscriminatorMap($subClass, $class->name);
                    }
                }
            } else {
                assert($parent instanceof ClassMetadata); // https://github.com/doctrine/orm/issues/8746
                if (
                    ! $class->reflClass->isAbstract()
                    && ! in_array($class->name, $class->discriminatorMap, true)
                ) {
                    throw MappingException::mappedClassNotPartOfDiscriminatorMap($class->name, $class->rootEntityName);
                }
            }
        } elseif ($class->isMappedSuperclass && $class->name === $class->rootEntityName && (count($class->discriminatorMap) || $class->discriminatorColumn)) {
            // second condition is necessary for mapped superclasses in the middle of an inheritance hierarchy
            throw MappingException::noInheritanceOnMappedSuperClass($class->name);
        }
    }

    #[Override]
    protected function newClassMetadataInstance(string $className): ClassMetadata
    {
        return new ClassMetadata(
            $className,
            $this->em->getConfiguration()->getNamingStrategy(),
            $this->em->getConfiguration()->getTypedFieldMapper(),
        );
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
    private function addDefaultDiscriminatorMap(ClassMetadata $class): void
    {
        $allClasses = $this->driver->getAllClassNames();
        $fqcn       = $class->getName();
        $map        = [$this->getShortName($class->name) => $fqcn];

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
            throw MappingException::duplicateDiscriminatorEntry($class->name, $duplicates, $map);
        }

        $class->setDiscriminatorMap($map);
    }

    private function findAbstractEntityClassesNotListedInDiscriminatorMap(ClassMetadata $rootEntityClass): void
    {
        // Only root classes in inheritance hierarchies need contain a discriminator map,
        // so skip for other classes.
        if (! $rootEntityClass->isRootEntity() || $rootEntityClass->isInheritanceTypeNone()) {
            return;
        }

        $processedClasses = [$rootEntityClass->name => true];
        foreach ($rootEntityClass->subClasses as $knownSubClass) {
            $processedClasses[$knownSubClass] = true;
        }

        foreach ($rootEntityClass->discriminatorMap as $declaredClassName) {
            // This fetches non-transient parent classes only
            $parentClasses = $this->getParentClasses($declaredClassName);

            foreach ($parentClasses as $parentClass) {
                if (isset($processedClasses[$parentClass])) {
                    continue;
                }

                $processedClasses[$parentClass] = true;

                // All non-abstract entity classes must be listed in the discriminator map, and
                // this will be validated/enforced at runtime (possibly at a later time, when the
                // subclass is loaded, but anyways). Also, subclasses is about entity classes only.
                // That means we can ignore non-abstract classes here. The (expensive) driver
                // check for mapped superclasses need only be run for abstract candidate classes.
                if (! (new ReflectionClass($parentClass))->isAbstract() || $this->peekIfIsMappedSuperclass($parentClass)) {
                    continue;
                }

                // We have found a non-transient, non-mapped-superclass = an entity class (possibly abstract, but that does not matter)
                $rootEntityClass->addSubClass($parentClass);
            }
        }
    }

    /** @param class-string $className */
    private function peekIfIsMappedSuperclass(string $className): bool
    {
        $reflService = $this->getReflectionService();
        $class       = $this->newClassMetadataInstance($className);
        $this->initializeReflection($class, $reflService);

        $this->getDriver()->loadMetadataForClass($className, $class);

        return $class->isMappedSuperclass;
    }

    /**
     * Gets the lower-case short name of a class.
     *
     * @param class-string $className
     */
    private function getShortName(string $className): string
    {
        if (! str_contains($className, '\\')) {
            return strtolower($className);
        }

        $parts = explode('\\', $className);

        return strtolower(end($parts));
    }

    /**
     * Puts the `inherited` and `declared` values into mapping information for fields, associations
     * and embedded classes.
     */
    private function addMappingInheritanceInformation(
        AssociationMapping|EmbeddedClassMapping|FieldMapping $mapping,
        ClassMetadata $parentClass,
    ): void {
        if (! isset($mapping->inherited) && ! $parentClass->isMappedSuperclass) {
            $mapping->inherited = $parentClass->name;
        }

        if (! isset($mapping->declared)) {
            $mapping->declared = $parentClass->name;
        }
    }

    /**
     * Adds inherited fields to the subclass mapping.
     */
    private function addInheritedFields(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        foreach ($parentClass->fieldMappings as $mapping) {
            $subClassMapping = clone $mapping;
            $this->addMappingInheritanceInformation($subClassMapping, $parentClass);
            $subClass->addInheritedFieldMapping($subClassMapping);
        }

        foreach ($parentClass->propertyAccessors as $name => $field) {
            $subClass->propertyAccessors[$name] = $field;
        }
    }

    /**
     * Adds inherited association mappings to the subclass mapping.
     *
     * @throws MappingException
     */
    private function addInheritedRelations(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        foreach ($parentClass->associationMappings as $field => $mapping) {
            $subClassMapping = clone $mapping;
            $this->addMappingInheritanceInformation($subClassMapping, $parentClass);
            // When the class inheriting the relation ($subClass) is the first entity class since the
            // relation has been defined in a mapped superclass (or in a chain
            // of mapped superclasses) above, then declare this current entity class as the source of
            // the relationship.
            // According to the definitions given in https://github.com/doctrine/orm/pull/10396/,
            // this is the case <=> ! isset($mapping['inherited']).
            if (! isset($subClassMapping->inherited)) {
                $subClassMapping->sourceEntity = $subClass->name;
            }

            $subClass->addInheritedAssociationMapping($subClassMapping);
        }
    }

    private function addInheritedEmbeddedClasses(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        foreach ($parentClass->embeddedClasses as $field => $embeddedClass) {
            $subClassMapping = clone $embeddedClass;
            $this->addMappingInheritanceInformation($subClassMapping, $parentClass);
            $subClass->embeddedClasses[$field] = $subClassMapping;
        }
    }

    /**
     * Adds nested embedded classes metadata to a parent class.
     *
     * @param ClassMetadata $subClass    Sub embedded class metadata to add nested embedded classes metadata from.
     * @param ClassMetadata $parentClass Parent class to add nested embedded classes metadata to.
     * @param string        $prefix      Embedded classes' prefix to use for nested embedded classes field names.
     */
    private function addNestedEmbeddedClasses(
        ClassMetadata $subClass,
        ClassMetadata $parentClass,
        string $prefix,
    ): void {
        foreach ($subClass->embeddedClasses as $property => $embeddableClass) {
            if (isset($embeddableClass->inherited)) {
                continue;
            }

            $embeddableMetadata = $this->getMetadataFor($embeddableClass->class);

            $parentClass->mapEmbedded(
                [
                    'fieldName' => $prefix . '.' . $property,
                    'class' => $embeddableMetadata->name,
                    'columnPrefix' => $embeddableClass->columnPrefix,
                    'declaredField' => $embeddableClass->declaredField
                            ? $prefix . '.' . $embeddableClass->declaredField
                            : $prefix,
                    'originalField' => $embeddableClass->originalField ?: $property,
                ],
            );
        }
    }

    /**
     * Copy the table indices from the parent class superclass to the child class
     */
    private function addInheritedIndexes(ClassMetadata $subClass, ClassMetadata $parentClass): void
    {
        if (! $parentClass->isMappedSuperclass) {
            return;
        }

        foreach (['uniqueConstraints', 'indexes'] as $indexType) {
            if (isset($parentClass->table[$indexType])) {
                foreach ($parentClass->table[$indexType] as $indexName => $index) {
                    if (is_int($indexName)) {
                        // Unnamed indices only have a positional key, so identify them by
                        // their columns: the inheriting table overrides such an index by
                        // declaring one on the same columns
                        if (! self::hasIndexOnSameColumns($subClass->table[$indexType] ?? [], $index)) {
                            $subClass->table[$indexType][] = $index;
                        }

                        continue;
                    }

                    if (isset($subClass->table[$indexType][$indexName])) {
                        continue; // Let the inheriting table override indices
                    }

                    $subClass->table[$indexType][$indexName] = $index;
                }
            }
        }
    }

    /**
     * @param array<int|string, array<string, mixed>> $indexes
     * @param array<string, mixed>                    $index
     */
    private static function hasIndexOnSameColumns(array $indexes, array $index): bool
    {
        foreach ($indexes as $existingIndex) {
            if (
                ($existingIndex['columns'] ?? null) === ($index['columns'] ?? null)
                && ($existingIndex['fields'] ?? null) === ($index['fields'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Completes the ID generator mapping. If "auto" is specified we choose the generator
     * most appropriate for the targeted database platform.
     *
     * @throws ORMException
     */
    private function completeIdGeneratorMapping(ClassMetadata $class): void
    {
        $idGenType = $class->generatorType;
        if ($idGenType === ClassMetadata::GENERATOR_TYPE_AUTO) {
            $class->setIdGeneratorType($this->determineIdGeneratorStrategy($this->getTargetPlatform()));
        }

        // Create & assign an appropriate ID generator instance
        switch ($class->generatorType) {
            case ClassMetadata::GENERATOR_TYPE_IDENTITY:
                $sequenceName = null;
                $fieldName    = $class->identifier ? $class->getSingleIdentifierFieldName() : null;
                $platform     = $this->getTargetPlatform();

                $generator = $fieldName && $class->fieldMappings[$fieldName]->type === 'bigint'
                    ? new BigIntegerIdentityGenerator()
                    : new IdentityGenerator();

                $class->setIdGenerator($generator);

                break;

            case ClassMetadata::GENERATOR_TYPE_SEQUENCE:
                // If there is no sequence definition yet, create a default definition
                $definition = $class->sequenceGeneratorDefinition;

                if (! $definition) {
                    $fieldName    = $class->getSingleIdentifierFieldName();
                    $sequenceName = $class->getSequenceName($this->getTargetPlatform());
                    $quoted       = isset($class->fieldMappings[$fieldName]->quoted) || isset($class->table['quoted']);

                    $definition = [
                        'sequenceName'      => $this->truncateSequenceName($sequenceName),
                        'allocationSize'    => 1,
                        'initialValue'      => 1,
                    ];

                    if ($quoted) {
                        $definition['quoted'] = true;
                    }

                    $class->setSequenceGeneratorDefinition($definition);
                }

                $sequenceGenerator = new SequenceGenerator(
                    $this->em->getConfiguration()->getQuoteStrategy()->getSequenceName($definition, $class, $this->getTargetPlatform()),
                    (int) $definition['allocationSize'],
                );
                $class->setIdGenerator($sequenceGenerator);
                break;

            case ClassMetadata::GENERATOR_TYPE_NONE:
                $class->setIdGenerator(new AssignedGenerator());
                break;

            case ClassMetadata::GENERATOR_TYPE_CUSTOM:
                $definition = $class->customGeneratorDefinition;
                if ($definition === null) {
                    throw InvalidCustomGenerator::onClassNotConfigured();
                }

                if (! class_exists($definition['class'])) {
                    throw InvalidCustomGenerator::onMissingClass($definition);
                }

                $class->setIdGenerator(new $definition['class']());
                break;

            default:
                throw UnknownGeneratorType::create($class->generatorType);
        }
    }

    /** @phpstan-return ClassMetadata::GENERATOR_TYPE_* */
    private function determineIdGeneratorStrategy(AbstractPlatform $platform): int
    {
        assert($this->em !== null);
        foreach ($this->em->getConfiguration()->getIdentityGenerationPreferences() as $platformFamily => $strategy) {
            if (is_a($platform, $platformFamily)) {
                return $strategy;
            }
        }

        foreach (self::NON_IDENTITY_DEFAULT_STRATEGY as $platformFamily => $strategy) {
            if (is_a($platform, $platformFamily)) {
                return $strategy;
            }
        }

        return ClassMetadata::GENERATOR_TYPE_IDENTITY;
    }

    private function truncateSequenceName(string $schemaElementName): string
    {
        $platform = $this->getTargetPlatform();
        if (! $platform instanceof Platforms\OraclePlatform) {
            return $schemaElementName;
        }

        $maxIdentifierLength = $platform->getMaxIdentifierLength();

        if (strlen($schemaElementName) > $maxIdentifierLength) {
            return substr($schemaElementName, 0, $maxIdentifierLength);
        }

        return $schemaElementName;
    }

    /**
     * Inherits the ID generator mapping from a parent class.
     */
    private function inheritIdGeneratorMapping(ClassMetadata $class, ClassMetadata $parent): void
    {
        if ($parent->isIdGeneratorSequence()) {
            $class->setSequenceGeneratorDefinition($parent->sequenceGeneratorDefinition);
        }

        if ($parent->generatorType) {
            $class->setIdGeneratorType($parent->generatorType);
        }

        if ($parent->idGenerator ?? null) {
            $class->setIdGenerator($parent->idGenerator);
        }
    }

    #[Override]
    protected function wakeupReflection(ClassMetadataInterface $class, ReflectionService $reflService): void
    {
        $class->wakeupReflection($reflService);

        foreach ($class->propertyAccessors as $propertyAccessor) {
            $property = $propertyAccessor->getUnderlyingReflector();

            if ($property->isVirtual()) {
                throw MappingException::mappingVirtualPropertyNotAllowed($class->name, $property->getName());
            }
        }
    }

    #[Override]
    protected function initializeReflection(ClassMetadataInterface $class, ReflectionService $reflService): void
    {
        $class->initializeReflection($reflService);
    }

    #[Override]
    protected function getDriver(): MappingDriver
    {
        assert($this->driver !== null);

        return $this->driver;
    }

    #[Override]
    protected function isEntity(ClassMetadataInterface $class): bool
    {
        return ! $class->isMappedSuperclass;
    }

    private function getTargetPlatform(): Platforms\AbstractPlatform
    {
        if (! $this->targetPlatform) {
            $this->targetPlatform = $this->em->getConnection()->getDatabasePlatform();
        }

        return $this->targetPlatform;
    }
}
