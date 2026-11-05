<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\ORM\Mapping\Builder\EntityListenerBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\Mapping\Driver\FileDriver;
use Doctrine\Persistence\Mapping\Driver\FileLocator;
use DOMDocument;
use InvalidArgumentException;
use LogicException;
use SimpleXMLElement;
use SortDirection;

use function array_key_exists;
use function assert;
use function class_exists;
use function constant;
use function count;
use function defined;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function in_array;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function simplexml_load_string;
use function sprintf;
use function str_replace;
use function strtoupper;

/**
 * XmlDriver is a metadata driver that enables mapping through XML files.
 *
 * @link        www.doctrine-project.org
 *
 * @template-extends FileDriver<SimpleXMLElement>
 */
class XmlDriver extends FileDriver
{
    use LoadMappingFileImplementation;

    public const DEFAULT_FILE_EXTENSION = '.dcm.xml';

    /**
     * {@inheritDoc}
     */
    public function __construct(
        string|array|FileLocator $locator,
        string $fileExtension = self::DEFAULT_FILE_EXTENSION,
        private readonly bool $isXsdValidationEnabled = true,
    ) {
        if (! extension_loaded('simplexml')) {
            throw new LogicException(
                'The XML metadata driver cannot be enabled because the SimpleXML PHP extension is missing.'
                . ' Please configure PHP with SimpleXML or choose a different metadata driver.',
            );
        }

        if ($isXsdValidationEnabled && ! extension_loaded('dom')) {
            throw new LogicException(
                'XSD validation cannot be enabled because the DOM extension is missing.',
            );
        }

        parent::__construct($locator, $fileExtension);
    }

    /**
     * {@inheritDoc}
     *
     * @param class-string<T>  $className
     * @param ClassMetadata<T> $metadata
     *
     * @template T of object
     */
    public function loadMetadataForClass($className, PersistenceClassMetadata $metadata): void
    {
        $xmlRoot = $this->getElement($className);

        if ($xmlRoot->getName() === 'entity') {
            if (isset($xmlRoot['repository-class'])) {
                $metadata->setCustomRepositoryClass((string) $xmlRoot['repository-class']);
            }

            if (isset($xmlRoot['read-only']) && $this->evaluateBoolean($xmlRoot['read-only'])) {
                $metadata->markReadOnly();
            }
        } elseif ($xmlRoot->getName() === 'mapped-superclass') {
            $metadata->setCustomRepositoryClass(
                isset($xmlRoot['repository-class']) ? (string) $xmlRoot['repository-class'] : null,
            );
            $metadata->isMappedSuperclass = true;
        } elseif ($xmlRoot->getName() === 'embeddable') {
            $metadata->isEmbeddedClass = true;
        } else {
            throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }

        // Evaluate <entity...> attributes
        $primaryTable = [];

        if (isset($xmlRoot['table'])) {
            $primaryTable['name'] = (string) $xmlRoot['table'];
        }

        if (isset($xmlRoot['schema'])) {
            $primaryTable['schema'] = (string) $xmlRoot['schema'];
        }

        $metadata->setPrimaryTable($primaryTable);

        // Evaluate second level cache
        if (isset($xmlRoot->cache)) {
            $metadata->enableCache($this->cacheToArray($xmlRoot->cache));
        }

        if (isset($xmlRoot['inheritance-type'])) {
            $inheritanceType = (string) $xmlRoot['inheritance-type'];
            $metadata->setInheritanceType(constant('Doctrine\ORM\Mapping\ClassMetadata::INHERITANCE_TYPE_' . $inheritanceType));

            if ($metadata->inheritanceType !== ClassMetadata::INHERITANCE_TYPE_NONE) {
                // Evaluate <discriminator-column...>
                if (isset($xmlRoot->{'discriminator-column'})) {
                    $discrColumn = $xmlRoot->{'discriminator-column'};
                    $columnDef   = [
                        'name' => isset($discrColumn['name']) ? (string) $discrColumn['name'] : null,
                        'type' => isset($discrColumn['type']) ? (string) $discrColumn['type'] : 'string',
                        'length' => isset($discrColumn['length']) ? (int) $discrColumn['length'] : 255,
                        'columnDefinition' => isset($discrColumn['column-definition']) ? (string) $discrColumn['column-definition'] : null,
                        'enumType' => isset($discrColumn['enum-type']) ? (string) $discrColumn['enum-type'] : null,
                    ];

                    if (isset($discrColumn['options'])) {
                        $columnDef['options'] = $this->parseOptions($discrColumn['options']->children());
                    }

                    $metadata->setDiscriminatorColumn($columnDef);
                } else {
                    $metadata->setDiscriminatorColumn(['name' => 'dtype', 'type' => 'string', 'length' => 255]);
                }

                // Evaluate <discriminator-map...>
                if (isset($xmlRoot->{'discriminator-map'})) {
                    $map = [];
                    assert($xmlRoot->{'discriminator-map'}->{'discriminator-mapping'} instanceof SimpleXMLElement);
                    foreach ($xmlRoot->{'discriminator-map'}->{'discriminator-mapping'} as $discrMapElement) {
                        $map[(string) $discrMapElement['value']] = (string) $discrMapElement['class'];
                    }

                    $metadata->setDiscriminatorMap($map);
                }
            }
        }

        // Evaluate <change-tracking-policy...>
        if (isset($xmlRoot['change-tracking-policy'])) {
            $metadata->setChangeTrackingPolicy(constant('Doctrine\ORM\Mapping\ClassMetadata::CHANGETRACKING_'
                . strtoupper((string) $xmlRoot['change-tracking-policy'])));
        }

        // Evaluate <indexes...>
        if (isset($xmlRoot->indexes)) {
            $metadata->table['indexes'] = [];
            foreach ($xmlRoot->indexes->index ?? [] as $indexXml) {
                $index = [];

                if (isset($indexXml['columns']) && ! empty($indexXml['columns'])) {
                    $index['columns'] = explode(',', (string) $indexXml['columns']);
                }

                if (isset($indexXml['fields'])) {
                    $index['fields'] = explode(',', (string) $indexXml['fields']);
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
                        (string) ($indexXml['name'] ?? count($metadata->table['indexes'])),
                    );
                }

                if (isset($indexXml['flags'])) {
                    $index['flags'] = explode(',', (string) $indexXml['flags']);
                }

                if (isset($indexXml->options)) {
                    $index['options'] = $this->parseOptions($indexXml->options->children());
                }

                if (isset($indexXml['name'])) {
                    $metadata->table['indexes'][(string) $indexXml['name']] = $index;
                } else {
                    $metadata->table['indexes'][] = $index;
                }
            }
        }

