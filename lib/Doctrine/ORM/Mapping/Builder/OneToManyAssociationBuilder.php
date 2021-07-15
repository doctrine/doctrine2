<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

/**
 * OneToMany Association Builder
 *
 * @link        www.doctrine-project.com
 */
class OneToManyAssociationBuilder extends AssociationBuilder
{
    /**
     * @psalm-param array<string, string> $fieldNames
     *
     * @return static
     */
    public function setOrderBy(array $fieldNames)
    {
        $this->mapping['orderBy'] = $fieldNames;

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return static
     */
    public function setIndexBy($fieldName)
    {
        $this->mapping['indexBy'] = $fieldName;

        return $this;
    }

    /**
     * @return ClassMetadataBuilder
     */
    public function build()
    {
        $mapping = $this->mapping;
        if ($this->joinColumns) {
            $mapping['joinColumns'] = $this->joinColumns;
        }

        $cm = $this->builder->getClassMetadata();
        $cm->mapOneToMany($mapping);

        return $this->builder;
    }
}
