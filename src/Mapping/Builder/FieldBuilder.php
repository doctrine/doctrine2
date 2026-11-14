<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use Doctrine\ORM\Mapping\ClassMetadata;

use function constant;

/**
 * Field Builder
 *
 * @link        www.doctrine-project.com
 */
class FieldBuilder
{
    private bool $version               = false;
    private string|null $generatedValue = null;

    /** @var mixed[]|null */
    private array|null $sequenceDef = null;

    private string|null $customIdGenerator = null;

    /** @param mixed[] $mapping */
    public function __construct(
        private readonly ClassMetadataBuilder $builder,
        private array $mapping,
    ) {
    }

    /**
     * Sets length.
     *
     * @return $this
     */
    public function length(int $length): static
    {
        $this->mapping['length'] = $length;

        return $this;
    }

    /**
     * Sets nullable.
     *
     * @return $this
     */
    public function nullable(bool $flag = true): static
    {
        $this->mapping['nullable'] = $flag;

        return $this;
    }

    /**
     * Sets Unique.
     *
     * @return $this
     */
    public function unique(bool $flag = true): static
    {
        $this->mapping['unique'] = $flag;

        return $this;
    }

    /**
     * Sets indexed.
     *
     * @return $this
     */
    public function index(bool $flag = true): static
    {
        $this->mapping['index'] = $flag;

        return $this;
    }

    /**
     * Sets column name.
     *
     * @return $this
     */
    public function columnName(string $name): static
    {
        $this->mapping['columnName'] = $name;

        return $this;
    }

    /**
     * Sets Precision.
     *
     * @return $this
     */
    public function precision(int $p): static
    {
        $this->mapping['precision'] = $p;

        return $this;
    }

    /**
     * Sets insertable.
     *
     * @return $this
     */
    public function insertable(bool $flag = true): self
    {
        if (! $flag) {
            $this->mapping['notInsertable'] = true;
        }

        return $this;
    }

    /**
     * Sets updatable.
     *
     * @return $this
     */
    public function updatable(bool $flag = true): self
    {
        if (! $flag) {
            $this->mapping['notUpdatable'] = true;
        }

        return $this;
    }

    /**
     * Sets scale.
     *
     * @return $this
     */
    public function scale(int $s): static
    {
        $this->mapping['scale'] = $s;

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

        return $this;
    }

    /**
     * Sets an option.
     *
     * @return $this
     */
    public function option(string $name, mixed $value): static
    {
        $this->mapping['options'][$name] = $value;

        return $this;
    }

    /** @return $this */
    public function generatedValue(string $strategy = 'AUTO'): static
    {
        $this->generatedValue = $strategy;

        return $this;
    }

    /**
     * Sets field versioned.
     *
     * @return $this
     */
    public function isVersionField(): static
    {
        $this->version = true;

        return $this;
    }

    /**
     * Sets Sequence Generator.
     *
     * @return $this
     */
    public function setSequenceGenerator(string $sequenceName, int $allocationSize = 1, int $initialValue = 1): static
    {
        $this->sequenceDef = [
            'sequenceName' => $sequenceName,
            'allocationSize' => $allocationSize,
            'initialValue' => $initialValue,
        ];

        return $this;
    }

    /**
     * Sets column definition.
     *
     * @return $this
     */
    public function columnDefinition(string $def): static
    {
        $this->mapping['columnDefinition'] = $def;

        return $this;
    }

    /**
     * Set the FQCN of the custom ID generator.
     * This class must extend \Doctrine\ORM\Id\AbstractIdGenerator.
     *
     * @return $this
     */
    public function setCustomIdGenerator(string $customIdGenerator): static
    {
        $this->customIdGenerator = $customIdGenerator;

        return $this;
    }

    /**
     * Finalizes this field and attach it to the ClassMetadata.
     *
     * Without this call a FieldBuilder has no effect on the ClassMetadata.
     */
    public function build(): ClassMetadataBuilder
    {
        $cm = $this->builder->getClassMetadata();
        if ($this->generatedValue) {
            $cm->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_' . $this->generatedValue));
        }

        if ($this->version) {
            $cm->setVersionMapping($this->mapping);
        }

        $cm->mapField($this->mapping);
        if ($this->sequenceDef) {
            $cm->setSequenceGeneratorDefinition($this->sequenceDef);
        }

        if ($this->customIdGenerator) {
            if ($this->generatedValue === null) {
                $cm->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_CUSTOM);
            }

            $cm->setCustomGeneratorDefinition(['class' => $this->customIdGenerator]);
        }

        return $this->builder;
    }
}
