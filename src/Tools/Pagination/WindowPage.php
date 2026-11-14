<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use ArrayIterator;
use Closure;
use LogicException;
use Override;
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
 * The result keys are preserved, so a query using DQL ``INDEX BY`` keeps its
 * keys: {@see getItems()} and the iteration both carry them.
 *
 * @template-covariant T
 * @implements Page<T>
 */
final class WindowPage implements Page
{
    /** Memoizes the total count when it is provided lazily. */
    private int|null $resolvedTotalCount = null;

    /**
     * @param array<array-key, T> $items
     * @param int|Closure(): int  $totalCount The total number of matching root entities, or a closure
     *                                        running the COUNT query on demand.
     */
    public function __construct(
        private readonly array $items,
        private readonly int|Closure $totalCount,
        private readonly Window $window,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return array<array-key, T>
     */
    #[Override]
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * {@inheritDoc}
     *
     * @return Traversable<array-key, T>
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
     * Returns the total number of matching root entities, ignoring the window.
     *
     * When the page was built with a lazy total count, as {@see OffsetPaginator}
     * does, this runs the COUNT query on the first call and memoizes its result.
     */
    #[Override]
    public function getTotalCount(): int
    {
        return $this->resolvedTotalCount ??= $this->totalCount instanceof Closure
            ? ($this->totalCount)()
            : $this->totalCount;
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
     * set. Relies on {@see getTotalCount()}.
     *
     * @return int<1, max>
     */
    public function getPageCount(): int
    {
        $maxResults = $this->window->getMaxResults();

        return max(1, intdiv($this->getTotalCount() + $maxResults - 1, $maxResults));
    }

    #[Override]
    public function hasPreviousPage(): bool
    {
        return $this->window->getFirstResult() > 0;
    }

    /** Relies on {@see getTotalCount()}. */
    #[Override]
    public function hasNextPage(): bool
    {
        return $this->window->getFirstResult() + count($this->items) < $this->getTotalCount();
    }

    /**
     * Returns whether the result set spans more than one page. Relies on
     * {@see getTotalCount()}.
     */
    #[Override]
    public function hasToPaginate(): bool
    {
        return $this->getTotalCount() > count($this->items);
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
