<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Query\Filter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\ParameterTypeInferer;
use InvalidArgumentException;
use RuntimeException;

use function array_map;
use function implode;
use function is_array;
use function ksort;
use function serialize;

/**
 * The base class that user defined filters should extend.
 *
 * Handles the setting and escaping of parameters.
 *
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
     * @psalm-var array<string,array{type: string, value: mixed, is_list: bool}>
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
     * Sets a parameter list that can be used by the filter.
     *
     * @param string       $name   Name of the parameter.
     * @param array<mixed> $values List of parameter values.
     * @param string       $type   The parameter type. If specified, the given value will be run through
     *                             the type conversion of this type.
     *
     * @return self The current SQL filter.
     */
    final public function setParameterList(string $name, $values, string $type = Types::STRING): self
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException('Value provided to setParameterList must be array.');
        }

        $this->parameters[$name] = ['value' => $values, 'type' => $type, 'is_list' => true];

        // Keep the parameters sorted for the hash
        ksort($this->parameters);

        // The filter collection of the EM is now dirty
        $this->em->getFilters()->setFiltersStateDirty();

        return $this;
    }

    /**
     * Sets a parameter that can be used by the filter.
     *
     * @param string      $name  Name of the parameter.
     * @param mixed       $value Value of the parameter.
     * @param string|null $type  The parameter type. If specified, the given value will be run through
     *                           the type conversion of this type. This is usually not needed for
     *                           strings and numeric types.
     *
     * @return self The current SQL filter.
     */
    final public function setParameter($name, $value, $type = null): self
    {
        if ($type === null) {
            $type = ParameterTypeInferer::inferType($value);
        }

        $this->parameters[$name] = ['value' => $value, 'type' => $type, 'is_list' => false];

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
     * @throws InvalidArgumentException
     */
    final public function getParameter($name)
    {
        if (! isset($this->parameters[$name])) {
            throw new InvalidArgumentException("Parameter '" . $name . "' does not exist.");
        }

        if ($this->parameters[$name]['is_list']) {
            throw FilterException::cannotConvertListParameterIntoSingleValue($name);
        }

        $param = $this->parameters[$name];

        return $this->em->getConnection()->quote($param['value'], $param['type']);
    }

    /**
     * Gets a parameter to use in a query assuming its a list of entries.
     *
     * The function is responsible for the right output escaping to use the
     * value in a query, seperating each entry by comma to inline it into
     * an IN() query part.
     *
     * @param string $name Name of the parameter.
     *
     * @return string The SQL escaped parameter to use in a query.
     *
     * @throws InvalidArgumentException
     */
    final public function getParameterList(string $name): string
    {
        if (! isset($this->parameters[$name])) {
            throw new InvalidArgumentException("Parameter '" . $name . "' does not exist.");
        }

        if ($this->parameters[$name]['is_list'] === false) {
            throw FilterException::cannotConvertSingleParameterIntoListValue($name);
        }

        $param      = $this->parameters[$name];
        $connection = $this->em->getConnection();

        $quoted = array_map(static function ($value) use ($connection, $param) {
            return $connection->quote($value, $param['type']);
        }, $param['value']);

        return implode(',', $quoted);
    }

    /**
     * Checks if a parameter was set for the filter.
     *
     * @param string $name Name of the parameter.
     *
     * @return bool
     */
    final public function hasParameter($name)
    {
        return isset($this->parameters[$name]);
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
     * @return Connection
     */
    final protected function getConnection()
    {
        return $this->em->getConnection();
    }

    /**
     * Gets the SQL query part to add to a query.
     *
     * @param string $targetTableAlias
     *
     * @return string The constraint SQL if there is available, empty string otherwise.
     */
    abstract public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias);
}
