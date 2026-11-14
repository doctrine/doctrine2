<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Exception\InvalidCursor;
use Doctrine\ORM\Utility\PersisterHelper;
use LogicException;

use function array_map;
use function array_reverse;
use function array_slice;
use function array_sum;
use function array_values;
use function assert;
use function count;
use function is_string;
use function sprintf;

/**
 * The cursor paginator handles cursor-based pagination for DQL queries.
 *
 * The query and the cursor are both passed to {@see paginate()}, which returns
 * an immutable {@see CursorPage} rather than the paginator itself. The paginator
 * therefore holds no state beyond its configuration: a single instance can be
 * shared as a service and reused for any query and any page. This keeps the
 * result free of temporal coupling and symmetric with {@see OffsetPaginator}.
 *
 * Unlike the offset-based {@see Window}, which carries its own page size, a
 * {@see Cursor} is a pure position: the page size is therefore paginator
 * configuration and never comes from user input.
 *
 * @template-covariant T
 * @implements PaginatorInterface<T, Cursor|string|null>
 */
final class CursorPaginator implements PaginatorInterface
{
    use SQLResultCasing;
    use PaginatorQuery;

    /**
     * @param int       $limit                   The maximum number of results per page.
     * @param bool      $queryProducesDuplicates Whether the query joins a collection (true by default).
     * @param bool|null $useOutputWalkers        Whether to force the use of an output walker. Null (default)
     *                                           lets the paginator decide.
     */
    public function __construct(
        private readonly int $limit,
        private readonly bool $queryProducesDuplicates = true,
        bool|null $useOutputWalkers = null,
    ) {
        $this->useOutputWalkers = $useOutputWalkers;
    }

    /**
     * Returns the maximum number of results per page.
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Returns whether the query joins a collection.
     *
     * @return bool Whether the query joins a collection.
     */
    public function getQueryProducesDuplicates(): bool
    {
        return $this->queryProducesDuplicates;
    }

    /**
     * Executes the query for the given cursor and returns an immutable page.
     *
     * Fetches $limit + 1 rows to detect whether a further page exists, then
     * trims the extra row.
     *
     * @param Cursor|string|null $position A cursor instance, an encoded cursor string, or
     *                                     null/empty string for the first page. Any other
     *                                     type (e.g. an array) throws InvalidCursor.
     *
     * @return CursorPage<T>
     *
     * @throws InvalidCursor If $position is not a Cursor instance, a string, or null.
     */
    public function paginate(Query|QueryBuilder $query, mixed $position = null): CursorPage
    {
        $query  = $this->resolveQuery($query);
        $cursor = $this->resolveCursor($position);

        $shouldReverse = $cursor?->isPrevious() ?? false;

        $subQuery = $this->cloneQuery($query);
        // Cursor values live in HINT_CURSOR_PARAMETERS, which is part of the query-cache id,
        // so every cursor would write its own entry. A cached ParserResult would also skip
        // CursorWalker: a repeated cursor would leave dctrn_cursor_* unbound, and a repeated
        // first page would build the next cursor from an unset order-by hint.
        $subQuery->useQueryCache(false);

        $this->appendTreeWalker($subQuery, CursorWalker::class);
        $subQuery->setHint(CursorWalker::HINT_QUERY_PRODUCES_DUPLICATES, $this->queryProducesDuplicates);

        if ($this->queryProducesDuplicates) {
            if ($this->useOutputWalker($subQuery)) {
                $subQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, LimitSubqueryOutputWalker::class);
            } else {
                $this->appendTreeWalker($subQuery, LimitSubqueryWalker::class);
                $this->unbindUnusedQueryParams($subQuery);
            }
        }

