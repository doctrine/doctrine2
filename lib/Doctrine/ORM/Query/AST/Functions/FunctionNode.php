<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\TypedExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Abstract Function Node.
 */
abstract class FunctionNode extends Node implements TypedExpression
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

    public function getReturnType() : Type
    {
        return Type::getType(Type::STRING);
    }
}
