<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use Override;
use SortDirection;
use Stringable;

use function count;
use function implode;

/**
 * Expression class for building DQL Order By parts.
 *
 * @link    www.doctrine-project.org
 */
class OrderBy implements Stringable
{
    protected string $preSeparator  = '';
    protected string $separator     = ', ';
    protected string $postSeparator = '';

    /** @var string[] */
    protected array $allowedClasses = [];

    /** @phpstan-var list<string> */
    protected array $parts = [];

    public function __construct(
        string|null $sort = null,
        SortDirection $order = SortDirection::Ascending,
    ) {
        if ($sort) {
            $this->add($sort, $order);
        }
    }

    public function add(string $sort, SortDirection $order = SortDirection::Ascending): void
    {
        $this->parts[] = $sort . ' ' . match ($order) {
            SortDirection::Ascending => 'ASC',
            SortDirection::Descending => 'DESC',
        };
    }

    /** @phpstan-return 0|positive-int */
    public function count(): int
    {
        return count($this->parts);
    }

    /** @phpstan-return list<string> */
    public function getParts(): array
    {
        return $this->parts;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->preSeparator . implode($this->separator, $this->parts) . $this->postSeparator;
    }
}
