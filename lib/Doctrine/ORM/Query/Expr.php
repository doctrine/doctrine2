<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Traversable;

use function func_get_args;
use function implode;
use function is_bool;
use function is_iterable;
use function is_numeric;
use function is_string;
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
    /**
     * Creates a conjunction of the given boolean expressions.
     *
     * Example:
     *
     *     [php]
     *     // (u.type = ?1) AND (u.role = ?2)
     *     $expr->andX($expr->eq('u.type', ':1'), $expr->eq('u.role', ':2'));
     *
     * @param Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string $x Optional clause. Defaults to null,
     *                                                               but requires at least one defined
     *                                                               when converting to string.
     * @psalm-param Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string ...$x
     *
     * @return Expr\Andx
     */
    public function andX($x = null)
    {
        return new Expr\Andx(func_get_args());
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
     * @param Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string $x Optional clause. Defaults to null,
     *                                                               but requires at least one defined
     *                                                               when converting to string.
     * @psalm-param Expr\Comparison|Expr\Func|Expr\Andx|Expr\Orx|string ...$x
     *
     * @return Expr\Orx
     */
    public function orX($x = null)
    {
        return new Expr\Orx(func_get_args());
    }

    /**
     * Creates an ASCending order expression.
     *
     * @param mixed $expr
     *
     * @return Expr\OrderBy
     */
    public function asc($expr)
    {
        return new Expr\OrderBy($expr, 'ASC');
    }

    /**
     * Creates a DESCending order expression.
     *
     * @param mixed $expr
     *
     * @return Expr\OrderBy
     */
    public function desc($expr)
    {
        return new Expr\OrderBy($expr, 'DESC');
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
     *
     * @return Expr\Comparison
     */
    public function eq($x, $y)
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
     *
     * @return Expr\Comparison
     */
    public function neq($x, $y)
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
     *
     * @return Expr\Comparison
     */
    public function lt($x, $y)
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
     *
     * @return Expr\Comparison
     */
    public function lte($x, $y)
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
     *
     * @return Expr\Comparison
     */
    public function gt($x, $y)
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
     *
     * @return Expr\Comparison
     */
    public function gte($x, $y)
    {
        return new Expr\Comparison($x, Expr\Comparison::GTE, $y);
    }

    /**
     * Creates an instance of AVG() function, with the given argument.
     *
     * @param mixed $x Argument to be used in AVG() function.
     *
     * @return Expr\Func
     */
    public function avg($x)
    {
        return new Expr\Func('AVG', [$x]);
    }

    /**
     * Creates an instance of MAX() function, with the given argument.
     *
     * @param mixed $x Argument to be used in MAX() function.
     *
     * @return Expr\Func
     */
    public function max($x)
    {
        return new Expr\Func('MAX', [$x]);
    }

    /**
     * Creates an instance of MIN() function, with the given argument.
     *
     * @param mixed $x Argument to be used in MIN() function.
     *
     * @return Expr\Func
     */
    public function min($x)
    {
        return new Expr\Func('MIN', [$x]);
    }

    /**
     * Creates an instance of COUNT() function, with the given argument.
     *
     * @param mixed $x Argument to be used in COUNT() function.
     *
     * @return Expr\Func
     */
    public function count($x)
    {
        return new Expr\Func('COUNT', [$x]);
    }

    /**
     * Creates an instance of COUNT(DISTINCT) function, with the given argument.
     *
     * @param mixed $x Argument to be used in COUNT(DISTINCT) function.
     *
     * @return string
     */
    public function countDistinct($x)
    {
        return 'COUNT(DISTINCT ' . implode(', ', func_get_args()) . ')';
    }

    /**
     * Creates an instance of EXISTS() function, with the given DQL Subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in EXISTS() function.
     *
     * @return Expr\Func
     */
    public function exists($subquery)
    {
        return new Expr\Func('EXISTS', [$subquery]);
    }

    /**
     * Creates an instance of ALL() function, with the given DQL Subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in ALL() function.
     *
     * @return Expr\Func
     */
    public function all($subquery)
    {
        return new Expr\Func('ALL', [$subquery]);
    }

    /**
     * Creates a SOME() function expression with the given DQL subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in SOME() function.
     *
     * @return Expr\Func
     */
    public function some($subquery)
    {
        return new Expr\Func('SOME', [$subquery]);
    }

    /**
     * Creates an ANY() function expression with the given DQL subquery.
     *
     * @param mixed $subquery DQL Subquery to be used in ANY() function.
     *
     * @return Expr\Func
     */
    public function any($subquery)
    {
        return new Expr\Func('ANY', [$subquery]);
    }

    /**
     * Creates a negation expression of the given restriction.
     *
     * @param mixed $restriction Restriction to be used in NOT() function.
     *
     * @return Expr\Func
     */
    public function not($restriction)
    {
        return new Expr\Func('NOT', [$restriction]);
    }

    /**
     * Creates an ABS() function expression with the given argument.
     *
     * @param mixed $x Argument to be used in ABS() function.
     *
     * @return Expr\Func
     */
    public function abs($x)
    {
        return new Expr\Func('ABS', [$x]);
    }

    /**
     * Creates a MOD($x, $y) function expression to return the remainder of $x divided by $y.
     *
     * @param mixed $x
     * @param mixed $y
     */
    public function mod($x, $y): Expr\Func
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
     *
     * @return Expr\Math
     */
    public function prod($x, $y)
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
     *
     * @return Expr\Math
     */
    public function diff($x, $y)
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
     *
     * @return Expr\Math
     */
    public function sum($x, $y)
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
     *
     * @return Expr\Math
     */
    public function quot($x, $y)
    {
        return new Expr\Math($x, '/', $y);
    }

    /**
     * Creates a SQRT() function expression with the given argument.
     *
     * @param mixed $x Argument to be used in SQRT() function.
     *
     * @return Expr\Func
     */
    public function sqrt($x)
    {
        return new Expr\Func('SQRT', [$x]);
    }

    /**
     * Creates an IN() expression with the given arguments.
     *
     * @param string $x Field in string format to be restricted by IN() function.
     * @param mixed  $y Argument to be used in IN() function.
     *
     * @return Expr\Func
     */
    public function in($x, $y)
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
     *
     * @return Expr\Func
     */
    public function notIn($x, $y)
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
     *
     * @return string
     */
    public function isNull($x)
    {
        return $x . ' IS NULL';
    }

    /**
     * Creates an IS NOT NULL expression with the given arguments.
     *
     * @param string $x Field in string format to be restricted by IS NOT NULL.
     *
     * @return string
     */
    public function isNotNull($x)
    {
        return $x . ' IS NOT NULL';
    }

    /**
     * Creates a LIKE() comparison expression with the given arguments.
     *
     * @param string $x Field in string format to be inspected by LIKE() comparison.
     * @param mixed  $y Argument to be used in LIKE() comparison.
     *
     * @return Expr\Comparison
     */
    public function like($x, $y)
    {
        return new Expr\Comparison($x, 'LIKE', $y);
    }

    /**
     * Creates a NOT LIKE() comparison expression with the given arguments.
     *
     * @param string $x Field in string format to be inspected by LIKE() comparison.
     * @param mixed  $y Argument to be used in LIKE() comparison.
     *
     * @return Expr\Comparison
     */
    public function notLike($x, $y)
    {
        return new Expr\Comparison($x, 'NOT LIKE', $y);
    }

    /**
     * Creates a CONCAT() function expression with the given arguments.
     *
     * @param mixed $x     First argument to be used in CONCAT() function.
     * @param mixed $y,... Other arguments to be used in CONCAT() function.
     *
     * @return Expr\Func
     */
    public function concat($x, $y)
    {
        return new Expr\Func('CONCAT', func_get_args());
    }

    /**
     * Creates a SUBSTRING() function expression with the given arguments.
     *
     * @param mixed    $x    Argument to be used as string to be cropped by SUBSTRING() function.
     * @param int      $from Initial offset to start cropping string. May accept negative values.
     * @param int|null $len  Length of crop. May accept negative values.
     *
     * @return Expr\Func
     */
    public function substring($x, $from, $len = null)
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
    public function lower($x)
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
    public function upper($x)
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
    public function length($x)
    {
        return new Expr\Func('LENGTH', [$x]);
    }

    /**
     * Creates a literal expression of the given argument.
     *
     * @param mixed $literal Argument to be converted to literal.
     *
     * @return Expr\Literal
     */
    public function literal($literal)
    {
        return new Expr\Literal($this->quoteLiteral($literal));
    }

    /**
     * Quotes a literal value, if necessary, according to the DQL syntax.
     *
     * @param mixed $literal The literal value.
     */
    private function quoteLiteral($literal): string
    {
        if (is_numeric($literal) && ! is_string($literal)) {
            return (string) $literal;
        } elseif (is_bool($literal)) {
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
    public function between($val, $x, $y)
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
    public function trim($x)
    {
        return new Expr\Func('TRIM', $x);
    }

    /**
     * Creates an instance of MEMBER OF function, with the given arguments.
     *
     * @param string $x Value to be checked
     * @param string $y Value to be checked against
     *
     * @return Expr\Comparison
     */
    public function isMemberOf($x, $y)
    {
        return new Expr\Comparison($x, 'MEMBER OF', $y);
    }

    /**
     * Creates an instance of INSTANCE OF function, with the given arguments.
     *
     * @param string $x Value to be checked
     * @param string $y Value to be checked against
     *
     * @return Expr\Comparison
     */
    public function isInstanceOf($x, $y)
    {
        return new Expr\Comparison($x, 'INSTANCE OF', $y);
    }
}
