<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use Override;
use Stringable;

use function strtoupper;

/**
 * Expression class for DQL join.
 *
 * @link    www.doctrine-project.org
 */
class Join implements Stringable
{
    final public const string INNER_JOIN = 'INNER';
    final public const string LEFT_JOIN  = 'LEFT';

    final public const string ON   = 'ON';
    final public const string WITH = 'WITH';

    /**
     * @phpstan-param self::INNER_JOIN|self::LEFT_JOIN $joinType
     * @phpstan-param self::ON|self::WITH|null $conditionType
     */
    public function __construct(
        protected string $joinType,
        protected string $join,
        protected string|null $alias = null,
        protected string|null $conditionType = null,
        protected string|Comparison|Composite|Func|null $condition = null,
        protected string|null $indexBy = null,
    ) {
    }

    /** @phpstan-return self::INNER_JOIN|self::LEFT_JOIN */
    public function getJoinType(): string
    {
        return $this->joinType;
    }

    public function getJoin(): string
    {
        return $this->join;
    }

    public function getAlias(): string|null
    {
        return $this->alias;
    }

    /** @phpstan-return self::ON|self::WITH|null */
    public function getConditionType(): string|null
    {
        return $this->conditionType;
    }

    public function getCondition(): string|Comparison|Composite|Func|null
    {
        return $this->condition;
    }

    public function getIndexBy(): string|null
    {
        return $this->indexBy;
    }

    #[Override]
    public function __toString(): string
    {
        return strtoupper($this->joinType) . ' JOIN ' . $this->join
             . ($this->alias ? ' ' . $this->alias : '')
             . ($this->indexBy ? ' INDEX BY ' . $this->indexBy : '')
             . ($this->condition ? ' ' . strtoupper($this->conditionType) . ' ' . $this->condition : '');
    }
}
