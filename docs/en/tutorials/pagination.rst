Pagination
==========

Doctrine ORM provides two pagination strategies for DQL queries. Both handle
the low-level SQL plumbing, but they make different trade-offs:

.. list-table::
   :header-rows: 1

   * - Feature
     - ``OffsetPaginator``
     - ``CursorPaginator``
   * - Total count
     - Yes (extra query)
     - Yes (extra query)
   * - Random access to page N
     - Yes
     - No
   * - Stable under concurrent inserts/deletes
     - No
     - Yes
   * - Performance on deep pages
     - Degrades (OFFSET scan)
     - Constant (index range scan)
   * - Requires deterministic ORDER BY
     - No
     - Yes

Choose the **Offset Paginator** when you need random access to an arbitrary
page number.

Choose the **Cursor Paginator** when you need stable, high-performance
pagination on large datasets and a simple previous/next navigation is
sufficient. A total count is also available via ``getTotalCount()``, at the
cost of an extra ``COUNT`` query.

Offset-Based Pagination
-----------------------

Doctrine ORM ships with the ``OffsetPaginator`` for offset-based pagination of
DQL queries. The query and the pagination position — a ``Window`` value object,
which carries both a first result and a page size — are both passed to
``paginate()``, which returns an immutable, iterable ``WindowPage``. This mirrors
the ``CursorPaginator`` API.

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
    use Doctrine\ORM\Tools\Pagination\Window;

    $dql   = 'SELECT p, c FROM BlogPost p JOIN p.comments c ORDER BY p.id ASC';
    $query = $entityManager->createQuery($dql);

    // new Window($firstResult, $maxResults), or Window::fromPageNumberAndSize($pageNumber, $pageSize)
    $page = (new OffsetPaginator())->paginate($query, Window::fromPageNumberAndSize(1, 25));

    echo $page->getTotalCount() . " result(s), page {$page->getPageNumber()} of {$page->getPageCount()}\n";

    foreach ($page as $post) {
        echo $post->getHeadline() . "\n";
    }

    if ($page->hasNextPage()) {
        $nextWindow = $page->getNextWindow(); // Window for the next page
    }

The paginator itself holds no query and no position, only configuration: a
single instance is stateless and can be reused — or registered as a service —
for any query and any page.

.. code-block:: php

    <?php
    $paginator = new OffsetPaginator();

    $firstPage  = $paginator->paginate($query, Window::fromPageNumberAndSize(1, 25));
    $secondPage = $paginator->paginate($query, $firstPage->getNextWindow());

Because the returned ``WindowPage`` is immutable and carries no temporal
coupling, its accessors can be called in any order, and building another page
never affects a page you already hold.

How Offset Pagination Works
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Paginating Doctrine queries is not as simple as you might think in the
beginning. If you have complex fetch-join scenarios with one-to-many or
many-to-many associations using the "default" LIMIT functionality of database
vendors is not sufficient to get the correct results.

By default the paginator does the following steps to compute the correct result:

- Perform a Count query using ``DISTINCT`` keyword.
- Perform a Limit Subquery with ``DISTINCT`` to find all ids of the entity in from on the current page.
- Perform a WHERE IN query to get all results for the current page.

This behavior is only necessary if you actually fetch join a to-many
collection. You can disable it by setting the ``fetchJoinCollection``
constructor argument to ``false``; in that case only 2 instead of the 3
queries described are executed.

.. note::

    ``fetchJoinCollection`` set to ``true`` might affect results if you use
    aggregations in your query.

Alternatively, the ``PaginatorInterface::HINT_ENABLE_DISTINCT`` query hint instructs
Doctrine that the query will not produce "duplicate" rows (only to-one relations
are joined), so the ``DISTINCT`` keyword is omitted, which can bring important
performance improvements:

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
    use Doctrine\ORM\Tools\Pagination\PaginatorInterface;
    use Doctrine\ORM\Tools\Pagination\Window;

    $dql   = 'SELECT u, p FROM User u JOIN u.mainPicture p ORDER BY u.id ASC';
    $query = $entityManager->createQuery($dql)
                           ->setHint(PaginatorInterface::HINT_ENABLE_DISTINCT, false);

    $page = (new OffsetPaginator())->paginate($query, new Window(0, 100));

API Reference
~~~~~~~~~~~~~