        // Evaluate <unique-constraints..>
        if (isset($xmlRoot->{'unique-constraints'})) {
            $metadata->table['uniqueConstraints'] = [];
            foreach ($xmlRoot->{'unique-constraints'}->{'unique-constraint'} ?? [] as $uniqueXml) {
                $unique = [];

                if (isset($uniqueXml['columns']) && ! empty($uniqueXml['columns'])) {
                    $unique['columns'] = explode(',', (string) $uniqueXml['columns']);
                }

                if (isset($uniqueXml['fields'])) {
                    $unique['fields'] = explode(',', (string) $uniqueXml['fields']);
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
                        (string) ($uniqueXml['name'] ?? count($metadata->table['uniqueConstraints'])),
                    );
                }

                if (isset($uniqueXml->options)) {
                    $unique['options'] = $this->parseOptions($uniqueXml->options->children());
                }

                if (isset($uniqueXml['name'])) {
                    $metadata->table['uniqueConstraints'][(string) $uniqueXml['name']] = $unique;
                } else {
                    $metadata->table['uniqueConstraints'][] = $unique;
                }
            }
        }

        if (isset($xmlRoot->options)) {
            $metadata->table['options'] = $this->parseOptions($xmlRoot->options->children());
        }

        // The mapping assignment is done in 2 times as a bug might occurs on some php/xml lib versions
        // The internal SimpleXmlIterator get resetted, to this generate a duplicate field exception
        // Evaluate <field ...> mappings
        if (isset($xmlRoot->field)) {
            foreach ($xmlRoot->field as $fieldMapping) {
                $mapping = $this->columnToArray($fieldMapping);

                if (isset($mapping['version'])) {
                    $metadata->setVersionMapping($mapping);
                    unset($mapping['version']);
                }

                $metadata->mapField($mapping);
            }
        }

        if (isset($xmlRoot->embedded)) {
            foreach ($xmlRoot->embedded as $embeddedMapping) {
                $columnPrefix = isset($embeddedMapping['column-prefix'])
                    ? (string) $embeddedMapping['column-prefix']
                    : null;

                $useColumnPrefix = isset($embeddedMapping['use-column-prefix'])
                    ? $this->evaluateBoolean($embeddedMapping['use-column-prefix'])
                    : true;

                $mapping = [
                    'fieldName' => (string) $embeddedMapping['name'],
                    'class' => isset($embeddedMapping['class']) ? (string) $embeddedMapping['class'] : null,
                    'columnPrefix' => $useColumnPrefix ? $columnPrefix : false,
                ];

                $metadata->mapEmbedded($mapping);
            }
        }

        // Evaluate <id ...> mappings
        $associationIds = [];
        foreach ($xmlRoot->id ?? [] as $idElement) {
            $position = isset($idElement['position']) ? (int) $idElement['position'] : null;

            if (isset($idElement['association-key']) && $this->evaluateBoolean($idElement['association-key'])) {
                $associationIds[(string) $idElement['name']] = $position;
                continue;
            }

            $mapping               = $this->columnToArray($idElement);
            $mapping['id']         = true;
            $mapping['idPosition'] = $position;

            $metadata->mapField($mapping);

            if (isset($idElement->generator)) {
                $strategy = isset($idElement->generator['strategy']) ?
                        (string) $idElement->generator['strategy'] : 'AUTO';
                $metadata->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_'
                    . $strategy));
            }

            // Check for SequenceGenerator/TableGenerator definition
            if (isset($idElement->{'sequence-generator'})) {
                $seqGenerator = $idElement->{'sequence-generator'};
                $metadata->setSequenceGeneratorDefinition(
                    [
                        'sequenceName' => (string) $seqGenerator['sequence-name'],
                        'allocationSize' => (string) $seqGenerator['allocation-size'],
                        'initialValue' => (string) $seqGenerator['initial-value'],
                    ],
                );
            } elseif (isset($idElement->{'custom-id-generator'})) {
                if (! isset($idElement->generator)) {
                    $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_CUSTOM);
                }

                $customGenerator = $idElement->{'custom-id-generator'};
                $metadata->setCustomGeneratorDefinition(
                    [
                        'class' => (string) $customGenerator['class'],
                    ],
                );
            }
        }

        // Evaluate <one-to-one ...> mappings
        if (isset($xmlRoot->{'one-to-one'})) {
            foreach ($xmlRoot->{'one-to-one'} as $oneToOneElement) {
                $mapping = [
                    'fieldName' => (string) $oneToOneElement['field'],
                ];

                if (isset($oneToOneElement['target-entity'])) {
                    $mapping['targetEntity'] = (string) $oneToOneElement['target-entity'];
                }

                if (array_key_exists($mapping['fieldName'], $associationIds)) {
                    $mapping['id']         = true;
                    $mapping['idPosition'] = $associationIds[$mapping['fieldName']];
                }

                if (isset($oneToOneElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . (string) $oneToOneElement['fetch']);
                }

                if (isset($oneToOneElement['mapped-by'])) {
                    $mapping['mappedBy'] = (string) $oneToOneElement['mapped-by'];
                } else {
                    if (isset($oneToOneElement['inversed-by'])) {
                        $mapping['inversedBy'] = (string) $oneToOneElement['inversed-by'];
                    }

                    $joinColumns = [];

                    if (isset($oneToOneElement->{'join-column'})) {
                        $joinColumns[] = $this->joinColumnToArray($oneToOneElement->{'join-column'});
                    } elseif (isset($oneToOneElement->{'join-columns'})) {
                        foreach ($oneToOneElement->{'join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                            $joinColumns[] = $this->joinColumnToArray($joinColumnElement);
                        }
                    }

                    $mapping['joinColumns'] = $joinColumns;
                }

                if (isset($oneToOneElement->cascade)) {
                    $mapping['cascade'] = $this->getCascadeMappings($oneToOneElement->cascade);
                }

                if (isset($oneToOneElement['orphan-removal'])) {
                    $mapping['orphanRemoval'] = $this->evaluateBoolean($oneToOneElement['orphan-removal']);
                }

                // Evaluate second level cache
                if (isset($oneToOneElement->cache)) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($oneToOneElement->cache));
                }

                $metadata->mapOneToOne($mapping);
            }
        }

        // Evaluate <one-to-many ...> mappings
        if (isset($xmlRoot->{'one-to-many'})) {
            foreach ($xmlRoot->{'one-to-many'} as $oneToManyElement) {
                $mapping = [
                    'fieldName' => (string) $oneToManyElement['field'],
                    'mappedBy' => (string) $oneToManyElement['mapped-by'],
                ];

                if (isset($oneToManyElement['target-entity'])) {
                    $mapping['targetEntity'] = (string) $oneToManyElement['target-entity'];
                }

                if (isset($oneToManyElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . (string) $oneToManyElement['fetch']);
                }

                if (isset($oneToManyElement->cascade)) {
                    $mapping['cascade'] = $this->getCascadeMappings($oneToManyElement->cascade);
                }

                if (isset($oneToManyElement['orphan-removal'])) {
                    $mapping['orphanRemoval'] = $this->evaluateBoolean($oneToManyElement['orphan-removal']);
                }

                if (isset($oneToManyElement->{'order-by'})) {
                    $orderBy = [];
                    foreach ($oneToManyElement->{'order-by'}->{'order-by-field'} ?? [] as $orderByField) {
                        $orderBy[(string) $orderByField['name']] = $this->getDirection($orderByField, $className);
                    }

                    $mapping['orderBy'] = $orderBy;
                }

                if (isset($oneToManyElement['index-by'])) {
                    $mapping['indexBy'] = (string) $oneToManyElement['index-by'];
                } elseif (isset($oneToManyElement->{'index-by'})) {
                    throw new InvalidArgumentException('<index-by /> is not a valid tag');
                }

                // Evaluate second level cache
                if (isset($oneToManyElement->cache)) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($oneToManyElement->cache));
                }

                $metadata->mapOneToMany($mapping);
            }
        }

        // Evaluate <many-to-one ...> mappings
        if (isset($xmlRoot->{'many-to-one'})) {
            foreach ($xmlRoot->{'many-to-one'} as $manyToOneElement) {
                $mapping = [
                    'fieldName' => (string) $manyToOneElement['field'],
                ];

                if (isset($manyToOneElement['target-entity'])) {
                    $mapping['targetEntity'] = (string) $manyToOneElement['target-entity'];
                }

                if (array_key_exists($mapping['fieldName'], $associationIds)) {
                    $mapping['id']         = true;
                    $mapping['idPosition'] = $associationIds[$mapping['fieldName']];
                }

                if (isset($manyToOneElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . (string) $manyToOneElement['fetch']);
                }

                if (isset($manyToOneElement['inversed-by'])) {
                    $mapping['inversedBy'] = (string) $manyToOneElement['inversed-by'];
                }

                $joinColumns = [];

                if (isset($manyToOneElement->{'join-column'})) {
                    $joinColumns[] = $this->joinColumnToArray($manyToOneElement->{'join-column'});
                } elseif (isset($manyToOneElement->{'join-columns'})) {
                    foreach ($manyToOneElement->{'join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                        $joinColumns[] = $this->joinColumnToArray($joinColumnElement);
                    }
                }

                $mapping['joinColumns'] = $joinColumns;

                if (isset($manyToOneElement->cascade)) {
                    $mapping['cascade'] = $this->getCascadeMappings($manyToOneElement->cascade);
                }

                // Evaluate second level cache
                if (isset($manyToOneElement->cache)) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($manyToOneElement->cache));
                }

                $metadata->mapManyToOne($mapping);
            }
        }

        // Evaluate <many-to-many ...> mappings
        if (isset($xmlRoot->{'many-to-many'})) {
            foreach ($xmlRoot->{'many-to-many'} as $manyToManyElement) {
                $mapping = [
                    'fieldName' => (string) $manyToManyElement['field'],
                ];

                if (isset($manyToManyElement['target-entity'])) {
                    $mapping['targetEntity'] = (string) $manyToManyElement['target-entity'];
                }

                if (isset($manyToManyElement['fetch'])) {
                    $mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . (string) $manyToManyElement['fetch']);
                }

                if (isset($manyToManyElement['orphan-removal'])) {
                    $mapping['orphanRemoval'] = $this->evaluateBoolean($manyToManyElement['orphan-removal']);
                }

                if (isset($manyToManyElement['mapped-by'])) {
                    $mapping['mappedBy'] = (string) $manyToManyElement['mapped-by'];
                } elseif (isset($manyToManyElement->{'join-table'})) {
                    if (isset($manyToManyElement['inversed-by'])) {
                        $mapping['inversedBy'] = (string) $manyToManyElement['inversed-by'];
                    }

                    $joinTableElement = $manyToManyElement->{'join-table'};
                    $joinTable        = [
                        'name' => (string) $joinTableElement['name'],
                    ];

                    if (isset($joinTableElement['schema'])) {
                        $joinTable['schema'] = (string) $joinTableElement['schema'];
                    }

                    if (isset($joinTableElement['foreign-key-name'])) {
                        $joinTable['foreignKeyName'] = (string) $joinTableElement['foreign-key-name'];
                    }

                    if (isset($joinTableElement['inverse-foreign-key-name'])) {
                        $joinTable['inverseForeignKeyName'] = (string) $joinTableElement['inverse-foreign-key-name'];
                    }

                    if (isset($joinTableElement->options)) {
                        $joinTable['options'] = $this->parseOptions($joinTableElement->options->children());
                    }

                    foreach ($joinTableElement->{'join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                        $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                    }

                    foreach ($joinTableElement->{'inverse-join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                        $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                    }

                    $mapping['joinTable'] = $joinTable;
                }

                if (isset($manyToManyElement->cascade)) {
                    $mapping['cascade'] = $this->getCascadeMappings($manyToManyElement->cascade);
                }

                if (isset($manyToManyElement->{'order-by'})) {
                    $orderBy = [];
                    foreach ($manyToManyElement->{'order-by'}->{'order-by-field'} ?? [] as $orderByField) {
                        $direction = $this->getDirection($orderByField, $className);

                        $orderBy[(string) $orderByField['name']] = $direction;
                    }

                    $mapping['orderBy'] = $orderBy;
                }

                if (isset($manyToManyElement['index-by'])) {
                    $mapping['indexBy'] = (string) $manyToManyElement['index-by'];
                } elseif (isset($manyToManyElement->{'index-by'})) {
                    throw new InvalidArgumentException('<index-by /> is not a valid tag');
                }

                // Evaluate second level cache
                if (isset($manyToManyElement->cache)) {
                    $mapping['cache'] = $metadata->getAssociationCacheDefaults($mapping['fieldName'], $this->cacheToArray($manyToManyElement->cache));
                }

                $metadata->mapManyToMany($mapping);
            }
        }

        // Evaluate association-overrides
        if (isset($xmlRoot->{'attribute-overrides'})) {
            foreach ($xmlRoot->{'attribute-overrides'}->{'attribute-override'} ?? [] as $overrideElement) {
                $fieldName = (string) $overrideElement['name'];
                foreach ($overrideElement->field ?? [] as $field) {
                    $mapping              = $this->columnToArray($field);
                    $mapping['fieldName'] = $fieldName;
                    $metadata->setAttributeOverride($fieldName, $mapping);
                }
            }
        }

        // Evaluate association-overrides
        if (isset($xmlRoot->{'association-overrides'})) {
            foreach ($xmlRoot->{'association-overrides'}->{'association-override'} ?? [] as $overrideElement) {
                $fieldName = (string) $overrideElement['name'];
                $override  = [];

                // Check for join-columns
                if (isset($overrideElement->{'join-columns'})) {
                    $joinColumns = [];
                    foreach ($overrideElement->{'join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                        $joinColumns[] = $this->joinColumnToArray($joinColumnElement);
                    }

                    $override['joinColumns'] = $joinColumns;
                }

                // Check for join-table
                if ($overrideElement->{'join-table'}) {
                    $joinTable        = null;
                    $joinTableElement = $overrideElement->{'join-table'};

                    $joinTable = [
                        'name'      => (string) $joinTableElement['name'],
                        'schema'    => (string) $joinTableElement['schema'],
                    ];

                    if (isset($joinTableElement['foreign-key-name'])) {
                        $joinTable['foreignKeyName'] = (string) $joinTableElement['foreign-key-name'];
                    }

                    if (isset($joinTableElement['inverse-foreign-key-name'])) {
                        $joinTable['inverseForeignKeyName'] = (string) $joinTableElement['inverse-foreign-key-name'];
                    }

                    if (isset($joinTableElement->options)) {
                        $joinTable['options'] = $this->parseOptions($joinTableElement->options->children());
                    }

                    if (isset($joinTableElement->{'join-columns'})) {
                        foreach ($joinTableElement->{'join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                            $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                        }
                    }

                    if (isset($joinTableElement->{'inverse-join-columns'})) {
                        foreach ($joinTableElement->{'inverse-join-columns'}->{'join-column'} ?? [] as $joinColumnElement) {
                            $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumnElement);
                        }
                    }

                    $override['joinTable'] = $joinTable;
                }

                // Check for inversed-by
                if (isset($overrideElement->{'inversed-by'})) {
                    $override['inversedBy'] = (string) $overrideElement->{'inversed-by'}['name'];
                }

                // Check for `fetch`
                if (isset($overrideElement['fetch'])) {
                    $override['fetch'] = constant(ClassMetadata::class . '::FETCH_' . (string) $overrideElement['fetch']);
                }

                $metadata->setAssociationOverride($fieldName, $override);
            }
        }

        // Evaluate <lifecycle-callbacks...>
        if (isset($xmlRoot->{'lifecycle-callbacks'})) {
            foreach ($xmlRoot->{'lifecycle-callbacks'}->{'lifecycle-callback'} ?? [] as $lifecycleCallback) {
                $metadata->addLifecycleCallback((string) $lifecycleCallback['method'], constant('Doctrine\ORM\Events::' . (string) $lifecycleCallback['type']));
            }
        }

        // Evaluate entity listener
        if (isset($xmlRoot->{'entity-listeners'})) {
            foreach ($xmlRoot->{'entity-listeners'}->{'entity-listener'} ?? [] as $listenerElement) {
                $className = (string) $listenerElement['class'];
                // Evaluate the listener using naming convention.
                if ($listenerElement->count() === 0) {
                    EntityListenerBuilder::bindEntityListener($metadata, $className);

                    continue;
                }

                foreach ($listenerElement as $callbackElement) {
                    $eventName  = (string) $callbackElement['type'];
                    $methodName = (string) $callbackElement['method'];

                    $metadata->addEntityListener($eventName, $className, $methodName);
                }
            }
        }
    }

    /**
     * Parses (nested) option elements.
     *
     * @return mixed[] The options array.
     * @phpstan-return array<int|string, array<int|string, mixed|string>|bool|string|object>
     */
    private function parseOptions(SimpleXMLElement|null $options): array
    {
        $array = [];

        foreach ($options ?? [] as $option) {
            $value = null;
            if ($option->count()) {
                // Check if this option contains an <object> element
                $children         = $option->children();
                $hasObjectElement = false;

                foreach ($children as $child) {
                    if ($child->getName() === 'object') {
                        $value            = $this->parseObjectElement($child);
                        $hasObjectElement = true;
                        break;
                    }
                }

                if (! $hasObjectElement) {
                    $value = $this->parseOptions($children);
                }
            } else {
                $value = (string) $option;
            }

            $attributes = $option->attributes();

            if (isset($attributes->name)) {
                $nameAttribute         = (string) $attributes->name;
                $array[$nameAttribute] = in_array($nameAttribute, ['unsigned', 'fixed'], true)
                    ? $this->evaluateBoolean($value)
                    : $value;
            } else {
                $array[] = $value;
            }
        }

        return $array;
    }

    /**
     * Parses an <object> element and returns the instantiated object.
     *
     * @param SimpleXMLElement $objectElement The XML element.
     *
     * @return object The instantiated object.
     *
     * @throws MappingException If the object specification is invalid.
     * @throws InvalidArgumentException If the class does not exist.
     */
    private function parseObjectElement(SimpleXMLElement $objectElement): object
    {
        $attributes = $objectElement->attributes();

        if (! isset($attributes->class)) {
            throw MappingException::missingRequiredOption('object', 'class');
        }

        $className = (string) $attributes->class;

        if (! class_exists($className)) {
            throw new InvalidArgumentException(sprintf('Class "%s" does not exist', $className));
        }

        return new $className();
    }

    /**
     * Constructs a joinColumn mapping array based on the information
     * found in the given SimpleXMLElement.
     *
     * @param SimpleXMLElement $joinColumnElement The XML element.
     *
     * @return mixed[] The mapping array.
     * @phpstan-return array{
     *                   name: string,
     *                   referencedColumnName: string,
     *                   unique?: bool,
     *                   nullable?: bool,
     *                   onDelete?: string,
     *                   columnDefinition?: string,
     *                   foreignKeyName?: string,
     *                   options?: mixed[]
     *               }
     */
    private function joinColumnToArray(SimpleXMLElement $joinColumnElement): array
    {
        $joinColumn = [
            'name' => (string) $joinColumnElement['name'],
            'referencedColumnName' => (string) $joinColumnElement['referenced-column-name'],
        ];

        if (isset($joinColumnElement['unique'])) {
            $joinColumn['unique'] = $this->evaluateBoolean($joinColumnElement['unique']);
        }

        if (isset($joinColumnElement['nullable'])) {
            $joinColumn['nullable'] = $this->evaluateBoolean($joinColumnElement['nullable']);
        }

        if (isset($joinColumnElement['on-delete'])) {
            $joinColumn['onDelete'] = (string) $joinColumnElement['on-delete'];
        }

        if (isset($joinColumnElement['column-definition'])) {
            $joinColumn['columnDefinition'] = (string) $joinColumnElement['column-definition'];
        }

        if (isset($joinColumnElement['foreign-key-name'])) {
            $joinColumn['foreignKeyName'] = (string) $joinColumnElement['foreign-key-name'];
        }

        if (isset($joinColumnElement['options'])) {
            $joinColumn['options'] = $this->parseOptions($joinColumnElement['options'] ? $joinColumnElement['options']->children() : null);
        }

        return $joinColumn;
    }

     /**
      * Parses the given field as array.
      *
      * @return mixed[]
      * @phpstan-return array{
      *                   fieldName: string,
      *                   type?: string,
      *                   columnName?: string,
      *                   length?: int,
      *                   precision?: int,
      *                   scale?: int,
      *                   unique?: bool,
      *                   nullable?: bool,
      *                   index?: bool,
      *                   notInsertable?: bool,
      *                   notUpdatable?: bool,
      *                   enumType?: string,
      *                   version?: bool,
      *                   columnDefinition?: string,
      *                   options?: array
      *               }
      */
    private function columnToArray(SimpleXMLElement $fieldMapping): array
    {
        $mapping = [
            'fieldName' => (string) $fieldMapping['name'],
        ];

        if (isset($fieldMapping['type'])) {
            $mapping['type'] = (string) $fieldMapping['type'];
        }

        if (isset($fieldMapping['column'])) {
            $mapping['columnName'] = (string) $fieldMapping['column'];
        }

        if (isset($fieldMapping['length'])) {
            $mapping['length'] = (int) $fieldMapping['length'];
        }

        if (isset($fieldMapping['precision'])) {
            $mapping['precision'] = (int) $fieldMapping['precision'];
        }

        if (isset($fieldMapping['scale'])) {
            $mapping['scale'] = (int) $fieldMapping['scale'];
        }

        if (isset($fieldMapping['unique'])) {
            $mapping['unique'] = $this->evaluateBoolean($fieldMapping['unique']);
        }

        if (isset($fieldMapping['index'])) {
            $mapping['index'] = $this->evaluateBoolean($fieldMapping['index']);
        }

        if (isset($fieldMapping['nullable'])) {
            $mapping['nullable'] = $this->evaluateBoolean($fieldMapping['nullable']);
        }

        if (isset($fieldMapping['insertable']) && ! $this->evaluateBoolean($fieldMapping['insertable'])) {
            $mapping['notInsertable'] = true;
        }

        if (isset($fieldMapping['updatable']) && ! $this->evaluateBoolean($fieldMapping['updatable'])) {
            $mapping['notUpdatable'] = true;
        }

        if (isset($fieldMapping['generated'])) {
            $mapping['generated'] = constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATED_' . (string) $fieldMapping['generated']);
        }

        if (isset($fieldMapping['version']) && $fieldMapping['version']) {
            $mapping['version'] = $this->evaluateBoolean($fieldMapping['version']);
        }

        if (isset($fieldMapping['column-definition'])) {
            $mapping['columnDefinition'] = (string) $fieldMapping['column-definition'];
        }

        if (isset($fieldMapping['enum-type'])) {
            $mapping['enumType'] = (string) $fieldMapping['enum-type'];
        }

        if (isset($fieldMapping->options)) {
            $mapping['options'] = $this->parseOptions($fieldMapping->options->children());
        }

        return $mapping;
    }

    /**
     * Parse / Normalize the cache configuration
     *
     * @return mixed[]
     * @phpstan-return array{usage: int|null, region?: string}
     */
    private function cacheToArray(SimpleXMLElement $cacheMapping): array
    {
        $region = isset($cacheMapping['region']) ? (string) $cacheMapping['region'] : null;
        $usage  = isset($cacheMapping['usage']) ? strtoupper((string) $cacheMapping['usage']) : null;

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
     * Gathers a list of cascade options found in the given cascade element.
     *
     * @param SimpleXMLElement $cascadeElement The cascade element.
     *
     * @return string[] The list of cascade options.
     * @phpstan-return list<string>
     */
    private function getCascadeMappings(SimpleXMLElement $cascadeElement): array
    {
        $cascades = [];
        $children = $cascadeElement->children();
        assert($children !== null);

        foreach ($children as $action) {
            // According to the JPA specifications, XML uses "cascade-persist"
            // instead of "persist". Here, both variations
            // are supported because Attribute uses "persist"
            // and we want to make sure that this driver doesn't need to know
            // anything about the supported cascading actions
            $cascades[] = str_replace('cascade-', '', $action->getName());
        }

        return $cascades;
    }

    /** @return array<class-string, SimpleXMLElement> */
    private function doLoadMappingFile(string $file): array
    {
        $this->validateMapping($file);
        $result = [];
        // Note: we do not use `simplexml_load_file()` because of https://bugs.php.net/bug.php?id=62577
        $xmlElement = simplexml_load_string(file_get_contents($file));
        assert($xmlElement !== false);

        if (isset($xmlElement->entity)) {
            foreach ($xmlElement->entity as $entityElement) {
                /** @var class-string $entityName */
                $entityName          = (string) $entityElement['name'];
                $result[$entityName] = $entityElement;
            }
        } elseif (isset($xmlElement->{'mapped-superclass'})) {
            foreach ($xmlElement->{'mapped-superclass'} as $mappedSuperClass) {
                /** @var class-string $className */
                $className          = (string) $mappedSuperClass['name'];
                $result[$className] = $mappedSuperClass;
            }
        } elseif (isset($xmlElement->embeddable)) {
            foreach ($xmlElement->embeddable as $embeddableElement) {
                /** @var class-string $embeddableName */
                $embeddableName          = (string) $embeddableElement['name'];
                $result[$embeddableName] = $embeddableElement;
            }
        }

        return $result;
    }

    private function validateMapping(string $file): void
    {
        if (! $this->isXsdValidationEnabled) {
            return;
        }

        $backedUpErrorSetting = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $document->load($file);

            if (! $document->schemaValidate(__DIR__ . '/../../../doctrine-mapping.xsd')) {
                throw MappingException::fromLibXmlErrors(libxml_get_errors());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($backedUpErrorSetting);
        }
    }

    protected function evaluateBoolean(mixed $element): bool
    {
        $flag = (string) $element;

        return $flag === 'true' || $flag === '1';
    }

    private function getDirection(SimpleXMLElement $orderByField, string $className): SortDirection
    {
        $direction = (string) ($orderByField['direction'] ?? 'ASC');

        assert(in_array($direction, ['ASC', 'DESC'], true));

        return match (strtoupper($direction)) {
            'ASC' => SortDirection::Ascending,
            'DESC' => SortDirection::Descending,
        };
    }
}
