<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use function microtime;

/**
 * Query cache entry
 */
class QueryCacheEntry implements CacheEntry
{
    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var array<string, mixed> List of entity identifiers
     */
    public $result;

    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var float Time creation of this cache entry
     */
    public $time;

    /**
     * @param array<string, mixed> $result
     * @param float                $time
     */
    public function __construct($result, $time = null)
    {
        $this->result = $result;
        $this->time   = $time ?: microtime(true);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return QueryCacheEntry
     */
    public static function __set_state(array $values)
    {
        return new self($values['result'], $values['time']);
    }
}
