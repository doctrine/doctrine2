<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\ASTException;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Override;

use function strtolower;

/**
 * "DATE_SUB(date1, interval, unit)"
 *
 * @link    www.doctrine-project.org
 */
class DateSubFunction extends DateAddFunction
{
    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return match (strtolower((string) $this->unit->value)) {
            'second' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubSecondsExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'minute' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubMinutesExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'hour' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubHourExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'day' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubDaysExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'week' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubWeeksExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'month' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubMonthExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            'year' => $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubYearsExpression(
                $this->firstDateExpression->dispatch($sqlWalker),
                $this->dispatchIntervalExpression($sqlWalker),
            ),
            default => throw QueryException::semanticalError(
                'DATE_SUB() only supports units of type second, minute, hour, day, week, month and year.',
            ),
        };
    }

    /** @throws ASTException */
    private function dispatchIntervalExpression(SqlWalker $sqlWalker): string
    {
        return $this->intervalExpression->dispatch($sqlWalker);
    }
}
