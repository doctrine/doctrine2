<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Countable;
use IteratorAggregate;
use Override;

/**
 * Immutable result of a single pagination.
 *
 * A page is the return value of {@see PaginatorInterface::paginate()}. It is
 * iterable and countable, and carries no temporal coupling: every accessor is
 * safe to call in any order, and paginating again never affects a page you
 * already hold.
 *
 * Navigating to another page is deliberately left out of this interface: the
 * position types are strategy specific ({@see WindowPage::getNextWindow()}
 * returns a {@see Window}, {@see CursorPage::getNextCursor()} a {@see Cursor}).
 *
 * @template-covariant T
 * @extends IteratorAggregate<array-key, T>
 */
interface Page extends Countable, IteratorAggregate
{
    /**
     * Returns the raw entities on this page.
     *
     * The keys are implementation specific: {@see CursorPage} returns a list,
     * while {@see WindowPage} preserves the result keys (e.g. DQL ``INDEX BY``).
     *
     * @return array<array-key, T>
     */
    public function getItems(): array;

    /**
     * Returns the number of items on this page.
     */
    #[Override]
    public function count(): int;

    /**
     * Returns the total number of matching root entities, ignoring the
     * pagination position.
     */
    public function getTotalCount(): int;

    /**
     * Returns whether there is a previous page.
     */
    public function hasPreviousPage(): bool;

    /**
     * Returns whether there is a next page.
     */
    public function hasNextPage(): bool;

    /**
     * Returns whether the result set spans more than one page.
     */
    public function hasToPaginate(): bool;
}
