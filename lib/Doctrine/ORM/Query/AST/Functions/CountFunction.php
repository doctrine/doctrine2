<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\AggregateExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * "COUNT" "(" ["DISTINCT"] StringPrimary ")"
 */
final class CountFunction extends FunctionNode
{
    /** @var AggregateExpression */
    private $aggregateExpression;

    /**
     * @inheritDoc
     */
    public function getSql(SqlWalker $sqlWalker) : string
    {
        return $this->aggregateExpression->dispatch($sqlWalker);
    }

    /**
     * @inheritDoc
     */
    public function parse(Parser $parser) : void
    {
        $this->aggregateExpression = $parser->AggregateExpression();
    }

    /**
     * @inheritDoc
     */
    public function getReturnType(): string
    {
        return \Doctrine\DBAL\Types\Type::INTEGER;
    }
}
