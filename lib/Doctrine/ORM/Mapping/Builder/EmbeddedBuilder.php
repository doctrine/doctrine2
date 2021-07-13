<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

/**
 * Embedded Builder
 *
 * @link        www.doctrine-project.com
 */
class EmbeddedBuilder
{
    /** @var ClassMetadataBuilder */
    private $builder;

    /** @var mixed[] */
    private $mapping;

    /**
     * @param mixed[] $mapping
     */
    public function __construct(ClassMetadataBuilder $builder, array $mapping)
    {
        $this->builder = $builder;
        $this->mapping = $mapping;
    }

    /**
     * Sets the column prefix for all of the embedded columns.
     *
     * @param string $columnPrefix
     *
     * @return $this
     */
    public function setColumnPrefix($columnPrefix)
    {
        $this->mapping['columnPrefix'] = $columnPrefix;

        return $this;
    }

    /**
     * Finalizes this embeddable and attach it to the ClassMetadata.
     *
     * Without this call an EmbeddedBuilder has no effect on the ClassMetadata.
     *
     * @return ClassMetadataBuilder
     */
    public function build()
    {
        $cm = $this->builder->getClassMetadata();

        $cm->mapEmbedded($this->mapping);

        return $this->builder;
    }
}
