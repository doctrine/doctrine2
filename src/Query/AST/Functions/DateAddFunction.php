<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\ASTException;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

use function strtolower;

/**
 * "DATE_ADD" "(" ArithmeticPrimary "," ArithmeticPrimary "," StringPrimary ")"
 *
 * @link    www.doctrine-project.org
 */
class DateAddFunction extends FunctionNode
{
    public Node $firstDateExpression;
    public Node $intervalExpression;
    public Node $unit;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return match (strtolower((string) $this->unit->value)) {
            'second' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddSecondsExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'minute' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddMinutesExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'hour' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddHourExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'day' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddDaysExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'week' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddWeeksExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'month' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddMonthExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'year' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateAddYearsExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            default => throw QueryException::semanticalError(
                'DATE_ADD() only supports units of type second, minute, hour, day, week, month and year.',
            ),
        };
    }

    /** @throws ASTException */
    private function dispatchIntervalExpression(SqlWalker $sqlWalker): string
    {
        return $this->intervalExpression->dispatch($sqlWalker);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->firstDateExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->intervalExpression = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->unit = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
