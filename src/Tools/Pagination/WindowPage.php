<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use ArrayIterator;
use LogicException;
use Traversable;

use function count;
use function intdiv;
use function max;

/**
 * Immutable result of a single offset pagination.
 *
 * It is returned by {@see OffsetPaginator::paginate()} instead of the paginator
 * itself, so the result is not stateful and there is no temporal coupling
 * between method calls: every accessor is safe to call in any order.
 *
 * On top of {@see Page}, a window page exposes what only offset pagination can
 * know: the total number of pages and the position of the last one.
 *
 * @template-covariant T
 * @implements Page<T>
 */
final class WindowPage implements Page
{
    /** @param list<T> $items */
    public function __construct(
        private readonly array $items,
        private readonly int $totalCount,
        private readonly Window $window,
    ) {
    }

    /** @return list<T> */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Returns the number of items on this page.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Returns the total number of matching root entities, ignoring the window.
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * Returns the window that produced this page.
     */
    public function getWindow(): Window
    {
        return $this->window;
    }

    /**
     * Returns the 1-based number of this page.
     *
     * @return int<1, max>
     */
    public function getPageNumber(): int
    {
        return $this->window->getPageNumber();
    }

    /**
     * Returns the total number of pages, at least 1 even for an empty result
     * set.
     *
     * @return int<1, max>
     */
    public function getPageCount(): int
    {
        $maxResults = $this->window->getMaxResults();

        return max(1, intdiv($this->totalCount + $maxResults - 1, $maxResults));
    }

    public function hasPreviousPage(): bool
    {
        return $this->window->getFirstResult() > 0;
    }

    public function hasNextPage(): bool
    {
        return $this->window->getFirstResult() + count($this->items) < $this->totalCount;
    }

    /**
     * Returns whether the result set spans more than one page.
     */
    public function hasToPaginate(): bool
    {
        return $this->totalCount > count($this->items);
    }

    /** @throws LogicException If there is no next page. Check {@see hasNextPage()} first. */
    public function getNextWindow(): Window
    {
        if (! $this->hasNextPage()) {
            throw new LogicException('There is no next page. Call hasNextPage() before getNextWindow().');
        }

        return $this->window->next();
    }

    /** @throws LogicException If there is no previous page. Check {@see hasPreviousPage()} first. */
    public function getPreviousWindow(): Window
    {
        if (! $this->hasPreviousPage()) {
            throw new LogicException('There is no previous page. Call hasPreviousPage() before getPreviousWindow().');
        }

        return $this->window->previous();
    }

    /**
     * Returns the window of the last page, keeping the same page size.
     *
     * Unlike {@see getNextWindow()} and {@see getPreviousWindow()}, this never
     * throws: an empty result set has a single, empty first page.
     */
    public function getLastWindow(): Window
    {
        return Window::fromPageNumberAndSize($this->getPageCount(), $this->window->getMaxResults());
    }
}
