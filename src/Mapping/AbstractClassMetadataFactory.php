<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Common\EventDispatcher;
use Doctrine\DBAL\Platforms;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Deprecations\Deprecation;
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
use Doctrine\ORM\Proxy\DefaultProxyClassNameResolver;
use Doctrine\ORM\Utility\OwningSideResolver;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\ClassMetadataFactory as PersistenceClassMetadataFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\ProxyClassNameResolver;
use Doctrine\Persistence\Mapping\ReflectionService;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Persistence\Proxy;
use ReflectionClass;
use ReflectionException;

use function array_reverse;
use function assert;
use function class_exists;
use function count;
use function end;
use function explode;
use function in_array;
use function is_a;
use function is_int;
use function is_subclass_of;
use function ltrim;
use function method_exists;
use function str_contains;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

use const PHP_VERSION_ID;

/**
 * Shared driver access, inheritance resolution and embedded-class handling for the "lazy"
 * {@see ClassMetadataFactory}, {@see ClassMetadataBagCompiler} and {@see CompiledClassMetadataFactory}.
 */
abstract class AbstractClassMetadataFactory implements PersistenceClassMetadataFactory, ClassMetadataFactoryInterface
{
    protected bool $initialized = false;

    private ReflectionService|null $reflectionService = null;

    private EntityManagerInterface|null $em       = null;
    private AbstractPlatform|null $targetPlatform = null;
    private MappingDriver|null $driver            = null;
    private EventDispatcher|null $eventDispatcher = null;

    private ProxyClassNameResolver|null $proxyClassNameResolver = null;

    /** @var mixed[] */
    private array $embeddablesActiveNesting = [];

    private const NON_IDENTITY_DEFAULT_STRATEGY = [
        Platforms\OraclePlatform::class => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
    ];

    public function setProxyClassNameResolver(ProxyClassNameResolver $resolver): void
    {
        $this->proxyClassNameResolver = $resolver;
    }

    /**
     * Gets the real class name of a class name that could be a proxy.
     *
     * @param class-string $class
     *
     * @return class-string
     */
    protected function getRealClass(string $class): string
    {
        if ($this->proxyClassNameResolver === null) {
            $this->createDefaultProxyClassNameResolver();
        }

        assert($this->proxyClassNameResolver !== null);

        return $this->proxyClassNameResolver->resolveClassName($class);
    }

    private function createDefaultProxyClassNameResolver(): void
    {
        $this->proxyClassNameResolver = new class implements ProxyClassNameResolver {
            /**
             * @param class-string $className
             *
             * @return class-string
             */
            public function resolveClassName(string $className): string
            {
                $pos = strrpos($className, '\\' . Proxy::MARKER . '\\');

                if ($pos === false) {
                    return $className;
                }

                return substr($className, $pos + Proxy::MARKER_LENGTH + 2);
            }
        };
    }

    public function setEntityManager(EntityManagerInterface $em): void
    {
        if (! $em->getConfiguration()->isNativeLazyObjectsEnabled()) {
            $this->setProxyClassNameResolver(new DefaultProxyClassNameResolver());
        }

        $this->em = $em;
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        assert($this->em !== null);

        return $this->em;
    }

    /** Sets the reflectionService. */
    public function setReflectionService(ReflectionService $reflectionService): void
    {
        $this->reflectionService = $reflectionService;
    }

    /** Gets the reflection service associated with this metadata factory. */
    public function getReflectionService(): ReflectionService
    {
        if ($this->reflectionService === null) {
            $this->reflectionService = new RuntimeReflectionService();
        }

        return $this->reflectionService;
    }

    /**
     * Removes the prepended backslash of a class string to conform with how php outputs class names.
     *
     * @param class-string $className
     *
     * @return class-string
     */
    protected function normalizeClassName(string $className): string
    {
        return ltrim($className, '\\');
    }

    /**
     * Gets an array of parent classes for the given entity class, ignoring transient
     * (not-mapped) classes.
     *
     * @param class-string $name
     *
     * @return list<class-string>
     */
    protected function getParentClasses(string $name): array
    {
        $parentClasses = [];

        foreach (array_reverse($this->getReflectionService()->getParentClasses($name)) as $parentClass) {
            if ($this->getDriver()->isTransient($parentClass)) {
                continue;
            }

            $parentClasses[] = $parentClass;
        }

        return $parentClasses;
    }

    /**
     * @deprecated Use {@see OwningSideResolver::resolve()} instead.
     *
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
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/pull/12760',
            '%s is deprecated, use %s::resolve() instead.',
            __METHOD__,
            OwningSideResolver::class,
        );

        return OwningSideResolver::resolve($this, $maybeOwningSide);
    }

    protected function initialize(): void
    {
        $this->driver          = $this->em->getConfiguration()->getMetadataDriverImpl();
        $this->eventDispatcher = $this->em->getEventManager();
        $this->initialized     = true;
    }

    /** @return ClassMetadata<object>|null */
    protected function onNotFoundMetadata(string $className): ClassMetadata|null
    {
        $eventArgs = new OnClassMetadataNotFoundEventArgs($className, $this->em);

        $this->eventDispatcher->dispatchEvent(Events::onClassMetadataNotFound, $eventArgs);
        $classMetadata = $eventArgs->getFoundMetadata();
        assert($classMetadata instanceof ClassMetadata || $classMetadata === null);

        return $classMetadata;
    }

