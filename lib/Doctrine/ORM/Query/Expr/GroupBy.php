<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

/**
 * Expression class for building DQL Group By parts.
 *
 * @link    www.doctrine-project.org
 */
class GroupBy extends Base
{
    /** @var string */
    protected $preSeparator = '';

    /** @var string */
    protected $postSeparator = '';

    /** @psalm-var list<string> */
    protected $parts = [];

    /**
     * @psalm-return list<string>
     */
    public function getParts()
    {
        return $this->parts;
    }
}
