<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Query\AST\OrderByItem;

/** @internal */
enum OrderDirection: string
{
    case Ascending  = 'ASC';
    case Descending = 'DESC';

    public static function fromOrderByItem(OrderByItem $item, bool $reverse = false): self
    {
        $direction = $item->isAsc() ? self::Ascending : self::Descending;

        return $reverse ? $direction->reversed() : $direction;
    }

    public function exclusiveOperator(): string
    {
        return match ($this) {
            self::Ascending  => '>',
            self::Descending => '<',
        };
    }

    public function inclusiveOperator(): string
    {
        return match ($this) {
            self::Ascending  => '>=',
            self::Descending => '<=',
        };
    }

    public function reversed(): self
    {
        return match ($this) {
            self::Ascending  => self::Descending,
            self::Descending => self::Ascending,
        };
    }
}
