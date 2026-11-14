<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

/**
 * Paginates a DQL query for a given pagination position.
 *
 * Implementations hold no query and no position: both are passed to
 * {@see paginate()}, so a paginator is stateless and can be reused — and shared
 * as a service — across queries and pages.
 *
 * The position type depends on the pagination strategy, so it is declared as
 * `mixed` here and narrowed by the `TPosition` template parameter:
 * {@see OffsetPaginator} is a `PaginatorInterface<T, Window>`, and
 * {@see CursorPaginator} a `PaginatorInterface<T, Cursor|string|null>`.
 *
 * @template-covariant T
 * @template TPosition
 */
interface PaginatorInterface
{
    /**
     * Query hint disabling the DISTINCT keyword in the identifier subquery, for
     * queries where duplicate root identifiers cannot occur.
     */
    public const string HINT_ENABLE_DISTINCT = 'paginator.distinct.enable';

    /**
     * Executes the query for the given position and returns an immutable page.
     *
     * @param TPosition $position
     *
     * @return Page<T>
     */
    public function paginate(Query|QueryBuilder $query, mixed $position = null): Page;
}
