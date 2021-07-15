<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

/**
 * Expression class for building DQL and parts.
 *
 * @link    www.doctrine-project.org
 */
class Andx extends Composite
{
    /** @var string */
    protected $separator = ' AND ';

    /** @var string[] */
    protected $allowedClasses = [
        Comparison::class,
        Func::class,
        Orx::class,
        self::class,
    ];

    /** @psalm-var list<string|Comparison|Func|Orx|self> */
    protected $parts = [];

    /**
     * @psalm-return list<string|Comparison|Func|Orx|self>
     */
    public function getParts()
    {
        return $this->parts;
    }
}