        $subQuery->setHint(CursorWalker::HINT_CURSOR_REVERSE, $shouldReverse);
        $subQuery->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, $cursor?->getParameters() ?? []);
        $subQuery->setFirstResult(0)->setMaxResults($this->limit + 1);

        if ($this->queryProducesDuplicates) {
            $foundIdRows  = $subQuery->getScalarResult();
            $orderByItems = $subQuery->getHint(CursorWalker::HINT_CURSOR_ORDER_BY_ITEMS) ?: [];
            $hasMore      = count($foundIdRows) > $this->limit;
            $foundIdRows  = array_slice($foundIdRows, 0, $this->limit);

            if ($foundIdRows === []) {
                $items = [];
            } else {
                $whereInQuery = $this->cloneQuery($query);
                $ids          = array_map('current', $foundIdRows);

                $this->appendTreeWalker($whereInQuery, WhereInWalker::class);
                $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
                $whereInQuery->setFirstResult(0)->setMaxResults(null);
                $whereInQuery->setCacheable($query->isCacheable());

                $databaseIds = $this->convertWhereInIdentifiersToDatabaseValues($query, $ids);
                $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, $databaseIds);

                $items = array_values($whereInQuery->getResult());
            }
        } else {
            $result       = $subQuery->getResult();
            $hasMore      = count($result) > $this->limit;
            $result       = array_slice($result, 0, $this->limit);
            $orderByItems = $subQuery->getHint(CursorWalker::HINT_CURSOR_ORDER_BY_ITEMS) ?: [];

            if ($cursor !== null && $cursor->isPrevious()) {
                $result = array_reverse($result);
            }

            $items = array_values($result);
        }

        return new CursorPage(
            $items,
            $hasMore,
            $cursor,
            fn (): int => (int) array_sum(array_map('current', $this->getCountQuery($query)->getScalarResult())),
            fn (mixed $item, bool $isNext): Cursor => new Cursor($this->getParametersForItem($query, $item, $orderByItems), $isNext),
        );
    }

    /** @throws InvalidCursor If $position is not a Cursor instance, a string, or null. */
    private function resolveCursor(mixed $position): Cursor|null
    {
        if ($position instanceof Cursor) {
            return $position;
        }

        if ($position === null || $position === '') {
            return null;
        }

        if (! is_string($position)) {
            throw new InvalidCursor('(non-string value)');
        }

        return Cursor::fromEncodedString($position);
    }

    /**
     * @param list<CursorOrderByItem> $orderByItems
     *
     * @return array<string, mixed>
     *
     * @throws Query\QueryException
     * @throws Exception
     */
    private function getParametersForItem(Query $query, mixed $item, array $orderByItems): array
    {
        $em         = $query->getEntityManager();
        $connection = $em->getConnection();
        $metadata   = $em->getMetadataFactory()->isMapped($item::class)
            ? $em->getClassMetadata($item::class)
            : null;

        $result = [];

        foreach ($orderByItems as $orderByItem) {
            if (! $orderByItem->expression instanceof PathExpression) {
                continue;
            }

            $fieldName     = $orderByItem->expression->field;
            $orderMetadata = $orderByItem->metadata ?? $metadata;
            $value         = $metadata === null || $orderByItem->associationPath === []
                ? $metadata?->getFieldValue($item, $fieldName) ?? $item->$fieldName
                : $this->getJoinedFieldValue($em, $item, $metadata, $orderByItem);

            if ($value !== null && $orderMetadata !== null && isset($orderMetadata->associationMappings[$fieldName])) {
                $targetMetadata = $em->getClassMetadata($orderMetadata->associationMappings[$fieldName]->targetEntity);
                $value          = $targetMetadata->getIdentifierValues($value)[$targetMetadata->getSingleIdentifierFieldName()] ?? null;
            }

            $type = PersisterHelper::getTypeOfField($fieldName, $orderMetadata, $em)[0];

            $result[$orderByItem->paramKey] = $connection->convertToDatabaseValue($value, $type);
        }

        return $result;
    }

    /**
     * Reads the value of an ORDER BY item that is on a joined alias, by following
     * the single-valued associations leading from the root entity to that alias.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @throws LogicException If the alias cannot be reached from the root entity.
     */
    private function getJoinedFieldValue(EntityManagerInterface $em, object $item, ClassMetadata $metadata, CursorOrderByItem $orderByItem): mixed
    {
        assert($orderByItem->expression instanceof PathExpression);

        if ($orderByItem->associationPath === null || $orderByItem->metadata === null) {
            throw new LogicException(sprintf(
                'Cannot build a cursor for the ORDER BY item "%s": its alias must be the root alias, or be joined from it through single-valued associations only.',
                $orderByItem->paramKey,
            ));
        }

        foreach ($orderByItem->associationPath as $associationName) {
            $item = $metadata->getFieldValue($item, $associationName);

            if ($item === null) {
                return null;
            }

            // the joined entity is an uninitialized proxy when its alias is not selected
            $em->initializeObject($item);

            $metadata = $em->getClassMetadata($metadata->associationMappings[$associationName]->targetEntity);
        }

        return $orderByItem->metadata->getFieldValue($item, $orderByItem->expression->field);
    }
}
