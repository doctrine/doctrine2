<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Persistence\Mapping\ClassMetadata;

/**
 * A swappable persister context to use as a container for the current
 * generated query/resultSetMapping/type binding information.
 *
 * This class is a utility class to be used only by the persister API
 *
 * This object is highly mutable due to performance reasons. Same reasoning
 * behind its properties being public.
 */
class CachedPersisterContext
{
    /**
     * Metadata object that describes the mapping of the mapped entity class.
     *
     * @var \Doctrine\ORM\Mapping\ClassMetadata
     */
    public $class;

    /**
     * ResultSetMapping that is used for all queries. Is generated lazily once per request.
     *
     * @var ResultSetMapping
     */
    public $rsm;

    /**
     * The SELECT column list SQL fragment used for querying entities by this persister.
     * This SQL fragment is only generated once per request, if at all.
     *
     * @var string|null
     */
    public $selectColumnListSql;

    /**
     * The JOIN SQL fragment used to eagerly load all many-to-one and one-to-one
     * associations configured as FETCH_EAGER, as well as all inverse one-to-one associations.
     *
     * @var string
     */
    public $selectJoinSql;

    /**
     * Counter for creating unique SQL table and column aliases.
     *
     * @var int
     */
    public $sqlAliasCounter = 0;

    /**
     * Map from class names (FQCN) to the corresponding generated SQL table aliases.
     *
     * @var array<class-string, string>
     */
    public $sqlTableAliases = [];

    /**
     * Whether this persistent context is considering limit operations applied to the selection queries
     *
     * @var bool
     */
    public $handlesLimits;

    /**
     * @param bool $handlesLimits
     */
    public function __construct(
        ClassMetadata $class,
        ResultSetMapping $rsm,
        $handlesLimits
    ) {
        $this->class         = $class;
        $this->rsm           = $rsm;
        $this->handlesLimits = (bool) $handlesLimits;
    }
}
