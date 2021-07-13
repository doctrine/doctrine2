<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use function implode;

/**
 * Expression class for generating DQL functions.
 *
 * @link    www.doctrine-project.org
 */
class Func
{
    /** @var string */
    protected $name;

    /** @var mixed[] */
    protected $arguments;

    /**
     * Creates a function, with the given argument.
     *
     * @param string        $name
     * @param mixed[]|mixed $arguments
     * @psalm-param list<mixed>|mixed $arguments
     */
    public function __construct($name, $arguments)
    {
        $this->name      = $name;
        $this->arguments = (array) $arguments;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @psalm-return list<mixed>
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->name . '(' . implode(', ', $this->arguments) . ')';
    }
}
