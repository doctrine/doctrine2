<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use function trim;

/**
 * Defines a Query Parameter.
 *
 * @link    www.doctrine-project.org
 */
class Parameter
{
    /**
     * Returns the internal representation of a parameter name.
     *
     * @param string|int $name The parameter name or position.
     *
     * @return string The normalized parameter name.
     */
    public static function normalizeName($name)
    {
        return trim((string) $name, ':');
    }

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
     * Whether the parameter type was explicitly specified or not
     *
     * @var bool
     */
    private $typeSpecified;

    /**
     * @param string $name  Parameter name
     * @param mixed  $value Parameter value
     * @param mixed  $type  Parameter type
     */
    public function __construct($name, $value, $type = null)
    {
        $this->name          = self::normalizeName($name);
        $this->typeSpecified = $type !== null;

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
     *
     * @return void
     */
    public function setValue($value, $type = null)
    {
        $this->value = $value;
        $this->type  = $type ?: ParameterTypeInferer::inferType($value);
    }

    public function typeWasSpecified(): bool
    {
        return $this->typeSpecified;
    }
}
