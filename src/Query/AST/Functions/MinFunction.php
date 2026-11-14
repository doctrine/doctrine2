<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\AggregateExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * "MIN" "(" ["DISTINCT"] StringPrimary ")"
 */
final class MinFunction extends FunctionNode
{
    private AggregateExpression $aggregateExpression;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return $this->aggregateExpression->dispatch($sqlWalker);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $this->aggregateExpression = $parser->AggregateExpression();
    }
}