    /**
     * Actually loads the metadata from the underlying metadata.
     *
     * @param ClassMetadata<object>      $class
     * @param ClassMetadata<object>|null $parent
     * @param bool                       $rootEntityFound      True when there is another entity (non-mapped superclass) class above the current class in the PHP class hierarchy.
     * @param list<class-string>         $nonSuperclassParents All parent class names that are not marked as mapped superclasses, with the direct parent class being the first and the root entity class the last element.
     */
    protected function doLoadMetadata(
        ClassMetadata $class,
        ClassMetadata|null $parent,
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
     * @param ClassMetadata<object>      $class
     * @param ClassMetadata<object>|null $parent
     *
     * @throws MappingException
     */
    protected function validateRuntimeMetadata(ClassMetadata $class, ClassMetadata|null $parent): void
    {
        if (! $class->reflClass) {
            // only validate if there is a reflection class instance
            return;
        }

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

    /**
     * @param class-string $className
     *
     * @return ClassMetadata<object>
     */
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
     * @param ClassMetadata<object> $class
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

    /** @param ClassMetadata<object> $rootEntityClass */
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
     *
     * @param ClassMetadata<object> $parentClass
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
     *
     * @param ClassMetadata<object> $subClass
     * @param ClassMetadata<object> $parentClass
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
     * @param ClassMetadata<object> $subClass
     * @param ClassMetadata<object> $parentClass
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

    /**
     * @param ClassMetadata<object> $subClass
     * @param ClassMetadata<object> $parentClass
     */
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
     * @param ClassMetadata<object> $subClass    Sub embedded class metadata to add nested embedded classes metadata from.
     * @param ClassMetadata<object> $parentClass Parent class to add nested embedded classes metadata to.
     * @param string                $prefix      Embedded classes' prefix to use for nested embedded classes field names.
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
     *
     * @param ClassMetadata<object> $subClass
     * @param ClassMetadata<object> $parentClass
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
     * @param ClassMetadata<object> $class
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

        $nonIdentityDefaultStrategy = self::NON_IDENTITY_DEFAULT_STRATEGY;

        // @phpstan-ignore function.alreadyNarrowedType (DBAL 3 compatibility)
        if (method_exists($platform, 'getIdentitySequenceName')) {
            $nonIdentityDefaultStrategy[Platforms\PostgreSQLPlatform::class] = ClassMetadata::GENERATOR_TYPE_SEQUENCE;
        }

        foreach ($nonIdentityDefaultStrategy as $platformFamily => $strategy) {
            if (is_a($platform, $platformFamily)) {
                if ($platform instanceof Platforms\PostgreSQLPlatform) {
                    Deprecation::trigger(
                        'doctrine/orm',
                        'https://github.com/doctrine/orm/issues/8893',
                        <<<'DEPRECATION'
                        Relying on non-optimal defaults for ID generation is deprecated, and IDENTITY
                        results in SERIAL, which is not recommended.
                        Instead, configure identifier generation strategies explicitly through
                        configuration.
                        We currently recommend "SEQUENCE" for "%s", when using DBAL 3,
                        and "IDENTITY" when using DBAL 4,
                        so you should probably use the following configuration before upgrading to DBAL 4,
                        and remove it after deploying that upgrade:

                        $configuration->setIdentityGenerationPreferences([
                            "%s" => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
                        ]);

                        DEPRECATION,
                        $platformFamily,
                        $platformFamily,
                    );
                }

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
     *
     * @param ClassMetadata<object> $class
     * @param ClassMetadata<object> $parent
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

    /** @param ClassMetadata<object> $class */
    protected function wakeupReflection(ClassMetadata $class, ReflectionService $reflService): void
    {
        $class->wakeupReflection($reflService);

        if (PHP_VERSION_ID < 80400) {
            return;
        }

        foreach ($class->propertyAccessors as $propertyAccessor) {
            $property = $propertyAccessor->getUnderlyingReflector();

            if ($property->isVirtual()) {
                throw MappingException::mappingVirtualPropertyNotAllowed($class->name, $property->getName());
            }
        }
    }

    /** @param ClassMetadata<object> $class */
    protected function initializeReflection(ClassMetadata $class, ReflectionService $reflService): void
    {
        $class->initializeReflection($reflService);
    }

    protected function getDriver(): MappingDriver
    {
        assert($this->driver !== null);

        return $this->driver;
    }

    /** @param ClassMetadata<object> $class */
    protected function isEntity(ClassMetadata $class): bool
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

    /** @return list<ClassMetadata<object>> */
    abstract public function getAllMetadata(): array;

    /**
     * @param class-string $className
     *
     * @throws ReflectionException
     * @throws MappingException
     */
    abstract public function getMetadataFor(string $className): ClassMetadata;

    /** @param class-string $className */
    abstract public function hasMetadataFor(string $className): bool;

    /**
     * Sets the metadata descriptor for a specific class.
     *
     * @param class-string $className
     */
    abstract public function setMetadataFor(string $className, ClassMetadataInterface $class): void;

    /** @param class-string $className */
    abstract public function isTransient(string $className): bool;

    /** @param class-string $className */
    abstract public function isMapped(string $className): bool;

    /** @return array<class-string, ClassMetadata<object>> */
    abstract public function getLoadedMetadata(): array;
}