``OffsetPaginator::paginate(Query|QueryBuilder $query, Window $position): WindowPage``
    Executes the query for the given ``Window`` and returns an immutable
    ``WindowPage``. All page accessors below live on the returned page. Throws an
    ``InvalidArgumentException`` if ``$position`` is not a ``Window``.

``WindowPage::getItems(): array``
    Returns the raw entity array for the current page.

``WindowPage::count(): int``
    Returns the number of items on the current page (SPL ``Countable``).

``WindowPage::getTotalCount(): int``
    Returns the total number of matching root entities, ignoring the window.

``WindowPage::getPageNumber(): int`` / ``WindowPage::getPageCount(): int``
    Return the 1-based number of the current page and the total number of pages.
    ``getPageCount()`` is at least ``1``, even for an empty result set.

``WindowPage::hasNextPage(): bool`` / ``WindowPage::hasPreviousPage(): bool``
    Return whether a next / previous page is available.

``WindowPage::hasToPaginate(): bool``
    Returns whether the result set spans more than one page.

``WindowPage::getNextWindow(): Window`` / ``WindowPage::getPreviousWindow(): Window``
    Return the ``Window`` for the next / previous page. Throw a ``LogicException``
    if there is none — call ``hasNextPage()`` / ``hasPreviousPage()`` first.

``WindowPage::getLastWindow(): Window``
    Returns the ``Window`` of the last page, keeping the same page size. Unlike
    the two methods above, it never throws: an empty result set has a single,
    empty first page.

``WindowPage::getWindow(): Window``
    Returns the ``Window`` that produced this page.

``Window::fromPageNumberAndSize(int $pageNumber, int $pageSize): Window``
    Builds a ``Window`` from a 1-based page number and a page size.

``Window::getPageNumber(): int``
    Returns the 1-based page number the window points at.

Legacy ``Paginator``
~~~~~~~~~~~~~~~~~~~~~

.. deprecated:: 3.7

    The ``Paginator`` class is deprecated in favor of ``OffsetPaginator`` and
    will be removed in 4.0.

The legacy ``Paginator`` reads the offset implicitly from the query
(``setFirstResult()`` / ``setMaxResults()``) and implements the SPL interfaces
``Countable`` and ``IteratorAggregate``:

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\Pagination\Paginator;

    $dql = "SELECT p, c FROM BlogPost p JOIN p.comments c";
    $query = $entityManager->createQuery($dql)
                           ->setFirstResult(0)
                           ->setMaxResults(100);

    $paginator = new Paginator($query, fetchJoinCollection: true);

    $c = count($paginator);
    foreach ($paginator as $post) {
        echo $post->getHeadline() . "\n";
    }

Cursor-Based Pagination
-----------------------

Doctrine ORM ships with a ``CursorPaginator`` for cursor-based pagination of DQL queries.
Unlike offset-based pagination, cursor pagination uses opaque pointers (cursors) derived
from the last seen row to fetch the next or previous page. This makes it stable and
performant on large datasets — no matter how deep you paginate, the database always uses
an index range scan instead of skipping rows.

.. note::

    Cursor pagination requires a **deterministic ORDER BY clause**. Every column
    combination used for sorting must uniquely identify a position in the result set.
    A common pattern is to sort by a timestamp and then by primary key as a tie-breaker.

Constructor
~~~~~~~~~~~

.. code-block:: php

    <?php
    new CursorPaginator(
        int $limit,
        bool $queryProducesDuplicates = true,
    )

The paginator only holds configuration: the query and the cursor are passed to
``paginate()``, which returns an immutable ``CursorPage`` — symmetric with the
offset-based ``OffsetPaginator``. A single instance is stateless and can be
reused, or registered as a service, for any query and any page.

``$limit``
    The maximum number of results per page. Unlike ``Window``, which carries its
    own page size, a ``Cursor`` is a pure position: the page size is paginator
    configuration and therefore never comes from user input.

``$queryProducesDuplicates``
    Set to ``true`` (default) when the query joins a to-many collection.
    The paginator then uses a two-query strategy (ID subquery + ``WHERE IN``)
    to return the correct number of root entities despite duplicate rows.
    Set to ``false`` when only to-one joins are present — this avoids the
    subquery overhead and is equivalent to passing ``fetchJoinCollection: false``
    to the ``OffsetPaginator``.
    However, passing ``false`` on a query that joins a to-many relation is not
    detected — arbitrary joins can produce duplicate root entities silently,
    leading to a corrupt result set.

