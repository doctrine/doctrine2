<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;

class AssociationBuilder
{
    /** @var ClassMetadataBuilder */
    protected $builder;

    /** @var mixed[] */
    protected $mapping;

    /** @var mixed[]|null */
    protected $joinColumns;

    /** @var int */
    protected $type;

    /**
     * @param mixed[] $mapping
     * @param int     $type
     */
    public function __construct(ClassMetadataBuilder $builder, array $mapping, $type)
    {
        $this->builder = $builder;
        $this->mapping = $mapping;
        $this->type    = $type;
    }

    /**
     * @param string $fieldName
     *
     * @return static
     */
    public function mappedBy($fieldName)
    {
        $this->mapping['mappedBy'] = $fieldName;

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return static
     */
    public function inversedBy($fieldName)
    {
        $this->mapping['inversedBy'] = $fieldName;

        return $this;
    }

    /**
     * @return static
     */
    public function cascadeAll()
    {
        $this->mapping['cascade'] = ['ALL'];

        return $this;
    }

    /**
     * @return static
     */
    public function cascadePersist()
    {
        $this->mapping['cascade'][] = 'persist';

        return $this;
    }

    /**
     * @return static
     */
    public function cascadeRemove()
    {
        $this->mapping['cascade'][] = 'remove';

        return $this;
    }

    /**
     * @return static
     */
    public function cascadeMerge()
    {
        $this->mapping['cascade'][] = 'merge';

        return $this;
    }

    /**
     * @return static
     */
    public function cascadeDetach()
    {
        $this->mapping['cascade'][] = 'detach';

        return $this;
    }

    /**
     * @return static
     */
    public function cascadeRefresh()
    {
        $this->mapping['cascade'][] = 'refresh';

        return $this;
    }

    /**
     * @return static
     */
    public function fetchExtraLazy()
    {
        $this->mapping['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;

        return $this;
    }

    /**
     * @return static
     */
    public function fetchEager()
    {
        $this->mapping['fetch'] = ClassMetadata::FETCH_EAGER;

        return $this;
    }

    /**
     * @return static
     */
    public function fetchLazy()
    {
        $this->mapping['fetch'] = ClassMetadata::FETCH_LAZY;

        return $this;
    }

    /**
     * Add Join Columns.
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
    public function addJoinColumn($columnName, $referencedColumnName, $nullable = true, $unique = false, $onDelete = null, $columnDef = null)
    {
        $this->joinColumns[] = [
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
     * Sets field as primary key.
     *
     * @return static
     */
    public function makePrimaryKey()
    {
        $this->mapping['id'] = true;

        return $this;
    }

    /**
     * Removes orphan entities when detached from their parent.
     *
     * @return static
     */
    public function orphanRemoval()
    {
        $this->mapping['orphanRemoval'] = true;

        return $this;
    }

    /**
     * @return ClassMetadataBuilder
     *
     * @throws InvalidArgumentException
     */
    public function build()
    {
        $mapping = $this->mapping;
        if ($this->joinColumns) {
            $mapping['joinColumns'] = $this->joinColumns;
        }

        $cm = $this->builder->getClassMetadata();
        if ($this->type === ClassMetadata::MANY_TO_ONE) {
            $cm->mapManyToOne($mapping);
        } elseif ($this->type === ClassMetadata::ONE_TO_ONE) {
            $cm->mapOneToOne($mapping);
        } else {
            throw new InvalidArgumentException('Type should be a ToOne Association here');
        }

        return $this->builder;
    }
}
