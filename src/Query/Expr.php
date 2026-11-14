<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\Internal\NoUnknownNamedArguments;
use SortDirection;
use Traversable;

use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_iterable;
use function iterator_to_array;
use function str_replace;

/**
 * This class is used to generate DQL expressions via a set of PHP static functions.
 *
 * @link    www.doctrine-project.org
 *
 * @todo Rename: ExpressionBuilder
 */
class Expr
{
    use NoUnknownNamedArguments;

    /**
     * Creates a conjunction of the given boolean expressions.
     *
     * Example:
     *
     *     [php]
     *     // (u.type = ?1) AND (u.role = ?2)
     *     $expr->andX($expr->eq('u.type', ':1'), $expr->eq('u.role', ':2'));
     *
     * @param Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string ...$x Optional clause. Defaults to null,
     *                                                                  but requires at least one defined
     *                                                                  when converting to string.
     *
     * @no-named-arguments
     */
    public function andX(Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string ...$x): Expr\Andx
    {
        self::validateVariadicParameter($x);

        return new Expr\Andx($x);
    }

    /**
     * Creates a disjunction of the given boolean expressions.
     *
     * Example:
     *
     *     [php]
     *     // (u.type = ?1) OR (u.role = ?2)
     *     $q->where($q->expr()->orX('u.type = ?1', 'u.role = ?2'));
     *
     * @param Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string ...$x Optional clause. Defaults to null,
     *                                                                  but requires at least one defined
     *                                                                  when converting to string.
     *
     * @no-named-arguments
     */
    public function orX(Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string ...$x): Expr\Orx
    {
        self::validateVariadicParameter($x);

        return new Expr\Orx($x);
    }

    /**
     * Creates an ASCending order expression.
     */
    public function asc(mixed $expr): Expr\OrderBy
    {
        return new Expr\OrderBy($expr, SortDirection::Ascending);
    }

    /**
     * Creates a DESCending order expression.
     */
    public function desc(mixed $expr): Expr\OrderBy
    {
        return new Expr\OrderBy($expr, SortDirection::Descending);
    }

    /**
     * Creates an equality comparison expression with the given arguments.
     *
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> = <right expr>. Example:
     *
     *     [php]
     *     // u.id = ?1
     *     $expr->eq('u.id', '?1');
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function eq(mixed $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, Expr\Comparison::EQ, $y);
    }

    /**
     * Creates an instance of Expr\Comparison, with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> <> <right expr>. Example:
     *
     *     [php]
     *     // u.id <> ?1
     *     $q->where($q->expr()->neq('u.id', '?1'));
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function neq(mixed $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, Expr\Comparison::NEQ, $y);
    }

    /**
     * Creates an instance of Expr\Comparison, with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> < <right expr>. Example:
     *
     *     [php]
     *     // u.id < ?1
     *     $q->where($q->expr()->lt('u.id', '?1'));
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function lt(mixed $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, Expr\Comparison::LT, $y);
    }

    /**
     * Creates an instance of Expr\Comparison, with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> <= <right expr>. Example:
     *
     *     [php]
     *     // u.id <= ?1
     *     $q->where($q->expr()->lte('u.id', '?1'));
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function lte(mixed $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, Expr\Comparison::LTE, $y);
    }

    /**
     * Creates an instance of Expr\Comparison, with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> > <right expr>. Example:
     *
     *     [php]
     *     // u.id > ?1
     *     $q->where($q->expr()->gt('u.id', '?1'));
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function gt(mixed $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, Expr\Comparison::GT, $y);
    }

    /**
     * Creates an instance of Expr\Comparison, with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> >= <right expr>. Example:
     *
     *     [php]
     *     // u.id >= ?1
     *     $q->where($q->expr()->gte('u.id', '?1'));
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function gte(mixed $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, Expr\Comparison::GTE, $y);
    }

    /**
     * Creates an instance of AVG() function, with the given argument.
     *
     * @param mixed $x Argument to be used in AVG() function.
     */
    public function avg(mixed $x): Expr\Func
    {
        return new Expr\Func('AVG', [$x]);
    }

    /**
     * Creates an instance of MAX() function, with the given argument.
     *
     * @param mixed $x Argument to be used in MAX() function.
     */
    public function max(mixed $x): Expr\Func
    {
        return new Expr\Func('MAX', [$x]);
    }

    /**
     * Creates an instance of MIN() function, with the given argument.
     *
     * @param mixed $x Argument to be used in MIN() function.
     */
    public function min(mixed $x): Expr\Func
    {
        return new Expr\Func('MIN', [$x]);
    }

    /**
     * Creates an instance of COUNT() function, with the given argument.
     *
     * @param mixed $x Argument to be used in COUNT() function.
     */
    public function count(mixed $x): Expr\Func
    {
        return new Expr\Func('COUNT', [$x]);
    }

    /**
     * Creates an instance of COUNT(DISTINCT) function, with the given argument.
     *
     * @param mixed ...$x Argument to be used in COUNT(DISTINCT) function.
     *
     * @no-named-arguments
     */
    public function countDistinct(mixed ...$x): string
    {
        self::validateVariadicParameter($x);

        return 'COUNT(DISTINCT ' . implode(', ', $x) . ')';
    }

