<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Abstract Function Node.
 *
 * @link    www.doctrine-project.org
 */
abstract class FunctionNode extends Node
{
    /** @var string */
    public $name;

    /**
     * @param string $name
     */
    public function __construct($name)
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    abstract public function getSql(SqlWalker $sqlWalker);

    /**
     * @param SqlWalker $sqlWalker
     *
     * @return string
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkFunction($this);
    }

    /**
     * @return void
     */
    abstract public function parse(Parser $parser);
}