Basic Usage
~~~~~~~~~~~

The ``$position`` parameter of ``paginate()`` accepts either an encoded string produced by a previous call to
``getNextCursorAsString()`` or ``getPreviousCursorAsString()``, or a ``Cursor`` instance
returned by ``getNextCursor()`` or ``getPreviousCursor()``. On the first request it is
``null`` or an empty string ``''`` — both are treated identically as the first page.
It is typically read from the incoming HTTP query string:

.. code-block:: php

    $cursor = $_GET['cursor'] ?? null; // null or '' on the first page

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\Pagination\CursorPaginator;

    $dql = 'SELECT p FROM BlogPost p ORDER BY p.createdAt DESC, p.id DESC';
    $query = $entityManager->createQuery($dql);

    $paginator = new CursorPaginator(limit: 15);
    $page      = $paginator->paginate($query, $cursor);

    foreach ($page as $post) {
        echo $post->getTitle() . "\n";
    }

    echo $page->getPreviousCursorAsString(); // previous encoded cursor string
    echo $page->getNextCursorAsString();     // next encoded cursor string

Navigating Pages
~~~~~~~~~~~~~~~~

Pass the encoded cursor back on subsequent requests to move forward or backward,
reusing the same paginator:

.. code-block:: php

    <?php
    // Next page
    $page = $paginator->paginate($query, $nextCursor);

    // Previous page
    $page = $paginator->paginate($query, $previousCursor);

The cursor is an encoded string containing the location at which the next query should begin fetching results, along with the navigation direction.

API Reference
~~~~~~~~~~~~~

``CursorPaginator::paginate(Query|QueryBuilder $query, Cursor|string|null $position): CursorPage``
    Executes the query for the given cursor and returns an immutable
    ``CursorPage``. Fetches ``$limit + 1`` rows to detect whether a further page
    exists, then trims the extra row. All accessors below live on the returned
    ``CursorPage``.

``CursorPage::getNextCursor(): Cursor``
    Returns the ``Cursor`` object for the next page. Throws a ``LogicException``
    if there is no next page — call ``hasNextPage()`` first.

``CursorPage::getPreviousCursor(): Cursor``
    Returns the ``Cursor`` object for the previous page. Throws a ``LogicException``
    if there is no previous page — call ``hasPreviousPage()`` first.

``CursorPage::getNextCursorAsString(): string``
    Returns the encoded cursor to retrieve the next page. Throws a
    ``LogicException`` if there is no next page — call ``hasNextPage()`` first.

``CursorPage::getPreviousCursorAsString(): string``
    Returns the encoded cursor to retrieve the previous page. Throws a
    ``LogicException`` if there is no previous page — call ``hasPreviousPage()`` first.

``CursorPage::hasNextPage(): bool``
    Returns whether a next page is available.

``CursorPage::hasPreviousPage(): bool``
    Returns whether a previous page is available.

``CursorPage::hasToPaginate(): bool``
    Returns whether either a next or previous page exists (i.e. the result
    set spans more than one page).

``CursorPage::getItems(): array``
    Returns the raw entity array for the current page.

``CursorPage::getItemsWithCursors(): array``
    Returns an array of ``CursorItem`` objects, each wrapping an entity and its
    individual ``Cursor``. Useful when you need per-row cursors.

``CursorPage::getCursorForItem(mixed $item, bool $isNext = true): Cursor``
    Builds a ``Cursor`` pointing at a specific entity. ``$isNext = true`` means
    "start *after* this item"; ``false`` means "start *before* this item".

``CursorPage::count(): int``
    Returns the number of items on the current page.

``CursorPage::getTotalCount(): int``
    Executes an extra ``COUNT`` query and returns the total number of matching
    root entities, ignoring the cursor and limit. Use this when you need to
    display a total result count alongside previous/next navigation.

**Next page**

.. code-block:: sql

    SELECT ...
    FROM   post p
    WHERE  p.created_at <= :dctrn_cursor_0
      AND  (   (p.created_at < :dctrn_cursor_0)
            OR (p.created_at = :dctrn_cursor_0 AND p.id < :dctrn_cursor_1))
    ORDER  BY p.created_at DESC, p.id DESC
    LIMIT  16   -- limit + 1

**Previous page**

