<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

/**
 * Expression class for building DQL select statements.
 *
 * @link    www.doctrine-project.org
 */
class Select extends Base
{
    /** @var string */
    protected $preSeparator = '';

    /** @var string */
    protected $postSeparator = '';

    /** @var string[] */
    protected $allowedClasses = [Func::class];

    /** @psalm-var list<string|Func> */
    protected $parts = [];

    /**
     * @psalm-return list<string|Func>
     */
    public function getParts()
    {
        return $this->parts;
    }
}
