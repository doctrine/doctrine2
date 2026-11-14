<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * "CONCAT" "(" StringPrimary "," StringPrimary {"," StringPrimary }* ")"
 *
 * @link    www.doctrine-project.org
 */
class ConcatFunction extends FunctionNode
{
    public Node $firstStringPrimary;
    public Node $secondStringPrimary;

    /** @phpstan-var list<Node> */
    public array $concatExpressions = [];

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        $args = [];

        foreach ($this->concatExpressions as $expression) {
            $args[] = $sqlWalker->walkStringPrimary($expression);
        }

        return $platform->getConcatExpression(...$args);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->firstStringPrimary  = $parser->StringPrimary();
        $this->concatExpressions[] = $this->firstStringPrimary;

        $parser->match(TokenType::T_COMMA);

        $this->secondStringPrimary = $parser->StringPrimary();
        $this->concatExpressions[] = $this->secondStringPrimary;

        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->concatExpressions[] = $parser->StringPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
