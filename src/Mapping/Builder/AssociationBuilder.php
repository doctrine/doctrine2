<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;

class AssociationBuilder
{
    /** @var mixed[]|null */
    protected array|null $joinColumns = null;

    /** @param mixed[] $mapping */
    public function __construct(
        protected readonly ClassMetadataBuilder $builder,
        protected array $mapping,
        protected readonly int $type,
    ) {
    }

    /**
     * Stores the association under its name in the given dynamic properties container
     * instead of in a property of the same name.
     *
     * @see ClassMetadataBuilder::addDynamicProperties()
     *
     * @return $this
     */
    public function inDynamicProperties(string $container): static
    {
        $this->mapping['dynamicProperty'] = $container;

        return $this;
    }

    /** @return $this */
    public function mappedBy(string $fieldName): static
    {
        $this->mapping['mappedBy'] = $fieldName;

        return $this;
    }

    /** @return $this */
    public function inversedBy(string $fieldName): static
    {
        $this->mapping['inversedBy'] = $fieldName;

        return $this;
    }

    /** @return $this */
    public function cascadeAll(): static
    {
        $this->mapping['cascade'] = ['ALL'];

        return $this;
    }

    /** @return $this */
    public function cascadePersist(): static
    {
        $this->mapping['cascade'][] = 'persist';

        return $this;
    }

    /** @return $this */
    public function cascadeRemove(): static
    {
        $this->mapping['cascade'][] = 'remove';

        return $this;
    }

    /** @return $this */
    public function cascadeDetach(): static
    {
        $this->mapping['cascade'][] = 'detach';

        return $this;
    }

    /** @return $this */
    public function cascadeRefresh(): static
    {
        $this->mapping['cascade'][] = 'refresh';

        return $this;
    }

    /** @return $this */
    public function fetchExtraLazy(): static
    {
        $this->mapping['fetch'] = ClassMetadata::FETCH_EXTRA_LAZY;

        return $this;
    }

    /** @return $this */
    public function fetchEager(): static
    {
        $this->mapping['fetch'] = ClassMetadata::FETCH_EAGER;

        return $this;
    }

    /** @return $this */
    public function fetchLazy(): static
    {
        $this->mapping['fetch'] = ClassMetadata::FETCH_LAZY;

        return $this;
    }

    /**
     * Add Join Columns.
     *
     * @return $this
     */
    public function addJoinColumn(
        string $columnName,
        string $referencedColumnName,
        bool $nullable = true,
        bool $unique = false,
        string|null $onDelete = null,
        string|null $columnDef = null,
    ): static {
        if ($this->mapping['id'] ?? false) {
            $nullable = null;
        }

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
     * @return $this
     */
    public function makePrimaryKey(): static
    {
        $this->mapping['id'] = true;
        foreach ($this->joinColumns ?? [] as $i => $joinColumn) {
            $this->joinColumns[$i]['nullable'] = null;
        }

        return $this;
    }

    /**
     * Removes orphan entities when detached from their parent.
     *
     * @return $this
     */
    public function orphanRemoval(): static
    {
        $this->mapping['orphanRemoval'] = true;

        return $this;
    }

    /** @throws InvalidArgumentException */
    public function build(): ClassMetadataBuilder
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
