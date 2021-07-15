<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;

use function strtolower;

/**
 * "DATE_ADD(date1, interval, unit)"
 *
 * @link    www.doctrine-project.org
 */
class DateSubFunction extends DateAddFunction
{
    /**
     * @override
     * @inheritdoc
     */
    public function getSql(SqlWalker $sqlWalker)
    {
        switch (strtolower($this->unit->value)) {
            case 'second':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubSecondsExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            case 'minute':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubMinutesExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            case 'hour':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubHourExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            case 'day':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubDaysExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            case 'week':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubWeeksExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            case 'month':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubMonthExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            case 'year':
                return $sqlWalker->getConnection()->getDatabasePlatform()->getDateSubYearsExpression(
                    $this->firstDateExpression->dispatch($sqlWalker),
                    $this->intervalExpression->dispatch($sqlWalker)
                );

            default:
                throw QueryException::semanticalError(
                    'DATE_SUB() only supports units of type second, minute, hour, day, week, month and year.'
                );
        }
    }
}
