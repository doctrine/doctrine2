<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

use function call_user_func_array;

/**
 * "CONCAT" "(" StringPrimary "," StringPrimary {"," StringPrimary }* ")"
 *
 * @link    www.doctrine-project.org
 */
class ConcatFunction extends FunctionNode
{
    /** @var Node */
    public $firstStringPrimary;

    /** @var Node */
    public $secondStringPrimary;

    /** @psalm-var list<Node> */
    public $concatExpressions = [];

    /**
     * @override
     * @inheritdoc
     */
    public function getSql(SqlWalker $sqlWalker)
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        $args = [];

        foreach ($this->concatExpressions as $expression) {
            $args[] = $sqlWalker->walkStringPrimary($expression);
        }

        return $platform->getConcatExpression(...$args);
    }

    /**
     * @override
     * @inheritdoc
     */
    public function parse(Parser $parser)
    {
        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        $this->firstStringPrimary  = $parser->StringPrimary();
        $this->concatExpressions[] = $this->firstStringPrimary;

        $parser->match(Lexer::T_COMMA);

        $this->secondStringPrimary = $parser->StringPrimary();
        $this->concatExpressions[] = $this->secondStringPrimary;

        while ($parser->getLexer()->isNextToken(Lexer::T_COMMA)) {
            $parser->match(Lexer::T_COMMA);
            $this->concatExpressions[] = $parser->StringPrimary();
        }

        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }
}
