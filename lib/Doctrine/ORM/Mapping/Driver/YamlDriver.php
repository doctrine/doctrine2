<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Mapping\Builder\EntityListenerBuilder;
use Doctrine\ORM\Mapping\ClassMetadata as Metadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\FileDriver;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

use function array_map;
use function constant;
use function defined;
use function explode;
use function file_get_contents;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;
use function strtoupper;
use function substr;

/**
 * The YamlDriver reads the mapping metadata from yaml schema files.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 */
class YamlDriver extends FileDriver
{
    public const DEFAULT_FILE_EXTENSION = '.dcm.yml';

    /**
     * {@inheritDoc}
     */
    public function __construct($locator, $fileExtension = self::DEFAULT_FILE_EXTENSION)
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8465',
            'YAML mapping driver is deprecated and will be removed in Doctrine ORM 3.0, please migrate to annotation or XML driver.'
        );

        parent::__construct($locator, $fileExtension);
    }

    /**
     * {@inheritDoc}
     */
    public function loadMetadataForClass($className, ClassMetadata $metadata)
    {
        $element = $this->getElement($className);

        if ($element['type'] === 'entity') {
            if (isset($element['repositoryClass'])) {
                $metadata->setCustomRepositoryClass($element['repositoryClass']);
            }

            if (isset($element['readOnly']) && $element['readOnly'] === true) {
                $metadata->markReadOnly();
            }
        } elseif ($element['type'] === 'mappedSuperclass') {
            $metadata->setCustomRepositoryClass(
                $element['repositoryClass'] ?? null
            );
            $metadata->isMappedSuperclass = true;
        } elseif ($element['type'] === 'embeddable') {
            $metadata->isEmbeddedClass = true;
        } else {
            throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }

        // Evaluate root level properties
        $primaryTable = [];

        if (isset($element['table'])) {
            $primaryTable['name'] = $element['table'];
        }

        if (isset($element['schema'])) {
            $primaryTable['schema'] = $element['schema'];
        }

        // Evaluate second level cache
        if (isset($element['cache'])) {
            $metadata->enableCache($this->cacheToArray($element['cache']));
        }

        $metadata->setPrimaryTable($primaryTable);

        // Evaluate named queries
        if (isset($element['namedQueries'])) {
            foreach ($element['namedQueries'] as $name => $queryMapping) {
                if (is_string($queryMapping)) {
                    $queryMapping = ['query' => $queryMapping];
                }

                if (! isset($queryMapping['name'])) {
                    $queryMapping['name'] = $name;
                }

                $metadata->addNamedQuery($queryMapping);
            }
        }

        // Evaluate named native queries
        if (isset($element['namedNativeQueries'])) {
            foreach ($element['namedNativeQueries'] as $name => $mappingElement) {
                if (! isset($mappingElement['name'])) {
                    $mappingElement['name'] = $name;
                }

                $metadata->addNamedNativeQuery(
                    [
                        'name'              => $mappingElement['name'],
                        'query'             => $mappingElement['query'] ?? null,
                        'resultClass'       => $mappingElement['resultClass'] ?? null,
                        'resultSetMapping'  => $mappingElement['resultSetMapping'] ?? null,
                    ]
                );
            }
        }

        // Evaluate sql result set mappings
        if (isset($element['sqlResultSetMappings'])) {
            foreach ($element['sqlResultSetMappings'] as $name => $resultSetMapping) {
                if (! isset($resultSetMapping['name'])) {
                    $resultSetMapping['name'] = $name;
                }

                $entities = [];
                $columns  = [];
                if (isset($resultSetMapping['entityResult'])) {
                    foreach ($resultSetMapping['entityResult'] as $entityResultElement) {
                        $entityResult = [
                            'fields'                => [],
                            'entityClass'           => $entityResultElement['entityClass'] ?? null,
                            'discriminatorColumn'   => $entityResultElement['discriminatorColumn'] ?? null,
                        ];

                        if (isset($entityResultElement['fieldResult'])) {
                            foreach ($entityResultElement['fieldResult'] as $fieldResultElement) {
                                $entityResult['fields'][] = [
                                    'name'      => $fieldResultElement['name'] ?? null,
                                    'column'    => $fieldResultElement['column'] ?? null,
                                ];
                            }
                        }

                        $entities[] = $entityResult;
                    }
                }

                if (isset($resultSetMapping['columnResult'])) {
                    foreach ($resultSetMapping['columnResult'] as $columnResultAnnot) {
                        $columns[] = [
                            'name' => $columnResultAnnot['name'] ?? null,
                        ];
                    }
                }

                $metadata->addSqlResultSetMapping(
                    [
                        'name'          => $resultSetMapping['name'],
                        'entities'      => $entities,
                        'columns'       => $columns,
                    ]
                );
            }
        }

        if (isset($element['inheritanceType'])) {
            $metadata->setInheritanceType(constant('Doctrine\ORM\Mapping\ClassMetadata::INHERITANCE_TYPE_' . strtoupper($element['inheritanceType'])));

            if ($metadata->inheritanceType !== Metadata::INHERITANCE_TYPE_NONE) {
                // Evaluate discriminatorColumn
                if (isset($element['discriminatorColumn'])) {
                    $discrColumn = $element['discriminatorColumn'];
                    $metadata->setDiscriminatorColumn(
                        [
                            'name' => isset($discrColumn['name']) ? (string) $discrColumn['name'] : null,
                            'type' => isset($discrColumn['type']) ? (string) $discrColumn['type'] : 'string',
                            'length' => isset($discrColumn['length']) ? (string) $discrColumn['length'] : 255,
                            'columnDefinition' => isset($discrColumn['columnDefinition']) ? (string) $discrColumn['columnDefinition'] : null,
                        ]
                    );
                } else {
                    $metadata->setDiscriminatorColumn(['name' => 'dtype', 'type' => 'string', 'length' => 255]);
                }

                // Evaluate discriminatorMap
                if (isset($element['discriminatorMap'])) {
                    $metadata->setDiscriminatorMap($element['discriminatorMap']);
                }
            }
        }

        // Evaluate changeTrackingPolicy
        if (isset($element['changeTrackingPolicy'])) {
            $metadata->setChangeTrackingPolicy(constant('Doctrine\ORM\Mapping\ClassMetadata::CHANGETRACKING_'
                . strtoupper($element['changeTrackingPolicy'])));
        }

        // Evaluate indexes
        if (isset($element['indexes'])) {
            foreach ($element['indexes'] as $name => $indexYml) {
                if (! isset($indexYml['name'])) {
                    $indexYml['name'] = $name;
                }

                $index = [];

                if (isset($indexYml['columns'])) {
                    if (is_string($indexYml['columns'])) {
                        $index['columns'] = array_map('trim', explode(',', $indexYml['columns']));
                    } else {
                        $index['columns'] = $indexYml['columns'];
                    }
                }

                if (isset($indexYml['fields'])) {
                    if (is_string($indexYml['fields'])) {
                        $index['fields'] = array_map('trim', explode(',', $indexYml['fields']));
                    } else {
                        $index['fields'] = $indexYml['fields'];
                    }
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
                        $indexYml['name']
                    );
                }

                if (isset($indexYml['flags'])) {
                    if (is_string($indexYml['flags'])) {
                        $index['flags'] = array_map('trim', explode(',', $indexYml['flags']));
                    } else {
                        $index['flags'] = $indexYml['flags'];
                    }
                }

                if (isset($indexYml['options'])) {
                    $index['options'] = $indexYml['options'];
                }

                $metadata->table['indexes'][$indexYml['name']] = $index;
            }
        }

        // Evaluate uniqueConstraints
        if (isset($element['uniqueConstraints'])) {
            foreach ($element['uniqueConstraints'] as $name => $uniqueYml) {
                if (! isset($uniqueYml['name'])) {
                    $uniqueYml['name'] = $name;
                }

                $unique = [];

                if (isset($uniqueYml['columns'])) {
                    if (is_string($uniqueYml['columns'])) {
                        $unique['columns'] = array_map('trim', explode(',', $uniqueYml['columns']));
                    } else {
                        $unique['columns'] = $uniqueYml['columns'];
                    }
                }

                if (isset($uniqueYml['fields'])) {
                    if (is_string($uniqueYml['fields'])) {
                        $unique['fields'] = array_map('trim', explode(',', $uniqueYml['fields']));
                    } else {
                        $unique['fields'] = $uniqueYml['fields'];
                    }
                }

                if (
                    isset($unique['columns'], $unique['fields'])
                    || (
                        ! isset($unique['columns'])
                        && ! isset($unique['fields'])
                    )
                ) {
                    throw MappingException::invalidUniqueConstraintConfiguration(
                        $className,
                        $uniqueYml['name']
                    );
                }

                if (isset($uniqueYml['options'])) {
                    $unique['options'] = $uniqueYml['options'];
                }

                $metadata->table['uniqueConstraints'][$uniqueYml['name']] = $unique;
            }
        }

        if (isset($element['options'])) {
            $metadata->table['options'] = $element['options'];
        }

        $associationIds = [];
        if (isset($element['id'])) {
            // Evaluate identifier settings
            foreach ($element['id'] as $name => $idElement) {
                if (isset($idElement['associationKey']) && $idElement['associationKey'] === true) {
                    $associationIds[$name] = true;
                    continue;
                }

                $mapping = [
                    'id' => true,
                    'fieldName' => $name,
                ];

                if (isset($idElement['type'])) {
                    $mapping['type'] = $idElement['type'];
                }

                if (isset($idElement['column'])) {
                    $mapping['columnName'] = $idElement['column'];
                }

                if (isset($idElement['length'])) {
                    $mapping['length'] = $idElement['length'];
                }

                if (isset($idElement['columnDefinition'])) {
                    $mapping['columnDefinition'] = $idElement['columnDefinition'];
                }

                if (isset($idElement['options'])) {
                    $mapping['options'] = $idElement['options'];
                }

                $metadata->mapField($mapping);

                if (isset($idElement['generator'])) {
                    $metadata->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_'
                        . strtoupper($idElement['generator']['strategy'])));
                }

                // Check for SequenceGenerator/TableGenerator definition
                if (isset($idElement['sequenceGenerator'])) {
                    $metadata->setSequenceGeneratorDefinition($idElement['sequenceGenerator']);
                } elseif (isset($idElement['customIdGenerator'])) {
                    $customGenerator = $idElement['customIdGenerator'];
                    $metadata->setCustomGeneratorDefinition(
                        [
                            'class' => (string) $customGenerator['class'],
                        ]
                    );
                } elseif (isset($idElement['tableGenerator'])) {
                    throw MappingException::tableIdGeneratorNotImplemented($className);
                }
            }
        }

        // Evaluate fields
        if (isset($element['fields'])) {
            foreach ($element['fields'] as $name => $fieldMapping) {
                $mapping = $this->columnToArray($name, $fieldMapping);

                if (isset($fieldMapping['id'])) {
                    $mapping['id'] = true;
                    if (isset($fieldMapping['generator']['strategy'])) {
                        $metadata->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_'
                            . strtoupper($fieldMapping['generator']['strategy'])));
                    }
                }

                if (isset($mapping['version'])) {
                    $metadata->setVersionMapping($mapping);
                    unset($mapping['version']);
                }

                $metadata->mapField($mapping);
            }
        }

        if (isset($element['embedded'])) {
            foreach ($element['embedded'] as $name => $embeddedMapping) {
                $mapping = [
                    'fieldName' => $name,
                    'class' => $embeddedMapping['class'] ?? null,
                    'columnPrefix' => $embeddedMapping['columnPrefix'] ?? null,
                ];
                $metadata->mapEmbedded($mapping);
            }
        }

        // Evaluate oneToOne relationships
        if (isset($element['oneToOne'])) {
            foreach ($element['oneToOne'] as $name => $oneToOneElement) {
                $mapping = [
                    'fieldName' => $name,
                    'targetEntity' => $oneToOneElement['targetEntity'] ?? null,
                ];

                if (isset($associationIds[$mapping['fieldName']])) {
                    $mapping['id'] = true;
                }

                if (isset($oneToOneElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $oneToOneElement['fetch']);
                }

                if (isset($oneToOneElement['mappedBy'])) {
                    $mapping['mappedBy'] = $oneToOneElement['mappedBy'];
                } else {
                    if (isset($oneToOneElement['inversedBy'])) {
                        $mapping['inversedBy'] = $oneToOneElement['inversedBy'];
                    }

                    $joinColumns = [];

                    if (isset($oneToOneElement['joinColumn'])) {
                        $joinColumns[] = $this->joinColumnToArray($oneToOneElement['joinColumn']);
                    } elseif (isset($oneToOneElement['joinColumns'])) {
                        foreach ($oneToOneElement['joinColumns'] as $joinColumnName => $joinColumnElement) {
                            if (! isset($joinColumnElement['name'])) {
                                $joinColumnElement['name'] = $joinColumnName;
                            }

                            $joinColumns[] = $this->joinColumnToArray($joinColumnElement);
                        }
                    }

                    $mapping['joinColumns'] = $joinColumns;
                }

                if (isset($oneToOneElement['cascade'])) {
                    $mapping['cascade'] = $oneToOneElement['cascade'];
                }

                if (isset($oneToOneElement['orphanRemoval'])) {
                    $mapping['orphanRemoval'] = (bool) $oneToOneElement['orphanRemoval'];
                }

                // Evaluate second level cache
                if (isset($oneToOneElement['cache'])) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($oneToOneElement['cache']));
                }

                $metadata->mapOneToOne($mapping);
            }
        }

        // Evaluate oneToMany relationships
        if (isset($element['oneToMany'])) {
            foreach ($element['oneToMany'] as $name => $oneToManyElement) {
                $mapping = [
                    'fieldName' => $name,
                    'targetEntity' => $oneToManyElement['targetEntity'],
                    'mappedBy' => $oneToManyElement['mappedBy'],
                ];

                if (isset($oneToManyElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $oneToManyElement['fetch']);
                }

                if (isset($oneToManyElement['cascade'])) {
                    $mapping['cascade'] = $oneToManyElement['cascade'];
                }

                if (isset($oneToManyElement['orphanRemoval'])) {
                    $mapping['orphanRemoval'] = (bool) $oneToManyElement['orphanRemoval'];
                }

                if (isset($oneToManyElement['orderBy'])) {
                    $mapping['orderBy'] = $oneToManyElement['orderBy'];
                }

                if (isset($oneToManyElement['indexBy'])) {
                    $mapping['indexBy'] = $oneToManyElement['indexBy'];
                }

                // Evaluate second level cache
                if (isset($oneToManyElement['cache'])) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($oneToManyElement['cache']));
                }

                $metadata->mapOneToMany($mapping);
            }
        }

        // Evaluate manyToOne relationships
        if (isset($element['manyToOne'])) {
            foreach ($element['manyToOne'] as $name => $manyToOneElement) {
                $mapping = [
                    'fieldName' => $name,
                    'targetEntity' => $manyToOneElement['targetEntity'] ?? null,
                ];

                if (isset($associationIds[$mapping['fieldName']])) {
                    $mapping['id'] = true;
                }

                if (isset($manyToOneElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $manyToOneElement['fetch']);
                }

                if (isset($manyToOneElement['inversedBy'])) {
                    $mapping['inversedBy'] = $manyToOneElement['inversedBy'];
                }

                $joinColumns = [];

                if (isset($manyToOneElement['joinColumn'])) {
                    $joinColumns[] = $this->joinColumnToArray($manyToOneElement['joinColumn']);
                } elseif (isset($manyToOneElement['joinColumns'])) {
                    foreach ($manyToOneElement['joinColumns'] as $joinColumnName => $joinColumnElement) {
                        if (! isset($joinColumnElement['name'])) {
                            $joinColumnElement['name'] = $joinColumnName;
                        }

                        $joinColumns[] = $this->joinColumnToArray($joinColumnElement);
                    }
                }

                $mapping['joinColumns'] = $joinColumns;

                if (isset($manyToOneElement['cascade'])) {
                    $mapping['cascade'] = $manyToOneElement['cascade'];
                }

                // Evaluate second level cache
                if (isset($manyToOneElement['cache'])) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($manyToOneElement['cache']));
                }

                $metadata->mapManyToOne($mapping);
            }
        }

        // Evaluate manyToMany relationships
        if (isset($element['manyToMany'])) {
            foreach ($element['manyToMany'] as $name => $manyToManyElement) {
                $mapping = [
                    'fieldName' => $name,
                    'targetEntity' => $manyToManyElement['targetEntity'],
                ];

                if (isset($manyToManyElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $manyToManyElement['fetch']);
                }

                if (isset($manyToManyElement['mappedBy'])) {
                    $mapping['mappedBy'] = $manyToManyElement['mappedBy'];
                } elseif (isset($manyToManyElement['joinTable'])) {
                    $joinTableElement = $manyToManyElement['joinTable'];
                    $joinTable        = [
                        'name' => $joinTableElement['name'],
                    ];

                    if (isset($joinTableElement['schema'])) {
                        $joinTable['schema'] = $joinTableElement['schema'];
                    }

                    if (isset($joinTableElement['joinColumns'])) {
                        foreach ($joinTableElement['joinColumns'] as $joinColumnName => $joinColumnElement) {
                            if (! isset($joinColumnElement['name'])) {
                                $joinColumnElement['name'] = $joinColumnName;
                            }

                            $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                        }
                    }

                    if (isset($joinTableElement['inverseJoinColumns'])) {
                        foreach ($joinTableElement['inverseJoinColumns'] as $joinColumnName => $joinColumnElement) {
                            if (! isset($joinColumnElement['name'])) {
                                $joinColumnElement['name'] = $joinColumnName;
                            }

                            $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                        }
                    }

                    $mapping['joinTable'] = $joinTable;
                }

                if (isset($manyToManyElement['inversedBy'])) {
                    $mapping['inversedBy'] = $manyToManyElement['inversedBy'];
                }

                if (isset($manyToManyElement['cascade'])) {
                    $mapping['cascade'] = $manyToManyElement['cascade'];
                }

                if (isset($manyToManyElement['orderBy'])) {
                    $mapping['orderBy'] = $manyToManyElement['orderBy'];
                }

                if (isset($manyToManyElement['indexBy'])) {
                    $mapping['indexBy'] = $manyToManyElement['indexBy'];
                }

                if (isset($manyToManyElement['orphanRemoval'])) {
                    $mapping['orphanRemoval'] = (bool) $manyToManyElement['orphanRemoval'];
                }

                // Evaluate second level cache
                if (isset($manyToManyElement['cache'])) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($manyToManyElement['cache']));
                }

                $metadata->mapManyToMany($mapping);
            }
        }

        // Evaluate associationOverride
        if (isset($element['associationOverride']) && is_array($element['associationOverride'])) {
            foreach ($element['associationOverride'] as $fieldName => $associationOverrideElement) {
                $override = [];

                // Check for joinColumn
                if (isset($associationOverrideElement['joinColumn'])) {
                    $joinColumns = [];
                    foreach ($associationOverrideElement['joinColumn'] as $name => $joinColumnElement) {
                        if (! isset($joinColumnElement['name'])) {
                            $joinColumnElement['name'] = $name;
                        }

                        $joinColumns[] = $this->joinColumnToArray($joinColumnElement);
                    }

                    $override['joinColumns'] = $joinColumns;
                }

                // Check for joinTable
                if (isset($associationOverrideElement['joinTable'])) {
                    $joinTableElement = $associationOverrideElement['joinTable'];
                    $joinTable        =  [
                        'name' => $joinTableElement['name'],
                    ];

                    if (isset($joinTableElement['schema'])) {
                        $joinTable['schema'] = $joinTableElement['schema'];
                    }

                    foreach ($joinTableElement['joinColumns'] as $name => $joinColumnElement) {
                        if (! isset($joinColumnElement['name'])) {
                            $joinColumnElement['name'] = $name;
                        }

                        $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                    }

                    foreach ($joinTableElement['inverseJoinColumns'] as $name => $joinColumnElement) {
                        if (! isset($joinColumnElement['name'])) {
                            $joinColumnElement['name'] = $name;
                        }

                        $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                    }

                    $override['joinTable'] = $joinTable;
                }

                // Check for inversedBy
                if (isset($associationOverrideElement['inversedBy'])) {
                    $override['inversedBy'] = (string) $associationOverrideElement['inversedBy'];
                }

                // Check for `fetch`
                if (isset($associationOverrideElement['fetch'])) {
                    $override['fetch'] = constant(Metadata::class . '::FETCH_' . $associationOverrideElement['fetch']);
                }

                $metadata->setAssociationOverride($fieldName, $override);
            }
        }

        // Evaluate associationOverride
        if (isset($element['attributeOverride']) && is_array($element['attributeOverride'])) {
            foreach ($element['attributeOverride'] as $fieldName => $attributeOverrideElement) {
                $mapping = $this->columnToArray($fieldName, $attributeOverrideElement);
                $metadata->setAttributeOverride($fieldName, $mapping);
            }
        }

        // Evaluate lifeCycleCallbacks
        if (isset($element['lifecycleCallbacks'])) {
            foreach ($element['lifecycleCallbacks'] as $type => $methods) {
                foreach ($methods as $method) {
                    $metadata->addLifecycleCallback($method, constant('Doctrine\ORM\Events::' . $type));
                }
            }
        }

        // Evaluate entityListeners
        if (isset($element['entityListeners'])) {
            foreach ($element['entityListeners'] as $className => $entityListener) {
                // Evaluate the listener using naming convention.
                if (empty($entityListener)) {
                    EntityListenerBuilder::bindEntityListener($metadata, $className);

                    continue;
                }

                foreach ($entityListener as $eventName => $callbackElement) {
                    foreach ($callbackElement as $methodName) {
                        $metadata->addEntityListener($eventName, $className, $methodName);
                    }
                }
            }
        }
    }

    /**
     * Constructs a joinColumn mapping array based on the information
     * found in the given join column element.
     *
     * @psalm-param array{
     *                   referencedColumnName?: mixed,
     *                   name?: mixed,
     *                   fieldName?: mixed,
     *                   unique?: mixed,
     *                   nullable?: mixed,
     *                   onDelete?: mixed,
     *                   columnDefinition?: mixed
     *              } $joinColumnElement The array join column element.
     *
     * @return mixed[] The mapping array.
     * @psalm-return array{
     *                   referencedColumnName?: string,
     *                   name?: string,
     *                   fieldName?: string,
     *                   unique?: bool,
     *                   nullable?: bool,
     *                   onDelete?: mixed,
     *                   columnDefinition?: mixed
     *               }
     */
    private function joinColumnToArray(array $joinColumnElement): array
    {
        $joinColumn = [];
        if (isset($joinColumnElement['referencedColumnName'])) {
            $joinColumn['referencedColumnName'] = (string) $joinColumnElement['referencedColumnName'];
        }

        if (isset($joinColumnElement['name'])) {
            $joinColumn['name'] = (string) $joinColumnElement['name'];
        }

        if (isset($joinColumnElement['fieldName'])) {
            $joinColumn['fieldName'] = (string) $joinColumnElement['fieldName'];
        }

        if (isset($joinColumnElement['unique'])) {
            $joinColumn['unique'] = (bool) $joinColumnElement['unique'];
        }

        if (isset($joinColumnElement['nullable'])) {
            $joinColumn['nullable'] = (bool) $joinColumnElement['nullable'];
        }

        if (isset($joinColumnElement['onDelete'])) {
            $joinColumn['onDelete'] = $joinColumnElement['onDelete'];
        }

        if (isset($joinColumnElement['columnDefinition'])) {
            $joinColumn['columnDefinition'] = $joinColumnElement['columnDefinition'];
        }

        return $joinColumn;
    }

    /**
     * Parses the given column as array.
     *
     * @psalm-param array{
     *                   type?: string,
     *                   column?: string,
     *                   precision?: mixed,
     *                   scale?: mixed,
     *                   unique?: mixed,
     *                   options?: mixed,
     *                   nullable?: mixed,
     *                   version?: mixed,
     *                   columnDefinition?: mixed
     *              }|null $column
     *
     * @return mixed[]
     * @psalm-return array{
     *                   fieldName: string,
     *                   type?: string,
     *                   columnName?: string,
     *                   length?: int,
     *                   precision?: mixed,
     *                   scale?: mixed,
     *                   unique?: bool,
     *                   options?: mixed,
     *                   nullable?: mixed,
     *                   version?: mixed,
     *                   columnDefinition?: mixed
     *               }
     */
    private function columnToArray(string $fieldName, ?array $column): array
    {
        $mapping = ['fieldName' => $fieldName];

        if (isset($column['type'])) {
            $params = explode('(', $column['type']);

            $column['type']  = $params[0];
            $mapping['type'] = $column['type'];

            if (isset($params[1])) {
                $column['length'] = (int) substr($params[1], 0, strlen($params[1]) - 1);
            }
        }

        if (isset($column['column'])) {
            $mapping['columnName'] = $column['column'];
        }

        if (isset($column['length'])) {
            $mapping['length'] = $column['length'];
        }

        if (isset($column['precision'])) {
            $mapping['precision'] = $column['precision'];
        }

        if (isset($column['scale'])) {
            $mapping['scale'] = $column['scale'];
        }

        if (isset($column['unique'])) {
            $mapping['unique'] = (bool) $column['unique'];
        }

        if (isset($column['options'])) {
            $mapping['options'] = $column['options'];
        }

        if (isset($column['nullable'])) {
            $mapping['nullable'] = $column['nullable'];
        }

        if (isset($column['version']) && $column['version']) {
            $mapping['version'] = $column['version'];
        }

        if (isset($column['columnDefinition'])) {
            $mapping['columnDefinition'] = $column['columnDefinition'];
        }

        return $mapping;
    }

    /**
     * Parse / Normalize the cache configuration
     *
     * @param mixed[] $cacheMapping
     * @psalm-param array{usage: mixed, region: (string|null)} $cacheMapping
     * @psalm-param array{usage: string, region?: string} $cacheMapping
     *
     * @return mixed[]
     * @psalm-return array{usage: int, region: string|null}
     */
    private function cacheToArray(array $cacheMapping): array
    {
        $region = isset($cacheMapping['region']) ? (string) $cacheMapping['region'] : null;
        $usage  = isset($cacheMapping['usage']) ? strtoupper($cacheMapping['usage']) : null;

        if ($usage && ! defined('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $usage)) {
            throw new InvalidArgumentException(sprintf('Invalid cache usage "%s"', $usage));
        }

        if ($usage) {
            $usage = (int) constant('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $usage);
        }

        return [
            'usage'  => $usage,
            'region' => $region,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function loadMappingFile($file)
    {
        return Yaml::parse(file_get_contents($file));
    }
}
