<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

/**
 * ManyToMany Association Builder
 *
 * @link        www.doctrine-project.com
 */
class ManyToManyAssociationBuilder extends OneToManyAssociationBuilder
{
    /** @var string|null */
    private $joinTableName;

    /** @var mixed[] */
    private $inverseJoinColumns = [];

    /**
     * @param string $name
     *
     * @return static
     */
    public function setJoinTable($name)
    {
        $this->joinTableName = $name;

        return $this;
    }

    /**
     * Adds Inverse Join Columns.
     *
     * @param string      $columnName
     * @param string      $referencedColumnName
     * @param bool        $nullable
     * @param bool        $unique
     * @param string|null $onDelete
     * @param string|null $columnDef
     *
     * @return static
     */
    public function addInverseJoinColumn($columnName, $referencedColumnName, $nullable = true, $unique = false, $onDelete = null, $columnDef = null)
    {
        $this->inverseJoinColumns[] = [
            'name' => $columnName,
            'referencedColumnName' => $referencedColumnName,
            'nullable' => $nullable,
            'unique' => $unique,
            'onDelete' => $onDelete,
            'columnDefinition' => $columnDef,
        ];

        return $this;
    }

    /**
     * @return ClassMetadataBuilder
     */
    public function build()
    {
        $mapping              = $this->mapping;
        $mapping['joinTable'] = [];
        if ($this->joinColumns) {
            $mapping['joinTable']['joinColumns'] = $this->joinColumns;
        }

        if ($this->inverseJoinColumns) {
            $mapping['joinTable']['inverseJoinColumns'] = $this->inverseJoinColumns;
        }

        if ($this->joinTableName) {
            $mapping['joinTable']['name'] = $this->joinTableName;
        }

        $cm = $this->builder->getClassMetadata();
        $cm->mapManyToMany($mapping);

        return $this->builder;
    }
}
