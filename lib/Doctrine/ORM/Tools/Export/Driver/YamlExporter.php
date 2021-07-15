<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Export\Driver;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Symfony\Component\Yaml\Yaml;

use function array_merge;
use function count;

/**
 * ClassMetadata exporter for Doctrine YAML mapping files.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 *
 * @link    www.doctrine-project.org
 */
class YamlExporter extends AbstractExporter
{
    /** @var string */
    protected $_extension = '.dcm.yml';

    /**
     * {@inheritdoc}
     */
    public function exportClassMetadata(ClassMetadataInfo $metadata)
    {
        $array = [];

        if ($metadata->isMappedSuperclass) {
            $array['type'] = 'mappedSuperclass';
        } else {
            $array['type'] = 'entity';
        }

        $metadataTable = $metadata->table ?? ['name' => null];

        $array['table'] = $metadataTable['name'];

        if (isset($metadataTable['schema'])) {
            $array['schema'] = $metadataTable['schema'];
        }

        $inheritanceType = $metadata->inheritanceType;

        if ($inheritanceType !== ClassMetadataInfo::INHERITANCE_TYPE_NONE) {
            $array['inheritanceType'] = $this->_getInheritanceTypeString($inheritanceType);
        }

        $column = $metadata->discriminatorColumn;
        if ($column) {
            $array['discriminatorColumn'] = $column;
        }

        $map = $metadata->discriminatorMap;
        if ($map) {
            $array['discriminatorMap'] = $map;
        }

        if ($metadata->changeTrackingPolicy !== ClassMetadataInfo::CHANGETRACKING_DEFERRED_IMPLICIT) {
            $array['changeTrackingPolicy'] = $this->_getChangeTrackingPolicyString($metadata->changeTrackingPolicy);
        }

        if (isset($metadataTable['indexes'])) {
            $array['indexes'] = $metadataTable['indexes'];
        }

        if ($metadata->customRepositoryClassName) {
            $array['repositoryClass'] = $metadata->customRepositoryClassName;
        }

        if (isset($metadataTable['uniqueConstraints'])) {
            $array['uniqueConstraints'] = $metadataTable['uniqueConstraints'];
        }

        if (isset($metadataTable['options'])) {
            $array['options'] = $metadataTable['options'];
        }

        $fieldMappings = $metadata->fieldMappings;

        $ids = [];
        foreach ($fieldMappings as $name => $fieldMapping) {
            $fieldMapping['column'] = $fieldMapping['columnName'];

            unset($fieldMapping['columnName'], $fieldMapping['fieldName']);

            if ($fieldMapping['column'] === $name) {
                unset($fieldMapping['column']);
            }

            if (isset($fieldMapping['id']) && $fieldMapping['id']) {
                $ids[$name] = $fieldMapping;
                unset($fieldMappings[$name]);
                continue;
            }

            $fieldMappings[$name] = $fieldMapping;
        }

        if (! $metadata->isIdentifierComposite) {
            $idGeneratorType = $this->_getIdGeneratorTypeString($metadata->generatorType);
            if ($idGeneratorType) {
                $ids[$metadata->getSingleIdentifierFieldName()]['generator']['strategy'] = $idGeneratorType;
            }
        }

        $array['id'] = $ids;

        if ($fieldMappings) {
            $array['fields'] = $fieldMappings;
        }

        foreach ($metadata->associationMappings as $name => $associationMapping) {
            $cascade = [];

            if ($associationMapping['isCascadeRemove']) {
                $cascade[] = 'remove';
            }

            if ($associationMapping['isCascadePersist']) {
                $cascade[] = 'persist';
            }

            if ($associationMapping['isCascadeRefresh']) {
                $cascade[] = 'refresh';
            }

            if ($associationMapping['isCascadeMerge']) {
                $cascade[] = 'merge';
            }

            if ($associationMapping['isCascadeDetach']) {
                $cascade[] = 'detach';
            }

            if (count($cascade) === 5) {
                $cascade = ['all'];
            }

            $associationMappingArray = [
                'targetEntity' => $associationMapping['targetEntity'],
                'cascade'     => $cascade,
            ];

            if (isset($associationMapping['fetch'])) {
                $associationMappingArray['fetch'] = $this->_getFetchModeString($associationMapping['fetch']);
            }

            if (isset($associationMapping['id']) && $associationMapping['id'] === true) {
                $array['id'][$name]['associationKey'] = true;
            }

            if ($associationMapping['type'] & ClassMetadataInfo::TO_ONE) {
                $joinColumns    = $associationMapping['isOwningSide'] ? $associationMapping['joinColumns'] : [];
                $newJoinColumns = [];

                foreach ($joinColumns as $joinColumn) {
                    $newJoinColumns[$joinColumn['name']]['referencedColumnName'] = $joinColumn['referencedColumnName'];

                    if (isset($joinColumn['onDelete'])) {
                        $newJoinColumns[$joinColumn['name']]['onDelete'] = $joinColumn['onDelete'];
                    }
                }

                $oneToOneMappingArray = [
                    'mappedBy'      => $associationMapping['mappedBy'],
                    'inversedBy'    => $associationMapping['inversedBy'],
                    'joinColumns'   => $newJoinColumns,
                    'orphanRemoval' => $associationMapping['orphanRemoval'],
                ];

                $associationMappingArray = array_merge($associationMappingArray, $oneToOneMappingArray);

                if ($associationMapping['type'] & ClassMetadataInfo::ONE_TO_ONE) {
                    $array['oneToOne'][$name] = $associationMappingArray;
                } else {
                    $array['manyToOne'][$name] = $associationMappingArray;
                }
            } elseif ($associationMapping['type'] === ClassMetadataInfo::ONE_TO_MANY) {
                $oneToManyMappingArray = [
                    'mappedBy'      => $associationMapping['mappedBy'],
                    'inversedBy'    => $associationMapping['inversedBy'],
                    'orphanRemoval' => $associationMapping['orphanRemoval'],
                    'orderBy'       => $associationMapping['orderBy'] ?? null,
                ];

                $associationMappingArray   = array_merge($associationMappingArray, $oneToManyMappingArray);
                $array['oneToMany'][$name] = $associationMappingArray;
            } elseif ($associationMapping['type'] === ClassMetadataInfo::MANY_TO_MANY) {
                $manyToManyMappingArray = [
                    'mappedBy'   => $associationMapping['mappedBy'],
                    'inversedBy' => $associationMapping['inversedBy'],
                    'joinTable'  => $associationMapping['joinTable'] ?? null,
                    'orderBy'    => $associationMapping['orderBy'] ?? null,
                ];

                $associationMappingArray    = array_merge($associationMappingArray, $manyToManyMappingArray);
                $array['manyToMany'][$name] = $associationMappingArray;
            }
        }

        if (isset($metadata->lifecycleCallbacks)) {
            $array['lifecycleCallbacks'] = $metadata->lifecycleCallbacks;
        }

        $array = $this->processEntityListeners($metadata, $array);

        return $this->yamlDump([$metadata->name => $array], 10);
    }

