<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

/**
 * Expression class for DQL from.
 *
 * @link    www.doctrine-project.org
 */
class From
{
    /** @var string */
    protected $from;

    /** @var string */
    protected $alias;

    /** @var string */
    protected $indexBy;

    /**
     * @param string $from    The class name.
     * @param string $alias   The alias of the class.
     * @param string $indexBy The index for the from.
     */
    public function __construct($from, $alias, $indexBy = null)
    {
        $this->from    = $from;
        $this->alias   = $alias;
        $this->indexBy = $indexBy;
    }

    /**
     * @return string
     */
    public function getFrom()
    {
        return $this->from;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getIndexBy()
    {
        return $this->indexBy;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->from . ' ' . $this->alias .
                ($this->indexBy ? ' INDEX BY ' . $this->indexBy : '');
    }
}
