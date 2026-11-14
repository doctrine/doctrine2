<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use ArrayIterator;
use Closure;
use LogicException;
use Override;
use Traversable;

use function array_map;
use function count;

/**
 * Immutable result of a single cursor pagination — the cursor-side counterpart
 * to {@see WindowPage}.
 *
 * It is returned by {@see CursorPaginator::paginate()} instead of the paginator
 * itself, so the result is not stateful and there is no temporal coupling
 * between method calls: every accessor is safe to call in any order.
 *
 * @template-covariant T
 * @implements Page<T>
 */
final class CursorPage implements Page
{
    /** Memoizes the COUNT query, which is only run when asked for. */
    private int|null $totalCount = null;

    /**
     * @param list<T>                      $items
     * @param Closure(): int               $totalCountProvider Runs the COUNT query on demand.
     * @param Closure(mixed, bool): Cursor $cursorForItem      Builds a Cursor pointing at a given row.
     */
    public function __construct(
        private readonly array $items,
        private readonly bool $hasMore,
        private readonly Cursor|null $cursor,
        private readonly Closure $totalCountProvider,
        private readonly Closure $cursorForItem,
    ) {
    }

    /**
     * Returns the raw entities on this page.
     *
     * @return list<T>
     */
    #[Override]
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int, T>
     */
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Returns the number of items on this page.
     */
    #[Override]
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Executes an extra COUNT query and returns the total number of matching
     * root entities, ignoring the cursor and limit. The result is memoized.
     */
    #[Override]
    public function getTotalCount(): int
    {
        return $this->totalCount ??= ($this->totalCountProvider)();
    }

    /**
     * Returns whether there is a previous page.
     */
    #[Override]
    public function hasPreviousPage(): bool
    {
        return $this->cursor !== null && ($this->cursor->isNext() || $this->hasMore);
    }

    /**
     * Returns whether there is a next page.
     */
    #[Override]
    public function hasNextPage(): bool
    {
        return $this->hasMore || ($this->cursor !== null && $this->cursor->isPrevious());
    }

    /**
     * Returns whether the result set spans more than one page.
     */
    #[Override]
    public function hasToPaginate(): bool
    {
        return $this->hasPreviousPage() || $this->hasNextPage();
    }

    /** @throws LogicException If there is no next page. Check {@see hasNextPage()} first. */
    public function getNextCursor(): Cursor
    {
        if ($this->items === [] || ! $this->hasNextPage()) {
            throw new LogicException('There is no next page. Call hasNextPage() before getNextCursor().');
        }

        return $this->getCursorForItem($this->items[count($this->items) - 1]);
    }

    /** @throws LogicException If there is no previous page. Check {@see hasPreviousPage()} first. */
    public function getPreviousCursor(): Cursor
    {
        if ($this->items === [] || ! $this->hasPreviousPage()) {
            throw new LogicException('There is no previous page. Call hasPreviousPage() before getPreviousCursor().');
        }

        return $this->getCursorForItem($this->items[0], false);
    }

    /**
     * Returns the encoded cursor string for the next page.
     *
     * @throws LogicException If there is no next page. Check {@see hasNextPage()} first.
     */
    public function getNextCursorAsString(): string
    {
        return $this->getNextCursor()->encodeToString();
    }

    /**
     * Returns the encoded cursor string for the previous page.
     *
     * @throws LogicException If there is no previous page. Check {@see hasPreviousPage()} first.
     */
    public function getPreviousCursorAsString(): string
    {
        return $this->getPreviousCursor()->encodeToString();
    }

    /**
     * Builds a Cursor pointing at a specific entity.
     *
     * @param mixed $item   The item to create a cursor for.
     * @param bool  $isNext Whether the cursor is for the next page.
     */
    public function getCursorForItem(mixed $item, bool $isNext = true): Cursor
    {
        return ($this->cursorForItem)($item, $isNext);
    }

    /**
     * Returns the items wrapped with their associated cursors.
     *
     * @return array<int, CursorItem<T>>
     */
    public function getItemsWithCursors(): array
    {
        return array_map(
            fn (mixed $item) => new CursorItem($item, $this->getCursorForItem($item)),
            $this->items,
        );
    }
}
