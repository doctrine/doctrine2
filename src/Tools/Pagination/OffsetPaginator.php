<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Override;

use function array_map;
use function array_sum;
use function get_debug_type;
use function sprintf;

/**
 * Offset-based paginator, designed to be symmetric with {@see CursorPaginator}.
 *
 * The query and the pagination position are both passed to {@see paginate()},
 * which returns an immutable, iterable {@see WindowPage} rather than the
 * paginator itself. The paginator therefore holds no state beyond its
 * configuration: a single instance can be shared as a service and reused for
 * any query and any page. This avoids the implicit offset handling and the
 * stateful API of the legacy {@see Paginator}, which this class is intended to
 * replace. When only the total is needed, {@see getTotalCount()} runs the COUNT
 * query without fetching any row.
 *
 * @template-covariant T
 * @implements PaginatorInterface<T, Window>
 */
final class OffsetPaginator implements PaginatorInterface
{
    use SQLResultCasing;
    use PaginatorQuery;

    /**
     * @param bool      $fetchJoinCollection Whether the query joins a collection (true by default).
     * @param bool|null $useOutputWalkers    Whether to force the use of an output walker. Null (default)
     *                                       lets the paginator decide.
     */
    public function __construct(
        private readonly bool $fetchJoinCollection = true,
        bool|null $useOutputWalkers = null,
    ) {
        $this->useOutputWalkers = $useOutputWalkers;
    }

    /**
     * Returns whether the query joins a collection.
     */
    public function getFetchJoinCollection(): bool
    {
        return $this->fetchJoinCollection;
    }

    /**
     * Executes the query for the given window and returns an immutable page.
     *
     * As in {@see CursorPaginator}, the COUNT query is deferred: it only runs
     * when {@see WindowPage::getTotalCount()}, or one of the methods relying on
     * it, is called.
     *
     * @param Window $position The window to fetch. Use {@see Window::fromPageNumberAndSize()}
     *                         to build it from a 1-based page number and a page size.
     *
     * @return WindowPage<T>
     *
     * @throws InvalidArgumentException If $position is not a {@see Window}.
     */
    #[Override]
    public function paginate(Query|QueryBuilder $query, mixed $position = null): WindowPage
    {
        if (! $position instanceof Window) {
            throw new InvalidArgumentException(sprintf(
                'Expected an instance of %s, got %s.',
                Window::class,
                get_debug_type($position),
            ));
        }

        $query = $this->resolveQuery($query);

        /** @var array<array-key, T> $items */
        $items = $this->getResultForOffset(
            $query,
            $position->getFirstResult(),
            $position->getMaxResults(),
            $this->fetchJoinCollection,
        );

        return new WindowPage($items, fn (): int => $this->countResolvedQuery($query), $position);
    }

    /**
     * Executes the COUNT query only and returns the total number of matching
     * root entities, without fetching any row.
     *
     * This is the same COUNT query as the one {@see WindowPage::getTotalCount()}
     * runs, for when the total is needed on its own: before choosing the window
     * to fetch, or without any listing at all.
     */
    public function getTotalCount(Query|QueryBuilder $query): int
    {
        return $this->countResolvedQuery($this->resolveQuery($query));
    }

    private function countResolvedQuery(Query $query): int
    {
        try {
            return (int) array_sum(array_map('current', $this->getCountQuery($query)->getScalarResult()));
        } catch (NoResultException) {
            return 0;
        }
    }
}
