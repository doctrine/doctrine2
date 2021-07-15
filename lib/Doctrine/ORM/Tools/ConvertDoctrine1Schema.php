<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\Deprecation;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Symfony\Component\Yaml\Yaml;

use function array_merge;
use function count;
use function explode;
use function file_get_contents;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * Class to help with converting Doctrine 1 schema files to Doctrine 2 mapping files
 *
 * @link    www.doctrine-project.org
 */
class ConvertDoctrine1Schema
{
    /** @var mixed[] */
    private $from;

    /** @var array<string,string> */
    private $legacyTypeMap = [
        // TODO: This list may need to be updated
        'clob' => 'text',
        'timestamp' => 'datetime',
        'enum' => 'string',
    ];

    /**
     * Constructor passes the directory or array of directories
     * to convert the Doctrine 1 schema files from.
     *
     * @param string[]|string $from
     * @psalm-param list<string>|string $from
     */
    public function __construct($from)
    {
        $this->from = (array) $from;

        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8458',
            '%s is deprecated with no replacement',
            self::class
        );
    }

    /**
     * Gets an array of ClassMetadataInfo instances from the passed
     * Doctrine 1 schema.
     *
     * @return ClassMetadataInfo[] An array of ClassMetadataInfo instances
     * @psalm-return list<ClassMetadataInfo>
     */
    public function getMetadata()
    {
        $schema = [];
        foreach ($this->from as $path) {
            if (is_dir($path)) {
                $files = glob($path . '/*.yml');
                foreach ($files as $file) {
                    $schema = array_merge($schema, (array) Yaml::parse(file_get_contents($file)));
                }
            } else {
                $schema = array_merge($schema, (array) Yaml::parse(file_get_contents($path)));
            }
        }

        $metadatas = [];
        foreach ($schema as $className => $mappingInformation) {
            $metadatas[] = $this->convertToClassMetadataInfo($className, $mappingInformation);
        }

        return $metadatas;
    }

    /**
     * @param mixed[] $mappingInformation
     * @psalm-param class-string $className
     */
    private function convertToClassMetadataInfo(
        string $className,
        array $mappingInformation
    ): ClassMetadataInfo {
        $metadata = new ClassMetadataInfo($className);

        $this->convertTableName($className, $mappingInformation, $metadata);
        $this->convertColumns($className, $mappingInformation, $metadata);
        $this->convertIndexes($className, $mappingInformation, $metadata);
        $this->convertRelations($className, $mappingInformation, $metadata);

        return $metadata;
    }

    /**
     * @param mixed[] $model
     */
    private function convertTableName(string $className, array $model, ClassMetadataInfo $metadata): void
    {
        if (isset($model['tableName']) && $model['tableName']) {
            $e = explode('.', $model['tableName']);

            if (count($e) > 1) {
                $metadata->table['schema'] = $e[0];
                $metadata->table['name']   = $e[1];
            } else {
                $metadata->table['name'] = $e[0];
            }
        }
    }

    /**
     * @param mixed[] $model
     */
    private function convertColumns(
        string $className,
        array $model,
        ClassMetadataInfo $metadata
    ): void {
        $id = false;

        if (isset($model['columns']) && $model['columns']) {
            foreach ($model['columns'] as $name => $column) {
                $fieldMapping = $this->convertColumn($className, $name, $column, $metadata);

                if (isset($fieldMapping['id']) && $fieldMapping['id']) {
                    $id = true;
                }
            }
        }

        if (! $id) {
            $fieldMapping = [
                'fieldName' => 'id',
                'columnName' => 'id',
                'type' => 'integer',
                'id' => true,
            ];
            $metadata->mapField($fieldMapping);
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
        }
    }

    /**
     * @param string|mixed[] $column
     *
     * @return mixed[]
     *
     * @throws ToolsException
     */
    private function convertColumn(
        string $className,
        string $name,
        $column,
        ClassMetadataInfo $metadata
    ): array {
        if (is_string($column)) {
            $string         = $column;
            $column         = [];
            $column['type'] = $string;
        }

        if (! isset($column['name'])) {
            $column['name'] = $name;
        }

        // check if a column alias was used (column_name as field_name)
        if (preg_match('/(\w+)\sas\s(\w+)/i', $column['name'], $matches)) {
            $name            = $matches[1];
            $column['name']  = $name;
            $column['alias'] = $matches[2];
        }

        if (preg_match('/([a-zA-Z]+)\(([0-9]+)\)/', $column['type'], $matches)) {
            $column['type']   = $matches[1];
            $column['length'] = $matches[2];
        }

        $column['type'] = strtolower($column['type']);
        // check if legacy column type (1.x) needs to be mapped to a 2.0 one
        if (isset($this->legacyTypeMap[$column['type']])) {
            $column['type'] = $this->legacyTypeMap[$column['type']];
        }

        if (! Type::hasType($column['type'])) {
            throw ToolsException::couldNotMapDoctrine1Type($column['type']);
        }

        $fieldMapping = [
            'nullable' => ! ($column['notnull'] ?? true), // Doctrine 1 columns are nullable by default
        ];

        if (isset($column['primary'])) {
            $fieldMapping['id'] = true;
        }

        $fieldMapping['fieldName']  = $column['alias'] ?? $name;
        $fieldMapping['columnName'] = $column['name'];
        $fieldMapping['type']       = $column['type'];

        if (isset($column['length'])) {
            $fieldMapping['length'] = $column['length'];
        }

        $allowed = ['precision', 'scale', 'unique', 'options', 'version'];

        foreach ($column as $key => $value) {
            if (in_array($key, $allowed)) {
                $fieldMapping[$key] = $value;
            }
        }

        $metadata->mapField($fieldMapping);

        if (isset($column['autoincrement'])) {
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
        } elseif (isset($column['sequence'])) {
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_SEQUENCE);

            $definition = [
                'sequenceName' => (string) (is_array($column['sequence']) ? $column['sequence']['name'] : $column['sequence']),
            ];

            if (isset($column['sequence']['size'])) {
                $definition['allocationSize'] = (int) $column['sequence']['size'];
            }

            if (isset($column['sequence']['value'])) {
                $definition['initialValue'] = (int) $column['sequence']['value'];
            }

            $metadata->setSequenceGeneratorDefinition($definition);
        }

        return $fieldMapping;
    }

    /**
     * @param mixed[] $model
     */
    private function convertIndexes(
        string $className,
        array $model,
        ClassMetadataInfo $metadata
    ): void {
        if (empty($model['indexes'])) {
            return;
        }

        foreach ($model['indexes'] as $name => $index) {
            $type = isset($index['type']) && $index['type'] === 'unique'
                ? 'uniqueConstraints' : 'indexes';

            $metadata->table[$type][$name] = [
                'columns' => $index['fields'],
            ];
        }
    }

    /**
     * @param mixed[] $model
     */
    private function convertRelations(
        string $className,
        array $model,
        ClassMetadataInfo $metadata
    ): void {
        if (empty($model['relations'])) {
            return;
        }

        $inflector = InflectorFactory::create()->build();

        foreach ($model['relations'] as $name => $relation) {
            if (! isset($relation['alias'])) {
                $relation['alias'] = $name;
            }

            if (! isset($relation['class'])) {
                $relation['class'] = $name;
            }

            if (! isset($relation['local'])) {
                $relation['local'] = $inflector->tableize($relation['class']);
            }

            if (! isset($relation['foreign'])) {
                $relation['foreign'] = 'id';
            }

            if (! isset($relation['foreignAlias'])) {
                $relation['foreignAlias'] = $className;
            }

            if (isset($relation['refClass'])) {
                $type        = 'many';
                $foreignType = 'many';
                $joinColumns = [];
            } else {
                $type        = $relation['type'] ?? 'one';
                $foreignType = $relation['foreignType'] ?? 'many';
                $joinColumns = [
                    [
                        'name' => $relation['local'],
                        'referencedColumnName' => $relation['foreign'],
                        'onDelete' => $relation['onDelete'] ?? null,
                    ],
                ];
            }

            if ($type === 'one' && $foreignType === 'one') {
                $method = 'mapOneToOne';
            } elseif ($type === 'many' && $foreignType === 'many') {
                $method = 'mapManyToMany';
            } else {
                $method = 'mapOneToMany';
            }

            $associationMapping                 = [];
            $associationMapping['fieldName']    = $relation['alias'];
            $associationMapping['targetEntity'] = $relation['class'];
            $associationMapping['mappedBy']     = $relation['foreignAlias'];
            $associationMapping['joinColumns']  = $joinColumns;

            $metadata->$method($associationMapping);
        }
    }
}
