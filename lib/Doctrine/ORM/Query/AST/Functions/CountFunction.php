<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\AST\AggregateExpression;
use Doctrine\ORM\Query\AST\TypedExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * "COUNT" "(" ["DISTINCT"] StringPrimary ")"
 */
final class CountFunction extends FunctionNode implements TypedExpression
{
    /** @var AggregateExpression */
    private $aggregateExpression;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return $this->aggregateExpression->dispatch($sqlWalker);
    }

    public function parse(Parser $parser): void
    {
        $this->aggregateExpression = $parser->AggregateExpression();
    }

    public function getReturnType(): Type
    {
        return Type::getType(Types::INTEGER);
    }
}
