<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use function trim;

/**
 * Defines a Query Parameter.
 */
class Parameter
{
    /**
     * The parameter name.
     *
     * @var string
     */
    private $name;

    /**
     * The parameter value.
     *
     * @var mixed
     */
    private $value;

    /**
     * The parameter type.
     *
     * @var mixed
     */
    private $type;

    /**
     * @param string $name  Parameter name
     * @param mixed  $value Parameter value
     * @param mixed  $type  Parameter type
     */
    public function __construct($name, $value, $type = null)
    {
        $name = (string) $name;
        if (preg_match('/^:|:$/', $name)) {
            throw new \InvalidArgumentException('A parameter name cannot start or end with ":".');
        }

        $this->name = $name;

        $this->setValue($value, $type);
    }

    /**
     * Retrieves the Parameter name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Retrieves the Parameter value.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Retrieves the Parameter type.
     *
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Defines the Parameter value.
     *
     * @param mixed $value Parameter value.
     * @param mixed $type  Parameter type.
     */
    public function setValue($value, $type = null)
    {
        $this->value = $value;
        $this->type  = $type ?: ParameterTypeInferer::inferType($value);
    }
}
