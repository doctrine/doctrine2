<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\Builder\EntityListenerBuilder;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\AnnotationDriver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use function assert;
use function class_exists;
use function constant;
use function defined;
use function get_class;

class AttributeDriver extends AnnotationDriver
{
    /** @var array<string,int> */
    // @phpcs:ignore
    protected $entityAnnotationClasses = [
        Mapping\Entity::class => 1,
        Mapping\MappedSuperclass::class => 2,
    ];

    /**
     * @param array<string> $paths
     */
    public function __construct(array $paths)
    {
        parent::__construct(new AttributeReader(), $paths);
    }

    /**
     * {@inheritDoc}
     */
    public function isTransient($className)
    {
        $classAnnotations = $this->reader->getClassAnnotations(new ReflectionClass($className));

        foreach ($classAnnotations as $a) {
            $annot = $a instanceof RepeatableAttributeCollection ? $a[0] : $a;
            if (isset($this->entityAnnotationClasses[get_class($annot)])) {
                return false;
            }
        }

        return true;
    }

    public function loadMetadataForClass($className, ClassMetadata $metadata): void
    {
        assert($metadata instanceof ClassMetadataInfo);

        $reflectionClass = $metadata->getReflectionClass();

        $classAttributes = $this->reader->getClassAnnotations($reflectionClass);

        // Evaluate Entity annotation
        if (isset($classAttributes[Mapping\Entity::class])) {
            $entityAttribute = $classAttributes[Mapping\Entity::class];
            if ($entityAttribute->repositoryClass !== null) {
                $metadata->setCustomRepositoryClass($entityAttribute->repositoryClass);
            }

            if ($entityAttribute->readOnly) {
                $metadata->markReadOnly();
            }
        } elseif (isset($classAttributes[Mapping\MappedSuperclass::class])) {
            $mappedSuperclassAttribute = $classAttributes[Mapping\MappedSuperclass::class];

            $metadata->setCustomRepositoryClass($mappedSuperclassAttribute->repositoryClass);
            $metadata->isMappedSuperclass = true;
        } elseif (isset($classAttributes[Mapping\Embeddable::class])) {
            $metadata->isEmbeddedClass = true;
        } else {
            throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }

        $primaryTable = [];

        if (isset($classAttributes[Mapping\Table::class])) {
            $tableAnnot             = $classAttributes[Mapping\Table::class];
            $primaryTable['name']   = $tableAnnot->name;
            $primaryTable['schema'] = $tableAnnot->schema;

            if ($tableAnnot->options) {
                $primaryTable['options'] = $tableAnnot->options;
            }
        }

        if (isset($classAttributes[Mapping\Index::class])) {
            foreach ($classAttributes[Mapping\Index::class] as $idx => $indexAnnot) {
                $index = [];

                if (! empty($indexAnnot->columns)) {
                    $index['columns'] = $indexAnnot->columns;
                }

                if (! empty($indexAnnot->fields)) {
                    $index['fields'] = $indexAnnot->fields;
                }

                if (
                    isset($index['columns'], $index['fields'])
                    || (
                        ! isset($index['columns'])
                        && ! isset($index['fields'])
                    )
                ) {
                    throw MappingException::invalidIndexConfiguration(
                        $className,
                        (string) ($indexAnnot->name ?? $idx)
                    );
                }

                if (! empty($indexAnnot->flags)) {
                    $index['flags'] = $indexAnnot->flags;
                }

                if (! empty($indexAnnot->options)) {
                    $index['options'] = $indexAnnot->options;
                }

                if (! empty($indexAnnot->name)) {
                    $primaryTable['indexes'][$indexAnnot->name] = $index;
                } else {
                    $primaryTable['indexes'][] = $index;
                }
            }
        }

        if (isset($classAttributes[Mapping\UniqueConstraint::class])) {
            foreach ($classAttributes[Mapping\UniqueConstraint::class] as $idx => $uniqueConstraintAnnot) {
                $uniqueConstraint = [];

                if (! empty($uniqueConstraintAnnot->columns)) {
                    $uniqueConstraint['columns'] = $uniqueConstraintAnnot->columns;
                }

                if (! empty($uniqueConstraintAnnot->fields)) {
                    $uniqueConstraint['fields'] = $uniqueConstraintAnnot->fields;
                }

                if (
                    isset($uniqueConstraint['columns'], $uniqueConstraint['fields'])
                    || (
                        ! isset($uniqueConstraint['columns'])
                        && ! isset($uniqueConstraint['fields'])
                    )
                ) {
                    throw MappingException::invalidUniqueConstraintConfiguration(
                        $className,
                        (string) ($uniqueConstraintAnnot->name ?? $idx)
                    );
                }

                if (! empty($uniqueConstraintAnnot->options)) {
                    $uniqueConstraint['options'] = $uniqueConstraintAnnot->options;
                }

                if (! empty($uniqueConstraintAnnot->name)) {
                    $primaryTable['uniqueConstraints'][$uniqueConstraintAnnot->name] = $uniqueConstraint;
                } else {
                    $primaryTable['uniqueConstraints'][] = $uniqueConstraint;
                }
            }
        }

        $metadata->setPrimaryTable($primaryTable);

        // Evaluate @Cache annotation
        if (isset($classAttributes[Mapping\Cache::class])) {
            $cacheAttribute = $classAttributes[Mapping\Cache::class];
            $cacheMap       = [
                'region' => $cacheAttribute->region,
                'usage'  => constant('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $cacheAttribute->usage),
            ];

            $metadata->enableCache($cacheMap);
        }

        // Evaluate InheritanceType annotation
        if (isset($classAttributes[Mapping\InheritanceType::class])) {
            $inheritanceTypeAttribute = $classAttributes[Mapping\InheritanceType::class];

            $metadata->setInheritanceType(
                constant('Doctrine\ORM\Mapping\ClassMetadata::INHERITANCE_TYPE_' . $inheritanceTypeAttribute->value)
            );

            if ($metadata->inheritanceType !== Mapping\ClassMetadata::INHERITANCE_TYPE_NONE) {
                // Evaluate DiscriminatorColumn annotation
                if (isset($classAttributes[Mapping\DiscriminatorColumn::class])) {
                    $discrColumnAttribute = $classAttributes[Mapping\DiscriminatorColumn::class];

                    $metadata->setDiscriminatorColumn(
                        [
                            'name'             => $discrColumnAttribute->name,
                            'type'             => $discrColumnAttribute->type ?: 'string',
                            'length'           => $discrColumnAttribute->length ?: 255,
                            'columnDefinition' => $discrColumnAttribute->columnDefinition,
                        ]
                    );
                } else {
                    $metadata->setDiscriminatorColumn(['name' => 'dtype', 'type' => 'string', 'length' => 255]);
                }

                // Evaluate DiscriminatorMap annotation
                if (isset($classAttributes[Mapping\DiscriminatorMap::class])) {
                    $discrMapAttribute = $classAttributes[Mapping\DiscriminatorMap::class];
                    $metadata->setDiscriminatorMap($discrMapAttribute->value);
                }
            }
        }

        // Evaluate DoctrineChangeTrackingPolicy annotation
        if (isset($classAttributes[Mapping\ChangeTrackingPolicy::class])) {
            $changeTrackingAttribute = $classAttributes[Mapping\ChangeTrackingPolicy::class];
            $metadata->setChangeTrackingPolicy(constant('Doctrine\ORM\Mapping\ClassMetadata::CHANGETRACKING_' . $changeTrackingAttribute->value));
        }

        foreach ($reflectionClass->getProperties() as $property) {
            assert($property instanceof ReflectionProperty);
            if (
                $metadata->isMappedSuperclass && ! $property->isPrivate()
                ||
                $metadata->isInheritedField($property->name)
                ||
                $metadata->isInheritedAssociation($property->name)
                ||
                $metadata->isInheritedEmbeddedClass($property->name)
            ) {
                continue;
            }

            $mapping              = [];
            $mapping['fieldName'] = $property->getName();

            // Evaluate @Cache annotation
            $cacheAttribute = $this->reader->getPropertyAnnotation($property, Mapping\Cache::class);
            if ($cacheAttribute !== null) {
                assert($cacheAttribute instanceof Mapping\Cache);

                $mapping['cache'] = $metadata->getAssociationCacheDefaults(
                    $mapping['fieldName'],
                    [
                        'usage'  => (int) constant('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $cacheAttribute->usage),
                        'region' => $cacheAttribute->region,
                    ]
                );
            }

            // Check for JoinColumn/JoinColumns annotations
            $joinColumns = [];

            $joinColumnAttributes = $this->reader->getPropertyAnnotation($property, Mapping\JoinColumn::class);

            foreach ($joinColumnAttributes as $joinColumnAttribute) {
                $joinColumns[] = $this->joinColumnToArray($joinColumnAttribute);
            }

            // Field can only be attributed with one of:
            // Column, OneToOne, OneToMany, ManyToOne, ManyToMany, Embedded
            $columnAttribute     = $this->reader->getPropertyAnnotation($property, Mapping\Column::class);
            $oneToOneAttribute   = $this->reader->getPropertyAnnotation($property, Mapping\OneToOne::class);
            $oneToManyAttribute  = $this->reader->getPropertyAnnotation($property, Mapping\OneToMany::class);
            $manyToOneAttribute  = $this->reader->getPropertyAnnotation($property, Mapping\ManyToOne::class);
            $manyToManyAttribute = $this->reader->getPropertyAnnotation($property, Mapping\ManyToMany::class);
            $embeddedAttribute   = $this->reader->getPropertyAnnotation($property, Mapping\Embedded::class);

            if ($columnAttribute !== null) {
                $mapping = $this->columnToArray($property->getName(), $columnAttribute);

                if ($this->reader->getPropertyAnnotation($property, Mapping\Id::class)) {
                    $mapping['id'] = true;
                }

                $generatedValueAttribute = $this->reader->getPropertyAnnotation($property, Mapping\GeneratedValue::class);

                if ($generatedValueAttribute !== null) {
                    $metadata->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_' . $generatedValueAttribute->strategy));
                }

                if ($this->reader->getPropertyAnnotation($property, Mapping\Version::class)) {
                    $metadata->setVersionMapping($mapping);
                }

                $metadata->mapField($mapping);

                // Check for SequenceGenerator/TableGenerator definition
                $seqGeneratorAttribute    = $this->reader->getPropertyAnnotation($property, Mapping\SequenceGenerator::class);
                $customGeneratorAttribute = $this->reader->getPropertyAnnotation($property, Mapping\CustomIdGenerator::class);

                if ($seqGeneratorAttribute !== null) {
                    $metadata->setSequenceGeneratorDefinition(
                        [
                            'sequenceName' => $seqGeneratorAttribute->sequenceName,
                            'allocationSize' => $seqGeneratorAttribute->allocationSize,
                            'initialValue' => $seqGeneratorAttribute->initialValue,
                        ]
                    );
                } elseif ($customGeneratorAttribute !== null) {
                    $metadata->setCustomGeneratorDefinition(
                        [
                            'class' => $customGeneratorAttribute->class,
                        ]
                    );
                }
            } elseif ($oneToOneAttribute !== null) {
                if ($this->reader->getPropertyAnnotation($property, Mapping\Id::class)) {
                    $mapping['id'] = true;
                }

                $mapping['targetEntity']  = $oneToOneAttribute->targetEntity;
                $mapping['joinColumns']   = $joinColumns;
                $mapping['mappedBy']      = $oneToOneAttribute->mappedBy;
                $mapping['inversedBy']    = $oneToOneAttribute->inversedBy;
                $mapping['cascade']       = $oneToOneAttribute->cascade;
                $mapping['orphanRemoval'] = $oneToOneAttribute->orphanRemoval;
                $mapping['fetch']         = $this->getFetchMode($className, $oneToOneAttribute->fetch);
                $metadata->mapOneToOne($mapping);
            } elseif ($oneToManyAttribute !== null) {
                $mapping['mappedBy']      = $oneToManyAttribute->mappedBy;
                $mapping['targetEntity']  = $oneToManyAttribute->targetEntity;
                $mapping['cascade']       = $oneToManyAttribute->cascade;
                $mapping['indexBy']       = $oneToManyAttribute->indexBy;
                $mapping['orphanRemoval'] = $oneToManyAttribute->orphanRemoval;
                $mapping['fetch']         = $this->getFetchMode($className, $oneToManyAttribute->fetch);

                $orderByAttribute = $this->reader->getPropertyAnnotation($property, Mapping\OrderBy::class);

                if ($orderByAttribute !== null) {
                    $mapping['orderBy'] = $orderByAttribute->value;
                }

                $metadata->mapOneToMany($mapping);
            } elseif ($manyToOneAttribute !== null) {
                $idAttribute = $this->reader->getPropertyAnnotation($property, Mapping\Id::class);

                if ($idAttribute !== null) {
                    $mapping['id'] = true;
                }

                $mapping['joinColumns']  = $joinColumns;
                $mapping['cascade']      = $manyToOneAttribute->cascade;
                $mapping['inversedBy']   = $manyToOneAttribute->inversedBy;
                $mapping['targetEntity'] = $manyToOneAttribute->targetEntity;
                $mapping['fetch']        = $this->getFetchMode($className, $manyToOneAttribute->fetch);
                $metadata->mapManyToOne($mapping);
            } elseif ($manyToManyAttribute !== null) {
                $joinTable          = [];
                $joinTableAttribute = $this->reader->getPropertyAnnotation($property, Mapping\JoinTable::class);

                if ($joinTableAttribute !== null) {
                    $joinTable = [
                        'name' => $joinTableAttribute->name,
                        'schema' => $joinTableAttribute->schema,
                    ];
                }

                foreach ($this->reader->getPropertyAnnotation($property, Mapping\JoinColumn::class) as $joinColumn) {
                    $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumn);
                }

                foreach ($this->reader->getPropertyAnnotation($property, Mapping\InverseJoinColumn::class) as $joinColumn) {
                    $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumn);
                }

                $mapping['joinTable']     = $joinTable;
                $mapping['targetEntity']  = $manyToManyAttribute->targetEntity;
                $mapping['mappedBy']      = $manyToManyAttribute->mappedBy;
                $mapping['inversedBy']    = $manyToManyAttribute->inversedBy;
                $mapping['cascade']       = $manyToManyAttribute->cascade;
                $mapping['indexBy']       = $manyToManyAttribute->indexBy;
                $mapping['ignoreDuplicates'] = $manyToManyAttribute->ignoreDuplicates;
                $mapping['orphanRemoval'] = $manyToManyAttribute->orphanRemoval;
                $mapping['fetch']         = $this->getFetchMode($className, $manyToManyAttribute->fetch);

                $orderByAttribute = $this->reader->getPropertyAnnotation($property, Mapping\OrderBy::class);

                if ($orderByAttribute !== null) {
                    $mapping['orderBy'] = $orderByAttribute->value;
                }

                $metadata->mapManyToMany($mapping);
            } elseif ($embeddedAttribute !== null) {
                $mapping['class']        = $embeddedAttribute->class;
                $mapping['columnPrefix'] = $embeddedAttribute->columnPrefix;

                $metadata->mapEmbedded($mapping);
            }
        }

        // Evaluate AttributeOverrides annotation
        if (isset($classAttributes[Mapping\AttributeOverride::class])) {
            foreach ($classAttributes[Mapping\AttributeOverride::class] as $attributeOverrideAttribute) {
                $attributeOverride = $this->columnToArray($attributeOverrideAttribute->name, $attributeOverrideAttribute->column);

                $metadata->setAttributeOverride($attributeOverrideAttribute->name, $attributeOverride);
            }
        }

        // Evaluate EntityListeners annotation
        if (isset($classAttributes[Mapping\EntityListeners::class])) {
            $entityListenersAttribute = $classAttributes[Mapping\EntityListeners::class];

            foreach ($entityListenersAttribute->value as $item) {
                $listenerClassName = $metadata->fullyQualifiedClassName($item);

                if (! class_exists($listenerClassName)) {
                    throw MappingException::entityListenerClassNotFound($listenerClassName, $className);
                }

                $hasMapping    = false;
                $listenerClass = new ReflectionClass($listenerClassName);

                foreach ($listenerClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    assert($method instanceof ReflectionMethod);
                    // find method callbacks.
                    $callbacks  = $this->getMethodCallbacks($method);
                    $hasMapping = $hasMapping ?: ! empty($callbacks);

                    foreach ($callbacks as $value) {
                        $metadata->addEntityListener($value[1], $listenerClassName, $value[0]);
                    }
                }

                // Evaluate the listener using naming convention.
                if (! $hasMapping) {
                    EntityListenerBuilder::bindEntityListener($metadata, $listenerClassName);
                }
            }
        }

        // Evaluate @HasLifecycleCallbacks annotation
        if (isset($classAttributes[Mapping\HasLifecycleCallbacks::class])) {
            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                assert($method instanceof ReflectionMethod);
                foreach ($this->getMethodCallbacks($method) as $value) {
                    $metadata->addLifecycleCallback($value[0], $value[1]);
                }
            }
        }
    }

    /**
     * Attempts to resolve the fetch mode.
     *
     * @param string $className The class name.
     * @param string $fetchMode The fetch mode.
     *
     * @return int The fetch mode as defined in ClassMetadata.
     *
     * @throws MappingException If the fetch mode is not valid.
     */
    private function getFetchMode(string $className, string $fetchMode): int
    {
        if (! defined('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $fetchMode)) {
            throw MappingException::invalidFetchMode($className, $fetchMode);
        }

        return constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $fetchMode);
    }

    /**
     * Parses the given method.
     *
     * @return callable[]
     */
    private function getMethodCallbacks(ReflectionMethod $method): array
    {
        $callbacks  = [];
        $attributes = $this->reader->getMethodAnnotations($method);

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Mapping\PrePersist) {
                $callbacks[] = [$method->name, Events::prePersist];
            }

            if ($attribute instanceof Mapping\PostPersist) {
                $callbacks[] = [$method->name, Events::postPersist];
            }

            if ($attribute instanceof Mapping\PreUpdate) {
                $callbacks[] = [$method->name, Events::preUpdate];
            }

            if ($attribute instanceof Mapping\PostUpdate) {
                $callbacks[] = [$method->name, Events::postUpdate];
            }

            if ($attribute instanceof Mapping\PreRemove) {
                $callbacks[] = [$method->name, Events::preRemove];
            }

            if ($attribute instanceof Mapping\PostRemove) {
                $callbacks[] = [$method->name, Events::postRemove];
            }

            if ($attribute instanceof Mapping\PostLoad) {
                $callbacks[] = [$method->name, Events::postLoad];
            }

            if ($attribute instanceof Mapping\PreFlush) {
                $callbacks[] = [$method->name, Events::preFlush];
            }
        }

        return $callbacks;
    }

    /**
     * Parse the given JoinColumn as array
     *
     * @param Mapping\JoinColumn|Mapping\InverseJoinColumn $joinColumn
     *
     * @return mixed[]
     * @psalm-return array{
     *                   name: string,
     *                   unique: bool,
     *                   nullable: bool,
     *                   onDelete: mixed,
     *                   columnDefinition: string,
     *                   referencedColumnName: string
     *               }
     */
    private function joinColumnToArray($joinColumn): array
    {
        return [
            'name' => $joinColumn->name,
            'unique' => $joinColumn->unique,
            'nullable' => $joinColumn->nullable,
            'onDelete' => $joinColumn->onDelete,
            'columnDefinition' => $joinColumn->columnDefinition,
            'referencedColumnName' => $joinColumn->referencedColumnName,
        ];
    }

    /**
     * Parse the given Column as array
     *
     * @return mixed[]
     * @psalm-return array{
     *                   fieldName: string,
     *                   type: mixed,
     *                   scale: int,
     *                   length: int,
     *                   unique: bool,
     *                   nullable: bool,
     *                   precision: int,
     *                   options?: mixed[],
     *                   columnName?: string,
     *                   columnDefinition?: string
     *               }
     */
    private function columnToArray(string $fieldName, Mapping\Column $column): array
    {
        $mapping = [
            'fieldName' => $fieldName,
            'type'      => $column->type,
            'scale'     => $column->scale,
            'length'    => $column->length,
            'unique'    => $column->unique,
            'nullable'  => $column->nullable,
            'precision' => $column->precision,
        ];

        if ($column->options) {
            $mapping['options'] = $column->options;
        }

        if (isset($column->name)) {
            $mapping['columnName'] = $column->name;
        }

        if (isset($column->columnDefinition)) {
            $mapping['columnDefinition'] = $column->columnDefinition;
        }

        return $mapping;
    }
}
