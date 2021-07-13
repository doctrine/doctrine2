<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use function constant;

/**
 * Field Builder
 *
 * @link        www.doctrine-project.com
 */
class FieldBuilder
{
    /** @var ClassMetadataBuilder */
    private $builder;

    /** @var mixed[] */
    private $mapping;

    /** @var bool */
    private $version;

    /** @var string */
    private $generatedValue;

    /** @var mixed[] */
    private $sequenceDef;

    /** @var string|null */
    private $customIdGenerator;

    /**
     * @param mixed[] $mapping
     */
    public function __construct(ClassMetadataBuilder $builder, array $mapping)
    {
        $this->builder = $builder;
        $this->mapping = $mapping;
    }

    /**
     * Sets length.
     *
     * @param int $length
     *
     * @return static
     */
    public function length($length)
    {
        $this->mapping['length'] = $length;

        return $this;
    }

    /**
     * Sets nullable.
     *
     * @param bool $flag
     *
     * @return static
     */
    public function nullable($flag = true)
    {
        $this->mapping['nullable'] = (bool) $flag;

        return $this;
    }

    /**
     * Sets Unique.
     *
     * @param bool $flag
     *
     * @return static
     */
    public function unique($flag = true)
    {
        $this->mapping['unique'] = (bool) $flag;

        return $this;
    }

    /**
     * Sets column name.
     *
     * @param string $name
     *
     * @return static
     */
    public function columnName($name)
    {
        $this->mapping['columnName'] = $name;

        return $this;
    }

    /**
     * Sets Precision.
     *
     * @param int $p
     *
     * @return static
     */
    public function precision($p)
    {
        $this->mapping['precision'] = $p;

        return $this;
    }

    /**
     * Sets scale.
     *
     * @param int $s
     *
     * @return static
     */
    public function scale($s)
    {
        $this->mapping['scale'] = $s;

        return $this;
    }

    /**
     * Sets field as primary key.
     *
     * @deprecated Use makePrimaryKey() instead
     *
     * @return FieldBuilder
     */
    public function isPrimaryKey()
    {
        return $this->makePrimaryKey();
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
     * Sets an option.
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return static
     */
    public function option($name, $value)
    {
        $this->mapping['options'][$name] = $value;

        return $this;
    }

    /**
     * @param string $strategy
     *
     * @return static
     */
    public function generatedValue($strategy = 'AUTO')
    {
        $this->generatedValue = $strategy;

        return $this;
    }

    /**
     * Sets field versioned.
     *
     * @return static
     */
    public function isVersionField()
    {
        $this->version = true;

        return $this;
    }

    /**
     * Sets Sequence Generator.
     *
     * @param string $sequenceName
     * @param int    $allocationSize
     * @param int    $initialValue
     *
     * @return static
     */
    public function setSequenceGenerator($sequenceName, $allocationSize = 1, $initialValue = 1)
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
     * @param string $def
     *
     * @return static
     */
    public function columnDefinition($def)
    {
        $this->mapping['columnDefinition'] = $def;

        return $this;
    }

    /**
     * Set the FQCN of the custom ID generator.
     * This class must extend \Doctrine\ORM\Id\AbstractIdGenerator.
     *
     * @param string $customIdGenerator
     *
     * @return $this
     */
    public function setCustomIdGenerator($customIdGenerator)
    {
        $this->customIdGenerator = (string) $customIdGenerator;

        return $this;
    }

    /**
     * Finalizes this field and attach it to the ClassMetadata.
     *
     * Without this call a FieldBuilder has no effect on the ClassMetadata.
     *
     * @return ClassMetadataBuilder
     */
    public function build()
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
            $cm->setCustomGeneratorDefinition(['class' => $this->customIdGenerator]);
        }

        return $this->builder;
    }
}
