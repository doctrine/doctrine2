<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use Override;

/**
 * ManyToMany Association Builder
 *
 * @link        www.doctrine-project.com
 */
class ManyToManyAssociationBuilder extends OneToManyAssociationBuilder
{
    private string|null $joinTableName = null;

    /** @var mixed[] */
    private array $inverseJoinColumns = [];

    /** @return $this */
    public function setJoinTable(string $name): static
    {
        $this->joinTableName = $name;

        return $this;
    }

    /**
     * Add Join Columns.
     *
     * @return $this
     */
    #[Override]
    public function addJoinColumn(
        string $columnName,
        string $referencedColumnName,
        bool $nullable = true,
        bool $unique = false,
        string|null $onDelete = null,
        string|null $columnDef = null,
    ): static {
        $this->joinColumns[] = [
            'name' => $columnName,
            'referencedColumnName' => $referencedColumnName,
            'unique' => $unique,
            'onDelete' => $onDelete,
            'columnDefinition' => $columnDef,
        ];

        return $this;
    }

    /**
     * Adds Inverse Join Columns.
     *
     * @return $this
     */
    public function addInverseJoinColumn(
        string $columnName,
        string $referencedColumnName,
        bool $nullable = true,
        bool $unique = false,
        string|null $onDelete = null,
        string|null $columnDef = null,
    ): static {
        $this->inverseJoinColumns[] = [
            'name' => $columnName,
            'referencedColumnName' => $referencedColumnName,
            'unique' => $unique,
            'onDelete' => $onDelete,
            'columnDefinition' => $columnDef,
        ];

        return $this;
    }

    #[Override]
    public function build(): ClassMetadataBuilder
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