    /**
     * Dumps a PHP array to a YAML string.
     *
     * The yamlDump method, when supplied with an array, will do its best
     * to convert the array into friendly YAML.
     *
     * @param mixed[] $array  PHP array
     * @param int     $inline [optional] The level where you switch to inline YAML
     *
     * @return string A YAML string representing the original PHP array
     */
    protected function yamlDump($array, $inline = 2)
    {
        return Yaml::dump($array, $inline);
    }

    /**
     * @psalm-param array<string, mixed> $array
     *
     * @psalm-return array<string, mixed>&array{entityListeners: array<class-string, array<string, array{string}>>}
     */
    private function processEntityListeners(ClassMetadataInfo $metadata, array $array): array
    {
        if (count($metadata->entityListeners) === 0) {
            return $array;
        }

        $array['entityListeners'] = [];

        foreach ($metadata->entityListeners as $event => $entityListenerConfig) {
            $array = $this->processEntityListenerConfig($array, $entityListenerConfig, $event);
        }

        return $array;
    }

    /**
     * @psalm-param array{entityListeners: array<class-string, array<string, array{string}>>} $array
     * @psalm-param list<array{class: class-string, method: string}> $entityListenerConfig
     *
     * @psalm-return array{entityListeners: array<class-string, array<string, array{string}>>}
     */
    private function processEntityListenerConfig(
        array $array,
        array $entityListenerConfig,
        string $event
    ): array {
        foreach ($entityListenerConfig as $entityListener) {
            if (! isset($array['entityListeners'][$entityListener['class']])) {
                $array['entityListeners'][$entityListener['class']] = [];
            }

            $array['entityListeners'][$entityListener['class']][$event] = [$entityListener['method']];
        }

        return $array;
    }
}