    /**
     * Creates an instance of EXISTS() function, with the given DQL Subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in EXISTS() function.
     */
    public function exists(mixed $subquery): Expr\Func
    {
        return new Expr\Func('EXISTS', [$subquery]);
    }

    /**
     * Creates an instance of ALL() function, with the given DQL Subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in ALL() function.
     */
    public function all(mixed $subquery): Expr\Func
    {
        return new Expr\Func('ALL', [$subquery]);
    }

    /**
     * Creates a SOME() function expression with the given DQL subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in SOME() function.
     */
    public function some(mixed $subquery): Expr\Func
    {
        return new Expr\Func('SOME', [$subquery]);
    }

    /**
     * Creates an ANY() function expression with the given DQL subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in ANY() function.
     */
    public function any(mixed $subquery): Expr\Func
    {
        return new Expr\Func('ANY', [$subquery]);
    }

    /**
     * Creates a negation expression of the given restriction.
     *
     * @param mixed $restriction Restriction to be used in NOT() function.
     */
    public function not(mixed $restriction): Expr\Func
    {
        return new Expr\Func('NOT', [$restriction]);
    }

    /**
     * Creates an ABS() function expression with the given argument.
     *
     * @param mixed $x Argument to be used in ABS() function.
     */
    public function abs(mixed $x): Expr\Func
    {
        return new Expr\Func('ABS', [$x]);
    }

    /**
     * Creates a MOD($x, $y) function expression to return the remainder of $x divided by $y.
     */
    public function mod(mixed $x, mixed $y): Expr\Func
    {
        return new Expr\Func('MOD', [$x, $y]);
    }

    /**
     * Creates a product mathematical expression with the given arguments.
     *
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> * <right expr>. Example:
     *
     *     [php]
     *     // u.salary * u.percentAnnualSalaryIncrease
     *     $q->expr()->prod('u.salary', 'u.percentAnnualSalaryIncrease')
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function prod(mixed $x, mixed $y): Expr\Math
    {
        return new Expr\Math($x, '*', $y);
    }

    /**
     * Creates a difference mathematical expression with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> - <right expr>. Example:
     *
     *     [php]
     *     // u.monthlySubscriptionCount - 1
     *     $q->expr()->diff('u.monthlySubscriptionCount', '1')
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function diff(mixed $x, mixed $y): Expr\Math
    {
        return new Expr\Math($x, '-', $y);
    }

    /**
     * Creates a sum mathematical expression with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> + <right expr>. Example:
     *
     *     [php]
     *     // u.numChildren + 1
     *     $q->expr()->sum('u.numChildren', '1')
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function sum(mixed $x, mixed $y): Expr\Math
    {
        return new Expr\Math($x, '+', $y);
    }

    /**
     * Creates a quotient mathematical expression with the given arguments.
     * First argument is considered the left expression and the second is the right expression.
     * When converted to string, it will generated a <left expr> / <right expr>. Example:
     *
     *     [php]
     *     // u.total / u.period
     *     $expr->quot('u.total', 'u.period')
     *
     * @param mixed $x Left expression.
     * @param mixed $y Right expression.
     */
    public function quot(mixed $x, mixed $y): Expr\Math
    {
        return new Expr\Math($x, '/', $y);
    }

    /**
     * Creates a SQRT() function expression with the given argument.
     *
     * @param mixed $x Argument to be used in SQRT() function.
     */
    public function sqrt(mixed $x): Expr\Func
    {
        return new Expr\Func('SQRT', [$x]);
    }

    /**
     * Creates an IN() expression with the given arguments.
     *
     * @param string $x Field in string format to be restricted by IN() function.
     * @param mixed  $y Argument to be used in IN() function.
     */
    public function in(string $x, mixed $y): Expr\Func
    {
        if (is_iterable($y)) {
            if ($y instanceof Traversable) {
                $y = iterator_to_array($y);
            }

            foreach ($y as &$literal) {
                if (! ($literal instanceof Expr\Literal)) {
                    $literal = $this->quoteLiteral($literal);
                }
            }
        }

        return new Expr\Func($x . ' IN', (array) $y);
    }

    /**
     * Creates a NOT IN() expression with the given arguments.
     *
     * @param string $x Field in string format to be restricted by NOT IN() function.
     * @param mixed  $y Argument to be used in NOT IN() function.
     */
    public function notIn(string $x, mixed $y): Expr\Func
    {
        if (is_iterable($y)) {
            if ($y instanceof Traversable) {
                $y = iterator_to_array($y);
            }

            foreach ($y as &$literal) {
                if (! ($literal instanceof Expr\Literal)) {
                    $literal = $this->quoteLiteral($literal);
                }
            }
        }

        return new Expr\Func($x . ' NOT IN', (array) $y);
    }

