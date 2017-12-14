<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Filter;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\ParameterTypeInferer;

/**
 * The base class that user defined filters should extend.
 *
 * Handles the setting and escaping of parameters.
 *
 * @author Alexander <iam.asm89@gmail.com>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @abstract
 */
abstract class SQLFilter
{
    /**
     * The entity manager.
     *
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * Parameters for the filter.
     *
     * @var array
     */
    private $parameters = [];

    /**
     * Constructs the SQLFilter object.
     *
     * @param EntityManagerInterface $em The entity manager.
     */
    final public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Sets a parameter that can be used by the filter.
     *
     * @param string      $name  Name of the parameter.
     * @param string      $value Value of the parameter.
     * @param string|null $type  The parameter type. If specified, the given value will be run through
     *                           the type conversion of this type. This is usually not needed for
     *                           strings and numeric types.
     *
     * @return SQLFilter The current SQL filter.
     */
    final public function setParameter($name, $value, $type = null)
    {
        if ($type === null) {
            $type = ParameterTypeInferer::inferType($value);
        }

        $this->parameters[$name] = ['value' => $value, 'type' => $type];

        // Keep the parameters sorted for the hash
        ksort($this->parameters);

        // The filter collection of the EM is now dirty
        $this->em->getFilters()->setFiltersStateDirty();

        return $this;
    }

    /**
     * Gets a parameter to use in a query.
     *
     * The function is responsible for the right output escaping to use the
     * value in a query.
     *
     * @param string $name Name of the parameter.
     *
     * @return string The SQL escaped parameter to use in a query.
     *
     * @throws \InvalidArgumentException
     */
    final public function getParameter($name)
    {
        if (!isset($this->parameters[$name])) {
            throw new \InvalidArgumentException("Parameter '" . $name . "' does not exist.");
        }

        return $this->em->getConnection()->quote($this->parameters[$name]['value'], $this->parameters[$name]['type']);
    }

    /**
     * Checks if a parameter was set for the filter.
     *
     * @param string $name Name of the parameter.
     *
     * @return boolean
     */
    final public function hasParameter($name)
    {
        if (!isset($this->parameters[$name])) {
            return false;
        }

        return true;
    }

    /**
     * Returns as string representation of the SQLFilter parameters (the state).
     *
     * @return string String representation of the SQLFilter.
     */
    final public function __toString()
    {
        return serialize($this->parameters);
    }

    /**
     * Returns the database connection used by the entity manager
     *
     * @return \Doctrine\DBAL\Connection
     */
    final protected function getConnection()
    {
        return $this->em->getConnection();
    }

    /**
     * Gets the SQL query part to add to a query.
     *
     * @param ClassMetaData $targetEntity
     * @param string        $targetTableAlias
     *
     * @return string The constraint SQL if there is available, empty string otherwise.
     */
    abstract public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias);
}
