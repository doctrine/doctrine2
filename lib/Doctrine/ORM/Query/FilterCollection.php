<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use InvalidArgumentException;

use function assert;
use function ksort;

/**
 * Collection class for all the query filters.
 */
class FilterCollection
{
    /* Filter STATES */

    /**
     * A filter object is in CLEAN state when it has no changed parameters.
     */
    public const FILTERS_STATE_CLEAN = 1;

    /**
     * A filter object is in DIRTY state when it has changed parameters.
     */
    public const FILTERS_STATE_DIRTY = 2;

    /**
     * The used Configuration.
     *
     * @var Configuration
     */
    private $config;

    /**
     * The EntityManager that "owns" this FilterCollection instance.
     *
     * @var EntityManager
     */
    private $em;

    /**
     * Instances of enabled filters.
     *
     * @var SQLFilter[]
     */
    private $enabledFilters = [];

    /** @var string The filter hash from the last time the query was parsed. */
    private $filterHash;

    /** @var int The current state of this filter. */
    private $filtersState = self::FILTERS_STATE_CLEAN;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em     = $em;
        $this->config = $em->getConfiguration();
    }

    /**
     * Gets all the enabled filters.
     *
     * @return SQLFilter[] The enabled filters.
     */
    public function getEnabledFilters()
    {
        return $this->enabledFilters;
    }

    /**
     * Enables a filter from the collection.
     *
     * @param string $name Name of the filter.
     *
     * @return SQLFilter The enabled filter.
     *
     * @throws InvalidArgumentException If the filter does not exist.
     */
    public function enable($name)
    {
        if (! $this->has($name)) {
            throw new InvalidArgumentException("Filter '" . $name . "' does not exist.");
        }

        if (! $this->isEnabled($name)) {
            $filterClass = $this->config->getFilterClassName($name);

            assert($filterClass !== null);

            $this->enabledFilters[$name] = new $filterClass($this->em);

            // Keep the enabled filters sorted for the hash
            ksort($this->enabledFilters);

            // Now the filter collection is dirty
            $this->filtersState = self::FILTERS_STATE_DIRTY;
        }

        return $this->enabledFilters[$name];
    }

    /**
     * Disables a filter.
     *
     * @param string $name Name of the filter.
     *
     * @return SQLFilter The disabled filter.
     *
     * @throws InvalidArgumentException If the filter does not exist.
     */
    public function disable($name)
    {
        // Get the filter to return it
        $filter = $this->getFilter($name);

        unset($this->enabledFilters[$name]);

        // Now the filter collection is dirty
        $this->filtersState = self::FILTERS_STATE_DIRTY;

        return $filter;
    }

    /**
     * Gets an enabled filter from the collection.
     *
     * @param string $name Name of the filter.
     *
     * @return SQLFilter The filter.
     *
     * @throws InvalidArgumentException If the filter is not enabled.
     */
    public function getFilter($name)
    {
        if (! $this->isEnabled($name)) {
            throw new InvalidArgumentException("Filter '" . $name . "' is not enabled.");
        }

        return $this->enabledFilters[$name];
    }

    /**
     * Checks whether filter with given name is defined.
     *
     * @param string $name Name of the filter.
     *
     * @return bool true if the filter exists, false if not.
     */
    public function has($name)
    {
        return $this->config->getFilterClassName($name) !== null;
    }

    /**
     * Checks if a filter is enabled.
     *
     * @param string $name Name of the filter.
     *
     * @return bool True if the filter is enabled, false otherwise.
     */
    public function isEnabled($name)
    {
        return isset($this->enabledFilters[$name]);
    }

    /**
     * @return bool True, if the filter collection is clean.
     */
    public function isClean()
    {
        return $this->filtersState === self::FILTERS_STATE_CLEAN;
    }

    /**
     * Generates a string of currently enabled filters to use for the cache id.
     *
     * @return string
     */
    public function getHash()
    {
        // If there are only clean filters, the previous hash can be returned
        if ($this->filtersState === self::FILTERS_STATE_CLEAN) {
            return $this->filterHash;
        }

        $filterHash = '';

        foreach ($this->enabledFilters as $name => $filter) {
            $filterHash .= $name . $filter;
        }

        return $filterHash;
    }

    /**
     * Sets the filter state to dirty.
     *
     * @return void
     */
    public function setFiltersStateDirty()
    {
        $this->filtersState = self::FILTERS_STATE_DIRTY;
    }
}
