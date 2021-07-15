<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * LikeExpression ::= StringExpression ["NOT"] "LIKE" string ["ESCAPE" char]
 *
 * @link    www.doctrine-project.org
 */
class LikeExpression extends Node
{
    /** @var bool */
    public $not;

    /** @var Node */
    public $stringExpression;

    /** @var InputParameter */
    public $stringPattern;

    /** @var Literal|null */
    public $escapeChar;

    /**
     * @param Node           $stringExpression
     * @param InputParameter $stringPattern
     * @param Literal|null   $escapeChar
     */
    public function __construct($stringExpression, $stringPattern, $escapeChar = null)
    {
        $this->stringExpression = $stringExpression;
        $this->stringPattern    = $stringPattern;
        $this->escapeChar       = $escapeChar;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkLikeExpression($this);
    }
}
