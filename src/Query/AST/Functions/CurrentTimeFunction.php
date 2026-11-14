<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * "CURRENT_TIME"
 *
 * @link    www.doctrine-project.org
 */
class CurrentTimeFunction extends FunctionNode
{
    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return $sqlWalker->getConnection()->getDatabasePlatform()->getCurrentTimeSQL();
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