    /**
     * Creates an IS NULL expression with the given arguments.
     *
     * @param string $x Field in string format to be restricted by IS NULL.
     */
    public function isNull(string $x): string
    {
        return $x . ' IS NULL';
    }

    /**
     * Creates an IS NOT NULL expression with the given arguments.
     *
     * @param string $x Field in string format to be restricted by IS NOT NULL.
     */
    public function isNotNull(string $x): string
    {
        return $x . ' IS NOT NULL';
    }

    /**
     * Creates a LIKE() comparison expression with the given arguments.
     *
     * @param string $x Field in string format to be inspected by LIKE() comparison.
     * @param mixed  $y Argument to be used in LIKE() comparison.
     */
    public function like(string $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, 'LIKE', $y);
    }

    /**
     * Creates a NOT LIKE() comparison expression with the given arguments.
     *
     * @param string $x Field in string format to be inspected by LIKE() comparison.
     * @param mixed  $y Argument to be used in LIKE() comparison.
     */
    public function notLike(string $x, mixed $y): Expr\Comparison
    {
        return new Expr\Comparison($x, 'NOT LIKE', $y);
    }

    /**
     * Creates a CONCAT() function expression with the given arguments.
     *
     * @param mixed ...$x Arguments to be used in CONCAT() function.
     *
     * @no-named-arguments
     */
    public function concat(mixed ...$x): Expr\Func
    {
        self::validateVariadicParameter($x);

        return new Expr\Func('CONCAT', $x);
    }

    /**
     * Creates a SUBSTRING() function expression with the given arguments.
     *
     * @param mixed    $x    Argument to be used as string to be cropped by SUBSTRING() function.
     * @param int      $from Initial offset to start cropping string. May accept negative values.
     * @param int|null $len  Length of crop. May accept negative values.
     */
    public function substring(mixed $x, int $from, int|null $len = null): Expr\Func
    {
        $args = [$x, $from];
        if ($len !== null) {
            $args[] = $len;
        }

        return new Expr\Func('SUBSTRING', $args);
    }

    /**
     * Creates a LOWER() function expression with the given argument.
     *
     * @param mixed $x Argument to be used in LOWER() function.
     *
     * @return Expr\Func A LOWER function expression.
     */
    public function lower(mixed $x): Expr\Func
    {
        return new Expr\Func('LOWER', [$x]);
    }

    /**
     * Creates an UPPER() function expression with the given argument.
     *
     * @param mixed $x Argument to be used in UPPER() function.
     *
     * @return Expr\Func An UPPER function expression.
     */
    public function upper(mixed $x): Expr\Func
    {
        return new Expr\Func('UPPER', [$x]);
    }

    /**
     * Creates a LENGTH() function expression with the given argument.
     *
     * @param mixed $x Argument to be used as argument of LENGTH() function.
     *
     * @return Expr\Func A LENGTH function expression.
     */
    public function length(mixed $x): Expr\Func
    {
        return new Expr\Func('LENGTH', [$x]);
    }

    /**
     * Creates a literal expression of the given argument.
     *
     * @param scalar $literal Argument to be converted to literal.
     */
    public function literal(bool|string|int|float $literal): Expr\Literal
    {
        return new Expr\Literal($this->quoteLiteral($literal));
    }

    /**
     * Quotes a literal value, if necessary, according to the DQL syntax.
     *
     * @param scalar $literal The literal value.
     */
    private function quoteLiteral(bool|string|int|float $literal): string
    {
        if (is_int($literal) || is_float($literal)) {
            return (string) $literal;
        }

        if (is_bool($literal)) {
            return $literal ? 'true' : 'false';
        }

        return "'" . str_replace("'", "''", $literal) . "'";
    }

    /**
     * Creates an instance of BETWEEN() function, with the given argument.
     *
     * @param mixed      $val Valued to be inspected by range values.
     * @param int|string $x   Starting range value to be used in BETWEEN() function.
     * @param int|string $y   End point value to be used in BETWEEN() function.
     *
     * @return string A BETWEEN expression.
     */
    public function between(mixed $val, int|string $x, int|string $y): string
    {
        return $val . ' BETWEEN ' . $x . ' AND ' . $y;
    }

    /**
     * Creates an instance of TRIM() function, with the given argument.
     *
     * @param mixed $x Argument to be used as argument of TRIM() function.
     *
     * @return Expr\Func a TRIM expression.
     */
    public function trim(mixed $x): Expr\Func
    {
        return new Expr\Func('TRIM', $x);
    }

    /**
     * Creates an instance of MEMBER OF function, with the given arguments.
     *
     * @param string $x Value to be checked
     * @param string $y Value to be checked against
     */
    public function isMemberOf(string $x, string $y): Expr\Comparison
    {
        return new Expr\Comparison($x, 'MEMBER OF', $y);
    }

    /**
     * Creates an instance of INSTANCE OF function, with the given arguments.
     *
     * @param string $x Value to be checked
     * @param string $y Value to be checked against
     */
    public function isInstanceOf(string $x, string $y): Expr\Comparison
    {
        return new Expr\Comparison($x, 'INSTANCE OF', $y);
    }
}
