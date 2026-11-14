<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\TreeWalker;
use Doctrine\ORM\QueryBuilder;

use function array_key_exists;
use function array_map;
use function assert;
use function is_array;
use function is_string;

/**
 * Provides the implementation shared by {@see OffsetPaginator} and
 * {@see CursorPaginator}.
 *
 * Every method takes the query it works on as an argument, so that the
 * stateless paginators can serve any query.
 *
 * @internal
 */
trait PaginatorQuery
{
    /**
     * Whether to force the use of an output walker. Null lets the paginator
     * decide. Paginators take it as a constructor argument.
     */
    private bool|null $useOutputWalkers = null;

    /**
     * Determines whether to use an output walker for the query.
     */
    private function useOutputWalker(Query $query): bool
    {
        if ($this->useOutputWalkers === null) {
            return (bool) $query->getHint(Query::HINT_CUSTOM_OUTPUT_WALKER) === false;
        }

        return $this->useOutputWalkers;
    }

    private function resolveQuery(Query|QueryBuilder $query): Query
    {
        return $query instanceof QueryBuilder ? $query->getQuery() : $query;
    }

    private function cloneQuery(Query $query): Query
    {
        $cloneQuery = clone $query;

        $cloneQuery->setParameters(clone $query->getParameters());
        $cloneQuery->setCacheable(false);

        foreach ($query->getHints() as $name => $value) {
            $cloneQuery->setHint($name, $value);
        }

        return $cloneQuery;
    }

    /**
     * @param mixed[] $identifiers
     *
     * @return mixed[]
     */
    private function convertWhereInIdentifiersToDatabaseValues(Query $query, array $identifiers): array
    {
        $typeQuery = $this->cloneQuery($query);
        $typeQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, RootTypeWalker::class);

        $connection = $query->getEntityManager()->getConnection();
        $type       = $typeQuery->getSQL();
        assert(is_string($type));

        return array_map(static fn ($id): mixed => $connection->convertToDatabaseValue($id, $type), $identifiers);
    }

    private function getCountQuery(Query $query): Query
    {
        /*
            As opposed to using self::cloneQuery, the following code does not transfer
            a potentially existing result set mapping (either set directly by the user,
            or taken from the parser result from a previous invocation of Query::parse())
            to the new query object. This is fine, since we are going to completely change the
            select clause, so a previously existing result set mapping (RSM) is probably wrong anyway.
            In the case of using output walkers, we are even creating a new RSM down below.
            In the case of using a tree walker, we want to have a new RSM created by the parser.
        */
        $countQuery = new Query($query->getEntityManager());
        $countQuery->setDQL($query->getDQL());
        $countQuery->setParameters(clone $query->getParameters());
        $countQuery->setCacheable(false);
        foreach ($query->getHints() as $name => $value) {
            $countQuery->setHint($name, $value);
        }

        if (! $countQuery->hasHint(CountWalker::HINT_DISTINCT)) {
            $countQuery->setHint(CountWalker::HINT_DISTINCT, true);
        }

        if ($this->useOutputWalker($countQuery)) {
            $platform = $countQuery->getEntityManager()->getConnection()->getDatabasePlatform(); // law of demeter win

            $rsm = new ResultSetMapping();
            $rsm->addScalarResult($this->getSQLResultCasing($platform, 'dctrn_count'), 'count');

            $countQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, CountOutputWalker::class);
            $countQuery->setResultSetMapping($rsm);
        } else {
            $this->appendTreeWalker($countQuery, CountWalker::class);
            $this->unbindUnusedQueryParams($countQuery);
        }

        $countQuery->setFirstResult(0)->setMaxResults(null);

        return $countQuery;
    }

    /**
     * Executes the query for the given offset window and returns the entities.
     *
     * Used by {@see OffsetPaginator}: when a to-many collection is fetch-joined,
     * it uses the ID subquery + WHERE IN strategy to return the correct number
     * of root entities despite duplicate rows.
     *
     * The result keys are preserved (e.g. for DQL ``INDEX BY``); callers that
     * need a list should apply {@see array_values()} themselves.
     *
     * @return array<mixed>
     */
    private function getResultForOffset(Query $query, int $offset, int|null $length, bool $fetchJoinCollection): array
    {
        if ($fetchJoinCollection && $length !== null) {
            $subQuery = $this->cloneQuery($query);

            if ($this->useOutputWalker($subQuery)) {
                $subQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, LimitSubqueryOutputWalker::class);
            } else {
                $this->appendTreeWalker($subQuery, LimitSubqueryWalker::class);
                $this->unbindUnusedQueryParams($subQuery);
            }

            $subQuery->setFirstResult($offset)->setMaxResults($length);

            $foundIdRows = $subQuery->getScalarResult();

            // don't do this for an empty id array
            if ($foundIdRows === []) {
                return [];
            }

            $whereInQuery = $this->cloneQuery($query);
            $ids          = array_map('current', $foundIdRows);

            $this->appendTreeWalker($whereInQuery, WhereInWalker::class);
            $whereInQuery->setHint(WhereInWalker::HINT_PAGINATOR_HAS_IDS, true);
            $whereInQuery->setFirstResult(0)->setMaxResults(null);
            $whereInQuery->setCacheable($query->isCacheable());

            $databaseIds = $this->convertWhereInIdentifiersToDatabaseValues($query, $ids);
            $whereInQuery->setParameter(WhereInWalker::PAGINATOR_ID_ALIAS, $databaseIds);

            $result = $whereInQuery->getResult($query->getHydrationMode());
        } else {
            $result = $this->cloneQuery($query)
                ->setMaxResults($length)
                ->setFirstResult($offset)
                ->setCacheable($query->isCacheable())
                ->getResult($query->getHydrationMode());
        }

        assert(is_array($result));

        return $result;
    }

    /**
     * Appends a custom tree walker to the tree walkers hint.
     *
     * @param class-string<TreeWalker> $walkerClass
     */
    private function appendTreeWalker(Query $query, string $walkerClass): void
    {
        $hints = $query->getHint(Query::HINT_CUSTOM_TREE_WALKERS);

        if ($hints === false) {
            $hints = [];
        }

        $hints[] = $walkerClass;
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, $hints);
    }

    private function unbindUnusedQueryParams(Query $query): void
    {
        $parser            = new Parser($query);
        $parameterMappings = $parser->parse()->getParameterMappings();
        /** @var ArrayCollection<int, Parameter> $parameters */
        $parameters = $query->getParameters();

        foreach ($parameters as $key => $parameter) {
            $parameterName = $parameter->getName();

            if (! (isset($parameterMappings[$parameterName]) || array_key_exists($parameterName, $parameterMappings))) {
                unset($parameters[$key]);
            }
        }

        $query->setParameters($parameters);
    }
}
