<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use function count;
use function implode;

/**
 * Expression class for building DQL Order By parts.
 *
 * @link    www.doctrine-project.org
 */
class OrderBy
{
    /** @var string */
    protected $preSeparator = '';

    /** @var string */
    protected $separator = ', ';

    /** @var string */
    protected $postSeparator = '';

    /** @var string[] */
    protected $allowedClasses = [];

    /** @psalm-var list<string> */
    protected $parts = [];

    /**
     * @param string|null $sort
     * @param string|null $order
     */
    public function __construct($sort = null, $order = null)
    {
        if ($sort) {
            $this->add($sort, $order);
        }
    }

    /**
     * @param string      $sort
     * @param string|null $order
     *
     * @return void
     */
    public function add($sort, $order = null)
    {
        $order         = ! $order ? 'ASC' : $order;
        $this->parts[] = $sort . ' ' . $order;
    }

    /**
     * @return int
     * @psalm-return 0|positive-int
     */
    public function count()
    {
        return count($this->parts);
    }

    /**
     * @psalm-return list<string>
     */
    public function getParts()
    {
        return $this->parts;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->preSeparator . implode($this->separator, $this->parts) . $this->postSeparator;
    }
}
