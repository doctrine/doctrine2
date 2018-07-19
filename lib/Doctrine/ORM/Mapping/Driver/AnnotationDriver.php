<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Annotation;
use Doctrine\ORM\Cache\CacheException;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use function array_diff;
use function array_intersect;
use function array_map;
use function array_merge;
use function array_unique;
use function class_exists;
use function constant;
use function count;
use function defined;
use function get_class;
use function get_declared_classes;
use function in_array;
use function is_dir;
use function is_numeric;
use function preg_match;
use function preg_quote;
use function realpath;
use function sprintf;
use function str_replace;
use function strpos;
use function strtolower;
use function strtoupper;

/**
 * The AnnotationDriver reads the mapping metadata from docblock annotations.
 *
 */
class AnnotationDriver implements MappingDriver
{
    /** @var int[] */
    protected $entityAnnotationClasses = [
        Annotation\Entity::class           => 1,
        Annotation\MappedSuperclass::class => 2,
    ];

    /**
     * The AnnotationReader.
     *
     * @var AnnotationReader
     */
    protected $reader;

    /**
     * The paths where to look for mapping files.
     *
     * @var string[]
     */
    protected $paths = [];

    /**
     * The paths excluded from path where to look for mapping files.
     *
     * @var string[]
     */
    protected $excludePaths = [];

    /**
     * The file extension of mapping documents.
     *
     * @var string
     */
    protected $fileExtension = '.php';

    /**
     * Cache for AnnotationDriver#getAllClassNames().
     *
     * @var string[]|null
     */
    protected $classNames;

    /**
     * Initializes a new AnnotationDriver that uses the given AnnotationReader for reading
     * docblock annotations.
     *
     * @param Reader               $reader The AnnotationReader to use, duck-typed.
     * @param string|string[]|null $paths  One or multiple paths where mapping classes can be found.
     */
    public function __construct(Reader $reader, $paths = null)
    {
        $this->reader = $reader;
        if ($paths) {
            $this->addPaths((array) $paths);
        }
    }

    /**
     * Appends lookup paths to metadata driver.
     *
     * @param string[] $paths
     */
    public function addPaths(array $paths)
    {
        $this->paths = array_unique(array_merge($this->paths, $paths));
    }

    /**
     * Retrieves the defined metadata lookup paths.
     *
     * @return string[]
     */
    public function getPaths()
    {
        return $this->paths;
    }

    /**
     * Append exclude lookup paths to metadata driver.
     *
     * @param string[] $paths
     */
    public function addExcludePaths(array $paths)
    {
        $this->excludePaths = array_unique(array_merge($this->excludePaths, $paths));
    }

    /**
     * Retrieve the defined metadata lookup exclude paths.
     *
     * @return string[]
     */
    public function getExcludePaths()
    {
        return $this->excludePaths;
    }

    /**
     * Retrieve the current annotation reader
     *
     * @return AnnotationReader
     */
    public function getReader()
    {
        return $this->reader;
    }

    /**
     * Gets the file extension used to look for mapping files under.
     *
     * @return string
     */
    public function getFileExtension()
    {
        return $this->fileExtension;
    }

    /**
     * Sets the file extension used to look for mapping files under.
     *
     * @param string $fileExtension The file extension to set.
     *
     */
    public function setFileExtension($fileExtension)
    {
        $this->fileExtension = $fileExtension;
    }

    /**
     * Returns whether the class with the specified name is transient. Only non-transient
     * classes, that is entities and mapped superclasses, should have their metadata loaded.
     *
     * A class is non-transient if it is annotated with an annotation
     * from the {@see AnnotationDriver::entityAnnotationClasses}.
     *
     * @param string $className
     *
     * @return bool
     */
    public function isTransient($className)
    {
        $classAnnotations = $this->reader->getClassAnnotations(new \ReflectionClass($className));

        foreach ($classAnnotations as $annot) {
            if (isset($this->entityAnnotationClasses[get_class($annot)])) {
                return false;
            }
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getAllClassNames()
    {
        if ($this->classNames !== null) {
            return $this->classNames;
        }

        if (! $this->paths) {
            throw Mapping\MappingException::pathRequired();
        }

        $classes       = [];
        $includedFiles = [];

        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                throw Mapping\MappingException::fileMappingDriversRequireConfiguredDirectoryPath($path);
            }

            $iterator = new \RegexIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                ),
                '/^.+' . preg_quote($this->fileExtension) . '$/i',
                \RecursiveRegexIterator::GET_MATCH
            );

