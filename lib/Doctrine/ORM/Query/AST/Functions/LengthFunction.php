<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;

/**
 * "LENGTH" "(" StringPrimary ")"
 */
class LengthFunction extends FunctionNode
{
    /** @var Node */
    public $stringPrimary;

    /**
     * @override
     * @inheritdoc
     */
    public function getSql(SqlWalker $sqlWalker)
    {
        return $sqlWalker->getConnection()->getDatabasePlatform()->getLengthExpression(
            $sqlWalker->walkSimpleArithmeticExpression($this->stringPrimary)
        );
    }

    /**
     * @override
     * @inheritdoc
     */
    public function parse(Parser $parser)
    {
        $parser->match(Lexer::T_IDENTIFIER);
        $parser->match(Lexer::T_OPEN_PARENTHESIS);

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(Lexer::T_CLOSE_PARENTHESIS);
    }

    /**
     * @inheritDoc
     */
    public function getReturnType() : Type
    {
        return Type::getType(Type::INTEGER);
    }
}
