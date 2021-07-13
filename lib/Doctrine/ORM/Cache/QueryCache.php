<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Query\ResultSetMapping;

/**
 * Defines the contract for caches capable of storing query results.
 * These caches should only concern themselves with storing the matching result ids.
 */
interface QueryCache
{
    /**
     * @return bool
     */
    public function clear();

    /**
     * @param mixed   $result
     * @param mixed[] $hints
     *
     * @return bool
     */
    public function put(QueryCacheKey $key, ResultSetMapping $rsm, $result, array $hints = []);

    /**
     * @param mixed[] $hints
     *
     * @return mixed[]|null
     */
    public function get(QueryCacheKey $key, ResultSetMapping $rsm, array $hints = []);

    /**
     * @return Region
     */
    public function getRegion();
}