            foreach ($iterator as $file) {
                $sourceFile = $file[0];

                if (! preg_match('(^phar:)i', $sourceFile)) {
                    $sourceFile = realpath($sourceFile);
                }

                foreach ($this->excludePaths as $excludePath) {
                    $exclude = str_replace('\\', '/', realpath($excludePath));
                    $current = str_replace('\\', '/', $sourceFile);

                    if (strpos($current, $exclude) !== false) {
                        continue 2;
                    }
                }

                require_once $sourceFile;

                $includedFiles[] = $sourceFile;
            }
        }

        $declared = get_declared_classes();

        foreach ($declared as $className) {
            $rc         = new \ReflectionClass($className);
            $sourceFile = $rc->getFileName();
            if (in_array($sourceFile, $includedFiles, true) && ! $this->isTransient($className)) {
                $classes[] = $className;
            }
        }

        $this->classNames = $classes;

        return $classes;
    }

    /**
     * {@inheritDoc}
     *
     * @throws CacheException
     * @throws Mapping\MappingException
     * @throws \ReflectionException
     * @throws \RuntimeException
     */
    public function loadMetadataForClass(
        string $className,
        Mapping\ClassMetadata $metadata,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) : Mapping\ClassMetadata {
        $reflectionClass = $metadata->getReflectionClass();

        if (! $reflectionClass) {
            // this happens when running annotation driver in combination with
            // static reflection services. This is not the nicest fix
            $reflectionClass = new \ReflectionClass($metadata->getClassName());
        }

        $classAnnotations = $this->getClassAnnotations($reflectionClass);
        $classMetadata    = $this->convertClassAnnotationsToClassMetadata(
            $classAnnotations,
            $reflectionClass,
            $metadata,
            $metadataBuildingContext
        );

        // Evaluate @Cache annotation
        if (isset($classAnnotations[Annotation\Cache::class])) {
            $cacheAnnot = $classAnnotations[Annotation\Cache::class];
            $cache      = $this->convertCacheAnnotationToCacheMetadata($cacheAnnot, $metadata);

            $classMetadata->setCache($cache);
        }

        // Evaluate annotations on properties/fields
        /** @var \ReflectionProperty $reflProperty */
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->getDeclaringClass()->getName() !== $reflectionClass->getName()) {
                continue;
            }

            $propertyAnnotations = $this->getPropertyAnnotations($reflectionProperty);
            $property            = $this->convertPropertyAnnotationsToProperty(
                $propertyAnnotations,
                $reflectionProperty,
                $classMetadata
            );

            if ($classMetadata->isMappedSuperclass &&
                $property instanceof Mapping\ToManyAssociationMetadata &&
                ! $property->isOwningSide()) {
                throw Mapping\MappingException::illegalToManyAssociationOnMappedSuperclass(
                    $classMetadata->getClassName(),
                    $property->getName()
                );
            }

            if (! $property) {
                continue;
            }

            $metadata->addProperty($property);
        }

        $this->attachPropertyOverrides($classAnnotations, $reflectionClass, $metadata);

        return $classMetadata;
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     *
     * @throws Mapping\MappingException
     */
    private function convertClassAnnotationsToClassMetadata(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) : Mapping\ClassMetadata {
        switch (true) {
            case isset($classAnnotations[Annotation\Entity::class]):
                return $this->convertClassAnnotationsToEntityClassMetadata(
                    $classAnnotations,
                    $reflectionClass,
                    $metadata,
                    $metadataBuildingContext
                );

                break;

            case isset($classAnnotations[Annotation\MappedSuperclass::class]):
                return $this->convertClassAnnotationsToMappedSuperClassMetadata(
                    $classAnnotations,
                    $reflectionClass,
                    $metadata
                );

            case isset($classAnnotations[Annotation\Embeddable::class]):
                return $this->convertClassAnnotationsToEmbeddableClassMetadata(
                    $classAnnotations,
                    $reflectionClass,
                    $metadata
                );

            default:
                throw Mapping\MappingException::classIsNotAValidEntityOrMappedSuperClass($reflectionClass->getName());
        }
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     *
     * @return Mapping\ClassMetadata
     *
     * @throws Mapping\MappingException
     * @throws \UnexpectedValueException
     */
    private function convertClassAnnotationsToEntityClassMetadata(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) {
        /** @var Annotation\Entity $entityAnnot */
        $entityAnnot = $classAnnotations[Annotation\Entity::class];

        if ($entityAnnot->repositoryClass !== null) {
            $metadata->setCustomRepositoryClassName($entityAnnot->repositoryClass);
        }

        if ($entityAnnot->readOnly) {
            $metadata->asReadOnly();
        }

        $metadata->isMappedSuperclass = false;
        $metadata->isEmbeddedClass    = false;

        $this->attachTable($classAnnotations, $reflectionClass, $metadata, $metadataBuildingContext);

        // Evaluate @ChangeTrackingPolicy annotation
        if (isset($classAnnotations[Annotation\ChangeTrackingPolicy::class])) {
            $changeTrackingAnnot = $classAnnotations[Annotation\ChangeTrackingPolicy::class];

            $metadata->setChangeTrackingPolicy(
                constant(sprintf('%s::%s', Mapping\ChangeTrackingPolicy::class, $changeTrackingAnnot->value))
            );
        }

        // Evaluate @InheritanceType annotation
        if (isset($classAnnotations[Annotation\InheritanceType::class])) {
            $inheritanceTypeAnnot = $classAnnotations[Annotation\InheritanceType::class];

            $metadata->setInheritanceType(
                constant(sprintf('%s::%s', Mapping\InheritanceType::class, $inheritanceTypeAnnot->value))
            );

            if ($metadata->inheritanceType !== Mapping\InheritanceType::NONE) {
                $this->attachDiscriminatorColumn($classAnnotations, $reflectionClass, $metadata);
            }
        }

        $this->attachLifecycleCallbacks($classAnnotations, $reflectionClass, $metadata);
        $this->attachEntityListeners($classAnnotations, $reflectionClass, $metadata);

        return $metadata;
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     */
    private function convertClassAnnotationsToMappedSuperClassMetadata(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata
    ) : Mapping\ClassMetadata {
        /** @var Annotation\MappedSuperclass $mappedSuperclassAnnot */
        $mappedSuperclassAnnot = $classAnnotations[Annotation\MappedSuperclass::class];

        if ($mappedSuperclassAnnot->repositoryClass !== null) {
            $metadata->setCustomRepositoryClassName($mappedSuperclassAnnot->repositoryClass);
        }

        $metadata->isMappedSuperclass = true;
        $metadata->isEmbeddedClass    = false;

        $this->attachLifecycleCallbacks($classAnnotations, $reflectionClass, $metadata);
        $this->attachEntityListeners($classAnnotations, $reflectionClass, $metadata);

        return $metadata;
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     */
    private function convertClassAnnotationsToEmbeddableClassMetadata(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata
    ) : Mapping\ClassMetadata {
        $metadata->isMappedSuperclass = false;
        $metadata->isEmbeddedClass    = true;

        return $metadata;
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     *
     * @todo guilhermeblanco Remove nullable typehint once embeddables are back
     */
    private function convertPropertyAnnotationsToProperty(
        array $propertyAnnotations,
        \ReflectionProperty $reflectionProperty,
        Mapping\ClassMetadata $metadata
    ) : ?Mapping\Property {
        switch (true) {
            case isset($propertyAnnotations[Annotation\Column::class]):
                return $this->convertReflectionPropertyToFieldMetadata(
                    $reflectionProperty,
                    $propertyAnnotations,
                    $metadata
                );

            case isset($propertyAnnotations[Annotation\OneToOne::class]):
                return $this->convertReflectionPropertyToOneToOneAssociationMetadata(
                    $reflectionProperty,
                    $propertyAnnotations,
                    $metadata
                );

            case isset($propertyAnnotations[Annotation\ManyToOne::class]):
                return $this->convertReflectionPropertyToManyToOneAssociationMetadata(
                    $reflectionProperty,
                    $propertyAnnotations,
                    $metadata
                );

            case isset($propertyAnnotations[Annotation\OneToMany::class]):
                return $this->convertReflectionPropertyToOneToManyAssociationMetadata(
                    $reflectionProperty,
                    $propertyAnnotations,
                    $metadata
                );

            case isset($propertyAnnotations[Annotation\ManyToMany::class]):
                return $this->convertReflectionPropertyToManyToManyAssociationMetadata(
                    $reflectionProperty,
                    $propertyAnnotations,
                    $metadata
                );

            case isset($propertyAnnotations[Annotation\Embedded::class]):
                return null;

            default:
                return new Mapping\TransientMetadata($reflectionProperty->getName());
        }
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     *
     * @throws Mapping\MappingException
     */
    private function convertReflectionPropertyToFieldMetadata(
        \ReflectionProperty $reflProperty,
        array $propertyAnnotations,
        Mapping\ClassMetadata $metadata
    ) : Mapping\FieldMetadata {
        $className   = $metadata->getClassName();
        $fieldName   = $reflProperty->getName();
        $isVersioned = isset($propertyAnnotations[Annotation\Version::class]);
        $columnAnnot = $propertyAnnotations[Annotation\Column::class];

        if ($columnAnnot->type === null) {
            throw Mapping\MappingException::propertyTypeIsRequired($className, $fieldName);
        }

        $fieldMetadata = $this->convertColumnAnnotationToFieldMetadata($columnAnnot, $fieldName, $isVersioned);

        // Check for Id
        if (isset($propertyAnnotations[Annotation\Id::class])) {
            $fieldMetadata->setPrimaryKey(true);
        }

        // Check for GeneratedValue strategy
        if (isset($propertyAnnotations[Annotation\GeneratedValue::class])) {
            $generatedValueAnnot = $propertyAnnotations[Annotation\GeneratedValue::class];
            $strategy            = strtoupper($generatedValueAnnot->strategy);
            $idGeneratorType     = constant(sprintf('%s::%s', Mapping\GeneratorType::class, $strategy));

            if ($idGeneratorType !== Mapping\GeneratorType::NONE) {
                $idGeneratorDefinition = [];

                // Check for CustomGenerator/SequenceGenerator/TableGenerator definition
                switch (true) {
                    case isset($propertyAnnotations[Annotation\SequenceGenerator::class]):
                        $seqGeneratorAnnot = $propertyAnnotations[Annotation\SequenceGenerator::class];

                        $idGeneratorDefinition = [
                            'sequenceName' => $seqGeneratorAnnot->sequenceName,
                            'allocationSize' => $seqGeneratorAnnot->allocationSize,
                        ];

                        break;

                    case isset($propertyAnnotations[Annotation\CustomIdGenerator::class]):
                        $customGeneratorAnnot = $propertyAnnotations[Annotation\CustomIdGenerator::class];

                        $idGeneratorDefinition = [
                            'class' => $customGeneratorAnnot->class,
                            'arguments' => $customGeneratorAnnot->arguments,
                        ];

                        break;

                    /** @todo If it is not supported, why does this exist? */
                    case isset($propertyAnnotations['Doctrine\ORM\Mapping\TableGenerator']):
                        throw Mapping\MappingException::tableIdGeneratorNotImplemented($className);
                }

                $fieldMetadata->setValueGenerator(new Mapping\ValueGeneratorMetadata($idGeneratorType, $idGeneratorDefinition));
            }
        }

        return $fieldMetadata;
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     *
     * @return Mapping\OneToOneAssociationMetadata
     */
    private function convertReflectionPropertyToOneToOneAssociationMetadata(
        \ReflectionProperty $reflectionProperty,
        array $propertyAnnotations,
        Mapping\ClassMetadata $metadata
    ) {
        $className     = $metadata->getClassName();
        $fieldName     = $reflectionProperty->getName();
        $oneToOneAnnot = $propertyAnnotations[Annotation\OneToOne::class];
        $assocMetadata = new Mapping\OneToOneAssociationMetadata($fieldName);
        $targetEntity  = $oneToOneAnnot->targetEntity;

        $assocMetadata->setTargetEntity($targetEntity);
        $assocMetadata->setCascade($this->getCascade($className, $fieldName, $oneToOneAnnot->cascade));
        $assocMetadata->setOrphanRemoval($oneToOneAnnot->orphanRemoval);
        $assocMetadata->setFetchMode($this->getFetchMode($className, $oneToOneAnnot->fetch));

        if (! empty($oneToOneAnnot->mappedBy)) {
            $assocMetadata->setMappedBy($oneToOneAnnot->mappedBy);
            $assocMetadata->setOwningSide(false);
        }

        if (! empty($oneToOneAnnot->inversedBy)) {
            $assocMetadata->setInversedBy($oneToOneAnnot->inversedBy);
        }

        // Check for Id
        if (isset($propertyAnnotations[Annotation\Id::class])) {
            $assocMetadata->setPrimaryKey(true);
        }

        $this->attachAssociationPropertyCache($propertyAnnotations, $reflectionProperty, $assocMetadata, $metadata);

        // Check for JoinColumn/JoinColumns annotations
        switch (true) {
            case isset($propertyAnnotations[Annotation\JoinColumn::class]):
                $joinColumnAnnot = $propertyAnnotations[Annotation\JoinColumn::class];

                $assocMetadata->addJoinColumn(
                    $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot)
                );

                break;

            case isset($propertyAnnotations[Annotation\JoinColumns::class]):
                $joinColumnsAnnot = $propertyAnnotations[Annotation\JoinColumns::class];

                foreach ($joinColumnsAnnot->value as $joinColumnAnnot) {
                    $assocMetadata->addJoinColumn(
                        $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot)
                    );
                }

                break;
        }

        return $assocMetadata;
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     *
     * @return Mapping\ManyToOneAssociationMetadata
     */
    private function convertReflectionPropertyToManyToOneAssociationMetadata(
        \ReflectionProperty $reflectionProperty,
        array $propertyAnnotations,
        Mapping\ClassMetadata $metadata
    ) {
        $className      = $metadata->getClassName();
        $fieldName      = $reflectionProperty->getName();
        $manyToOneAnnot = $propertyAnnotations[Annotation\ManyToOne::class];
        $assocMetadata  = new Mapping\ManyToOneAssociationMetadata($fieldName);
        $targetEntity   = $manyToOneAnnot->targetEntity;

        $assocMetadata->setTargetEntity($targetEntity);
        $assocMetadata->setCascade($this->getCascade($className, $fieldName, $manyToOneAnnot->cascade));
        $assocMetadata->setFetchMode($this->getFetchMode($className, $manyToOneAnnot->fetch));

        if (! empty($manyToOneAnnot->inversedBy)) {
            $assocMetadata->setInversedBy($manyToOneAnnot->inversedBy);
        }

        // Check for Id
        if (isset($propertyAnnotations[Annotation\Id::class])) {
            $assocMetadata->setPrimaryKey(true);
        }

        $this->attachAssociationPropertyCache($propertyAnnotations, $reflectionProperty, $assocMetadata, $metadata);

        // Check for JoinColumn/JoinColumns annotations
        switch (true) {
            case isset($propertyAnnotations[Annotation\JoinColumn::class]):
                $joinColumnAnnot = $propertyAnnotations[Annotation\JoinColumn::class];

                $assocMetadata->addJoinColumn(
                    $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot)
                );

                break;

            case isset($propertyAnnotations[Annotation\JoinColumns::class]):
                $joinColumnsAnnot = $propertyAnnotations[Annotation\JoinColumns::class];

                foreach ($joinColumnsAnnot->value as $joinColumnAnnot) {
                    $assocMetadata->addJoinColumn(
                        $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot)
                    );
                }

                break;
        }

        return $assocMetadata;
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     *
     * @throws Mapping\MappingException
     */
    private function convertReflectionPropertyToOneToManyAssociationMetadata(
        \ReflectionProperty $reflectionProperty,
        array $propertyAnnotations,
        Mapping\ClassMetadata $metadata
    ) : Mapping\OneToManyAssociationMetadata {
        $className      = $metadata->getClassName();
        $fieldName      = $reflectionProperty->getName();
        $oneToManyAnnot = $propertyAnnotations[Annotation\OneToMany::class];
        $assocMetadata  = new Mapping\OneToManyAssociationMetadata($fieldName);
        $targetEntity   = $oneToManyAnnot->targetEntity;

        $assocMetadata->setTargetEntity($targetEntity);
        $assocMetadata->setCascade($this->getCascade($className, $fieldName, $oneToManyAnnot->cascade));
        $assocMetadata->setOrphanRemoval($oneToManyAnnot->orphanRemoval);
        $assocMetadata->setFetchMode($this->getFetchMode($className, $oneToManyAnnot->fetch));
        $assocMetadata->setOwningSide(false);
        $assocMetadata->setMappedBy($oneToManyAnnot->mappedBy);

        if (! empty($oneToManyAnnot->indexBy)) {
            $assocMetadata->setIndexedBy($oneToManyAnnot->indexBy);
        }

        // Check for OrderBy
        if (isset($propertyAnnotations[Annotation\OrderBy::class])) {
            $orderByAnnot = $propertyAnnotations[Annotation\OrderBy::class];

            $assocMetadata->setOrderBy($orderByAnnot->value);
        }

        // Check for Id
        if (isset($propertyAnnotations[Annotation\Id::class])) {
            throw Mapping\MappingException::illegalToManyIdentifierAssociation($className, $fieldName);
        }

        $this->attachAssociationPropertyCache($propertyAnnotations, $reflectionProperty, $assocMetadata, $metadata);

        return $assocMetadata;
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     *
     * @throws Mapping\MappingException
     */
    private function convertReflectionPropertyToManyToManyAssociationMetadata(
        \ReflectionProperty $reflectionProperty,
        array $propertyAnnotations,
        Mapping\ClassMetadata $metadata
    ) : Mapping\ManyToManyAssociationMetadata {
        $className       = $metadata->getClassName();
        $fieldName       = $reflectionProperty->getName();
        $manyToManyAnnot = $propertyAnnotations[Annotation\ManyToMany::class];
        $assocMetadata   = new Mapping\ManyToManyAssociationMetadata($fieldName);
        $targetEntity    = $manyToManyAnnot->targetEntity;

        $assocMetadata->setTargetEntity($targetEntity);
        $assocMetadata->setCascade($this->getCascade($className, $fieldName, $manyToManyAnnot->cascade));
        $assocMetadata->setOrphanRemoval($manyToManyAnnot->orphanRemoval);
        $assocMetadata->setFetchMode($this->getFetchMode($className, $manyToManyAnnot->fetch));

        if (! empty($manyToManyAnnot->mappedBy)) {
            $assocMetadata->setMappedBy($manyToManyAnnot->mappedBy);
            $assocMetadata->setOwningSide(false);
        }

        if (! empty($manyToManyAnnot->inversedBy)) {
            $assocMetadata->setInversedBy($manyToManyAnnot->inversedBy);
        }

        if (! empty($manyToManyAnnot->indexBy)) {
            $assocMetadata->setIndexedBy($manyToManyAnnot->indexBy);
        }

        // Check for JoinTable
        if (isset($propertyAnnotations[Annotation\JoinTable::class])) {
            $joinTableAnnot    = $propertyAnnotations[Annotation\JoinTable::class];
            $joinTableMetadata = $this->convertJoinTableAnnotationToJoinTableMetadata($joinTableAnnot);

            $assocMetadata->setJoinTable($joinTableMetadata);
        }

        // Check for OrderBy
        if (isset($propertyAnnotations[Annotation\OrderBy::class])) {
            $orderByAnnot = $propertyAnnotations[Annotation\OrderBy::class];

            $assocMetadata->setOrderBy($orderByAnnot->value);
        }

        // Check for Id
        if (isset($propertyAnnotations[Annotation\Id::class])) {
            throw Mapping\MappingException::illegalToManyIdentifierAssociation($className, $fieldName);
        }

        $this->attachAssociationPropertyCache($propertyAnnotations, $reflectionProperty, $assocMetadata, $metadata);

        return $assocMetadata;
    }

    /**
     * Parse the given Column as FieldMetadata
     */
    private function convertColumnAnnotationToFieldMetadata(
        Annotation\Column $columnAnnot,
        string $fieldName,
        bool $isVersioned
    ) : Mapping\FieldMetadata {
        $fieldMetadata = $isVersioned
            ? new Mapping\VersionFieldMetadata($fieldName)
            : new Mapping\FieldMetadata($fieldName)
        ;

        $fieldMetadata->setType(Type::getType($columnAnnot->type));

        if (! empty($columnAnnot->name)) {
            $fieldMetadata->setColumnName($columnAnnot->name);
        }

        if (! empty($columnAnnot->columnDefinition)) {
            $fieldMetadata->setColumnDefinition($columnAnnot->columnDefinition);
        }

        if (! empty($columnAnnot->length)) {
            $fieldMetadata->setLength($columnAnnot->length);
        }

        if ($columnAnnot->options) {
            $fieldMetadata->setOptions($columnAnnot->options);
        }

        $fieldMetadata->setScale($columnAnnot->scale);
        $fieldMetadata->setPrecision($columnAnnot->precision);
        $fieldMetadata->setNullable($columnAnnot->nullable);
        $fieldMetadata->setUnique($columnAnnot->unique);

        return $fieldMetadata;
    }

    /**
     * Parse the given Table as TableMetadata
     */
    private function convertTableAnnotationToTableMetadata(
        Annotation\Table $tableAnnot,
        Mapping\TableMetadata $tableMetadata
    ) : void {
        if (! empty($tableAnnot->name)) {
            $tableMetadata->setName($tableAnnot->name);
        }

        if (! empty($tableAnnot->schema)) {
            $tableMetadata->setSchema($tableAnnot->schema);
        }

        foreach ($tableAnnot->options as $optionName => $optionValue) {
            $tableMetadata->addOption($optionName, $optionValue);
        }

        foreach ($tableAnnot->indexes as $indexAnnot) {
            $tableMetadata->addIndex([
                'name'    => $indexAnnot->name,
                'columns' => $indexAnnot->columns,
                'unique'  => $indexAnnot->unique,
                'options' => $indexAnnot->options,
                'flags'   => $indexAnnot->flags,
            ]);
        }

        foreach ($tableAnnot->uniqueConstraints as $uniqueConstraintAnnot) {
            $tableMetadata->addUniqueConstraint([
                'name'    => $uniqueConstraintAnnot->name,
                'columns' => $uniqueConstraintAnnot->columns,
                'options' => $uniqueConstraintAnnot->options,
                'flags'   => $uniqueConstraintAnnot->flags,
            ]);
        }
    }

    /**
     * Parse the given JoinTable as JoinTableMetadata
     */
    private function convertJoinTableAnnotationToJoinTableMetadata(
        Annotation\JoinTable $joinTableAnnot
    ) : Mapping\JoinTableMetadata {
        $joinTable = new Mapping\JoinTableMetadata();

        if (! empty($joinTableAnnot->name)) {
            $joinTable->setName($joinTableAnnot->name);
        }

        if (! empty($joinTableAnnot->schema)) {
            $joinTable->setSchema($joinTableAnnot->schema);
        }

        foreach ($joinTableAnnot->joinColumns as $joinColumnAnnot) {
            $joinColumn = $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot);

            $joinTable->addJoinColumn($joinColumn);
        }

        foreach ($joinTableAnnot->inverseJoinColumns as $joinColumnAnnot) {
            $joinColumn = $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot);

            $joinTable->addInverseJoinColumn($joinColumn);
        }

        return $joinTable;
    }

    /**
     * Parse the given JoinColumn as JoinColumnMetadata
     */
    private function convertJoinColumnAnnotationToJoinColumnMetadata(
        Annotation\JoinColumn $joinColumnAnnot
    ) : Mapping\JoinColumnMetadata {
        $joinColumn = new Mapping\JoinColumnMetadata();

        // @todo Remove conditionals for name and referencedColumnName once naming strategy is brought into drivers
        if (! empty($joinColumnAnnot->name)) {
            $joinColumn->setColumnName($joinColumnAnnot->name);
        }

        if (! empty($joinColumnAnnot->referencedColumnName)) {
            $joinColumn->setReferencedColumnName($joinColumnAnnot->referencedColumnName);
        }

        $joinColumn->setNullable($joinColumnAnnot->nullable);
        $joinColumn->setUnique($joinColumnAnnot->unique);

        if (! empty($joinColumnAnnot->fieldName)) {
            $joinColumn->setAliasedName($joinColumnAnnot->fieldName);
        }

        if (! empty($joinColumnAnnot->columnDefinition)) {
            $joinColumn->setColumnDefinition($joinColumnAnnot->columnDefinition);
        }

        if ($joinColumnAnnot->onDelete) {
            $joinColumn->setOnDelete(strtoupper($joinColumnAnnot->onDelete));
        }

        return $joinColumn;
    }

    /**
     * Parse the given Cache as CacheMetadata
     *
     * @param string|null $fieldName
     */
    private function convertCacheAnnotationToCacheMetadata(
        Annotation\Cache $cacheAnnot,
        Mapping\ClassMetadata $metadata,
        $fieldName = null
    ) : Mapping\CacheMetadata {
        $baseRegion    = strtolower(str_replace('\\', '_', $metadata->getRootClassName()));
        $defaultRegion = $baseRegion . ($fieldName ? '__' . $fieldName : '');

        $usage  = constant(sprintf('%s::%s', Mapping\CacheUsage::class, $cacheAnnot->usage));
        $region = $cacheAnnot->region ?: $defaultRegion;

        return new Mapping\CacheMetadata($usage, $region);
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     */
    private function attachTable(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) : void {
        $parent = $metadata->getParent();

        if ($parent && $parent->inheritanceType === Mapping\InheritanceType::SINGLE_TABLE) {
            // Handle the case where a middle mapped super class inherits from a single table inheritance tree.
            do {
                if (! $parent->isMappedSuperclass) {
                    $metadata->setTable($parent->table);

                    break;
                }

                $parent = $parent->getParent();
            } while ($parent !== null);

            return;
        }

        $namingStrategy = $metadataBuildingContext->getNamingStrategy();
        $tableMetadata  = new Mapping\TableMetadata();

        $tableMetadata->setName($namingStrategy->classToTableName($metadata->getClassName()));

        // Evaluate @Table annotation
        if (isset($classAnnotations[Annotation\Table::class])) {
            $tableAnnot = $classAnnotations[Annotation\Table::class];

            $this->convertTableAnnotationToTableMetadata($tableAnnot, $tableMetadata);
        }

        $metadata->setTable($tableMetadata);
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     *
     * @throws Mapping\MappingException
     */
    private function attachDiscriminatorColumn(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata
    ) : void {
        $discriminatorColumn = new Mapping\DiscriminatorColumnMetadata();

        $discriminatorColumn->setTableName($metadata->getTableName());
        $discriminatorColumn->setColumnName('dtype');
        $discriminatorColumn->setType(Type::getType('string'));
        $discriminatorColumn->setLength(255);

        // Evaluate DiscriminatorColumn annotation
        if (isset($classAnnotations[Annotation\DiscriminatorColumn::class])) {
            /** @var Annotation\DiscriminatorColumn $discriminatorColumnAnnotation */
            $discriminatorColumnAnnotation = $classAnnotations[Annotation\DiscriminatorColumn::class];
            $typeName                      = ! empty($discriminatorColumnAnnotation->type)
                ? $discriminatorColumnAnnotation->type
                : 'string';

            $discriminatorColumn->setType(Type::getType($typeName));
            $discriminatorColumn->setColumnName($discriminatorColumnAnnotation->name);

            if (! empty($discriminatorColumnAnnotation->columnDefinition)) {
                $discriminatorColumn->setColumnDefinition($discriminatorColumnAnnotation->columnDefinition);
            }

            if (! empty($discriminatorColumnAnnotation->length)) {
                $discriminatorColumn->setLength($discriminatorColumnAnnotation->length);
            }
        }

        $metadata->setDiscriminatorColumn($discriminatorColumn);

        // Evaluate DiscriminatorMap annotation
        if (isset($classAnnotations[Annotation\DiscriminatorMap::class])) {
            $discriminatorMapAnnotation = $classAnnotations[Annotation\DiscriminatorMap::class];
            $discriminatorMap           = $discriminatorMapAnnotation->value;

            $metadata->setDiscriminatorMap($discriminatorMap);
        }
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     */
    private function attachLifecycleCallbacks(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata
    ) : void {
        // Evaluate @HasLifecycleCallbacks annotation
        if (isset($classAnnotations[Annotation\HasLifecycleCallbacks::class])) {
            /** @var \ReflectionMethod $method */
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($this->getMethodCallbacks($method) as $callback) {
                    $metadata->addLifecycleCallback($method->getName(), $callback);
                }
            }
        }
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     *
     * @throws \ReflectionException
     * @throws Mapping\MappingException
     */
    private function attachEntityListeners(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata
    ) : void {
        // Evaluate @EntityListeners annotation
        if (isset($classAnnotations[Annotation\EntityListeners::class])) {
            /** @var Annotation\EntityListeners $entityListenersAnnot */
            $entityListenersAnnot = $classAnnotations[Annotation\EntityListeners::class];

            foreach ($entityListenersAnnot->value as $listenerClassName) {
                if (! class_exists($listenerClassName)) {
                    throw Mapping\MappingException::entityListenerClassNotFound(
                        $listenerClassName,
                        $metadata->getClassName()
                    );
                }

                $listenerClass = new \ReflectionClass($listenerClassName);

                /** @var \ReflectionMethod $method */
                foreach ($listenerClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    foreach ($this->getMethodCallbacks($method) as $callback) {
                        $metadata->addEntityListener($callback, $listenerClassName, $method->getName());
                    }
                }
            }
        }
    }

    /**
     * @param Annotation\Annotation[] $classAnnotations
     *
     * @throws Mapping\MappingException
     */
    private function attachPropertyOverrides(
        array $classAnnotations,
        \ReflectionClass $reflectionClass,
        Mapping\ClassMetadata $metadata
    ) : void {
        // Evaluate AssociationOverrides annotation
        if (isset($classAnnotations[Annotation\AssociationOverrides::class])) {
            $associationOverridesAnnot = $classAnnotations[Annotation\AssociationOverrides::class];

            foreach ($associationOverridesAnnot->value as $associationOverride) {
                $fieldName = $associationOverride->name;
                $property  = $metadata->getProperty($fieldName);

                if (! $property) {
                    throw Mapping\MappingException::invalidOverrideFieldName($metadata->getClassName(), $fieldName);
                }

                $existingClass = get_class($property);
                $override      = new $existingClass($fieldName);

                // Check for JoinColumn/JoinColumns annotations
                if ($associationOverride->joinColumns) {
                    $joinColumns = [];

                    foreach ($associationOverride->joinColumns as $joinColumnAnnot) {
                        $joinColumns[] = $this->convertJoinColumnAnnotationToJoinColumnMetadata($joinColumnAnnot);
                    }

                    $override->setJoinColumns($joinColumns);
                }

                // Check for JoinTable annotations
                if ($associationOverride->joinTable) {
                    $joinTableAnnot    = $associationOverride->joinTable;
                    $joinTableMetadata = $this->convertJoinTableAnnotationToJoinTableMetadata($joinTableAnnot);

                    $override->setJoinTable($joinTableMetadata);
                }

                // Check for inversedBy
                if ($associationOverride->inversedBy) {
                    $override->setInversedBy($associationOverride->inversedBy);
                }

                // Check for fetch
                if ($associationOverride->fetch) {
                    $override->setFetchMode(
                        constant(Mapping\FetchMode::class . '::' . $associationOverride->fetch)
                    );
                }

                $metadata->setPropertyOverride($override);
            }
        }

        // Evaluate AttributeOverrides annotation
        if (isset($classAnnotations[Annotation\AttributeOverrides::class])) {
            $attributeOverridesAnnot = $classAnnotations[Annotation\AttributeOverrides::class];

            foreach ($attributeOverridesAnnot->value as $attributeOverrideAnnot) {
                $fieldMetadata = $this->convertColumnAnnotationToFieldMetadata(
                    $attributeOverrideAnnot->column,
                    $attributeOverrideAnnot->name,
                    false
                );

                $metadata->setPropertyOverride($fieldMetadata);
            }
        }
    }

    /**
     * @param Annotation\Annotation[] $propertyAnnotations
     */
    private function attachAssociationPropertyCache(
        array $propertyAnnotations,
        \ReflectionProperty $reflectionProperty,
        Mapping\AssociationMetadata $assocMetadata,
        Mapping\ClassMetadata $metadata
    ) : void {
        // Check for Cache
        if (isset($propertyAnnotations[Annotation\Cache::class])) {
            $cacheAnnot    = $propertyAnnotations[Annotation\Cache::class];
            $cacheMetadata = $this->convertCacheAnnotationToCacheMetadata(
                $cacheAnnot,
                $metadata,
                $reflectionProperty->getName()
            );

            $assocMetadata->setCache($cacheMetadata);
        }
    }

    /**
     * Attempts to resolve the cascade modes.
     *
     * @param string   $className        The class name.
     * @param string   $fieldName        The field name.
     * @param string[] $originalCascades The original unprocessed field cascades.
     *
     * @return string[] The processed field cascades.
     *
     * @throws Mapping\MappingException If a cascade option is not valid.
     */
    private function getCascade(string $className, string $fieldName, array $originalCascades)
    {
        $cascadeTypes = ['remove', 'persist', 'refresh'];
        $cascades     = array_map('strtolower', $originalCascades);

        if (in_array('all', $cascades, true)) {
            $cascades = $cascadeTypes;
        }

        if (count($cascades) !== count(array_intersect($cascades, $cascadeTypes))) {
            $diffCascades = array_diff($cascades, array_intersect($cascades, $cascadeTypes));

            throw Mapping\MappingException::invalidCascadeOption($diffCascades, $className, $fieldName);
        }

        return $cascades;
    }

    /**
     * Attempts to resolve the fetch mode.
     *
     * @param string $className The class name.
     * @param string $fetchMode The fetch mode.
     *
     * @return string The fetch mode as defined in ClassMetadata.
     *
     * @throws Mapping\MappingException If the fetch mode is not valid.
     */
    private function getFetchMode($className, $fetchMode) : string
    {
        $fetchModeConstant = sprintf('%s::%s', Mapping\FetchMode::class, $fetchMode);

        if (! defined($fetchModeConstant)) {
            throw Mapping\MappingException::invalidFetchMode($className, $fetchMode);
        }

        return constant($fetchModeConstant);
    }

    /**
     * Parses the given method.
     *
     * @return string[]
     */
    private function getMethodCallbacks(\ReflectionMethod $method) : array
    {
        $annotations = $this->getMethodAnnotations($method);
        $events      = [
            Events::prePersist  => Annotation\PrePersist::class,
            Events::postPersist => Annotation\PostPersist::class,
            Events::preUpdate   => Annotation\PreUpdate::class,
            Events::postUpdate  => Annotation\PostUpdate::class,
            Events::preRemove   => Annotation\PreRemove::class,
            Events::postRemove  => Annotation\PostRemove::class,
            Events::postLoad    => Annotation\PostLoad::class,
            Events::preFlush    => Annotation\PreFlush::class,
        ];

        // Check for callbacks
        $callbacks = [];

        foreach ($events as $eventName => $annotationClassName) {
            if (isset($annotations[$annotationClassName]) || $method->getName() === $eventName) {
                $callbacks[] = $eventName;
            }
        }

        return $callbacks;
    }

    /**
     * @return Annotation\Annotation[]
     */
    private function getClassAnnotations(\ReflectionClass $reflectionClass) : array
    {
        $classAnnotations = $this->reader->getClassAnnotations($reflectionClass);

        foreach ($classAnnotations as $key => $annot) {
            if (! is_numeric($key)) {
                continue;
            }

            $classAnnotations[get_class($annot)] = $annot;
        }

        return $classAnnotations;
    }

    /**
     * @return Annotation\Annotation[]
     */
    private function getPropertyAnnotations(\ReflectionProperty $reflectionProperty) : array
    {
        $propertyAnnotations = $this->reader->getPropertyAnnotations($reflectionProperty);

        foreach ($propertyAnnotations as $key => $annot) {
            if (! is_numeric($key)) {
                continue;
            }

            $propertyAnnotations[get_class($annot)] = $annot;
        }

        return $propertyAnnotations;
    }

    /**
     * @return Annotation\Annotation[]
     */
    private function getMethodAnnotations(\ReflectionMethod $reflectionMethod) : array
    {
        $methodAnnotations = $this->reader->getMethodAnnotations($reflectionMethod);

        foreach ($methodAnnotations as $key => $annot) {
            if (! is_numeric($key)) {
                continue;
            }

            $methodAnnotations[get_class($annot)] = $annot;
        }

        return $methodAnnotations;
    }

    /**
     * Factory method for the Annotation Driver.
     *
     * @param string|string[] $paths
     *
     * @return AnnotationDriver
     */
    public static function create($paths = [], ?AnnotationReader $reader = null)
    {
        if ($reader === null) {
            $reader = new AnnotationReader();
        }

        return new self($reader, $paths);
    }
}