.. code-block:: sql

    SELECT ...
    FROM   post p
    WHERE  p.created_at >= :dctrn_cursor_0
      AND  (   (p.created_at > :dctrn_cursor_0)
            OR (p.created_at = :dctrn_cursor_0 AND p.id > :dctrn_cursor_1))
    ORDER  BY p.created_at ASC, p.id ASC   -- reversed
    LIMIT  16

When the query is ordered by more than one column, the paginator puts an
inclusive bound on the first ``ORDER BY`` column in front of the cursor
condition (``p.created_at <= :dctrn_cursor_0`` above). The bound is redundant;
every row that matches the ``OR`` condition already satisfies it, so it does not
change which rows are returned. It is there for performance. Some platforms,
PostgreSQL among them, cannot derive an index range from the ``OR`` condition
alone and filter rows one by one, so a page gets slower the deeper it is in the
result set. The leading bound gives them a range to seek to on an index that
covers the ``ORDER BY`` columns.

HTML Template Example
~~~~~~~~~~~~~~~~~~~~~

The following example shows how to render a paginated list with previous/next
navigation links using the ``CursorPaginator`` in a PHP template:

.. literalinclude:: pagination/cursor-pagination.php
   :language: php

Cursor Encoding
~~~~~~~~~~~~~~~

A cursor is serialized to a URL-safe string via ``Cursor::encodeToString()`` and
deserialized back via the static ``Cursor::fromEncodedString()``. The format is a
JSON object encoded with URL-safe Base64 (no padding):

.. code-block:: json

    {
        "p.createdAt": "2024-01-15T10:30:00+00:00",
        "p.id": 42,
        "_isNext": true
    }

The ``_isNext`` flag distinguishes next-page cursors from previous-page cursors.
All other keys are the DQL path expressions (``alias.field``) of the ``ORDER BY``
columns, and their values are the database representations of the pivot row's
field values. A value that is not valid UTF-8, such as a UUID stored as binary (a
Symfony ``uuid`` or ``ulid`` on a platform without a native GUID type, for instance),
cannot be put in JSON as such: it is carried Base64 encoded, as a ``{"base64": "..."}``
object. When the next page is fetched, each value is bound as it is, with the binding
type of its field's type, and is never converted by that type.

A value that cannot be encoded as JSON at all, such as a non-finite float, makes
``Cursor::encodeToString()`` throw a ``JsonException``.

If you need a different serialization format (e.g. encryption), build it on top of
a ``Cursor`` instance: call ``$cursor->toArray()`` to get the raw data (a binary
value is raw bytes there), apply your own encoding, and reconstruct with
``new Cursor($parameters, $isNext)``.

Limitations
~~~~~~~~~~~

- Every ``ORDER BY`` column must map to an entity field. Raw SQL expressions or
  computed columns in ``ORDER BY`` are not supported.
- The query must have at least one ``ORDER BY`` item; the paginator throws a
  ``LogicException`` otherwise.

Writing Strategy-Agnostic Code
------------------------------

Both strategies share two interfaces, so code that only lists results can be
written once and work with either one:

``PaginatorInterface<T, TPosition>``
    Implemented by ``OffsetPaginator`` (a ``PaginatorInterface<T, Window>``) and
    ``CursorPaginator`` (a ``PaginatorInterface<T, Cursor|string|null>``). Its
    single method is
    ``paginate(Query|QueryBuilder $query, mixed $position = null): Page``.
    The native type of ``$position`` is ``mixed`` because the position type is
    strategy specific; the ``TPosition`` template parameter narrows it for static
    analysis.

``Page<T>``
    Implemented by ``WindowPage`` and ``CursorPage``. It extends ``Countable`` and
    ``IteratorAggregate`` and exposes ``getItems()``, ``getTotalCount()``,
    ``hasPreviousPage()``, ``hasNextPage()`` and ``hasToPaginate()``.

Navigating to another page is deliberately left out of ``Page``, since
the position types differ: ``WindowPage::getNextWindow()`` returns a ``Window``,
``CursorPage::getNextCursor()`` a ``Cursor``.

.. code-block:: php

    <?php
    use Doctrine\ORM\Query;
    use Doctrine\ORM\Tools\Pagination\PaginatorInterface;

    function renderTitles(PaginatorInterface $paginator, Query $query, mixed $position): void
    {
        $page = $paginator->paginate($query, $position);

        echo $page->getTotalCount() . " result(s), " . count($page) . " on this page\n";

        foreach ($page as $post) {
            echo $post->getTitle() . "\n";
        }
    }
