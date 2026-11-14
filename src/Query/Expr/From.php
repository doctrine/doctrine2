<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use Override;
use Stringable;

/**
 * Expression class for DQL from.
 *
 * @link    www.doctrine-project.org
 */
class From implements Stringable
{
    /**
     * @param class-string $from  The class name.
     * @param string       $alias The alias of the class.
     */
    public function __construct(
        protected string $from,
        protected string $alias,
        protected string|null $indexBy = null,
    ) {
    }

    /** @return class-string */
    public function getFrom(): string
    {
        return $this->from;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getIndexBy(): string|null
    {
        return $this->indexBy;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->from . ' ' . $this->alias .
                ($this->indexBy ? ' INDEX BY ' . $this->indexBy : '');
    }
}
