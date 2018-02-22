<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\DBAL\FetchMode;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use function count;
use function end;
use function is_array;
use function key;
use function reset;

/**
 * The ArrayHydrator produces a nested array "graph" that is often (not always)
 * interchangeable with the corresponding object graph for read-only access.
 */
class ArrayHydrator extends AbstractHydrator
{
    /**
     * @var bool[]
     */
    private $rootAliases = [];

    /**
     * @var bool
     */
    private $isSimpleQuery = false;

    /**
     * @var mixed[][]
     */
    private $identifierMap = [];

    /**
     * @var mixed[]
     */
    private $resultPointers = [];

    /**
     * @var string[]
     */
    private $idTemplate = [];

    /**
     * @var int
     */
    private $resultCounter = 0;

    /**
     * {@inheritdoc}
     */
    protected function prepare()
    {
        $simpleQuery = 0;

        foreach ($this->rsm->aliasMap as $dqlAlias => $className) {
            $this->identifierMap[$dqlAlias]  = [];
            $this->resultPointers[$dqlAlias] = [];
            $this->idTemplate[$dqlAlias]     = '';
            $simpleQuery                    += 1; // avoiding counting the alias map
        }

        $this->isSimpleQuery = $simpleQuery < 2;
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateAllData()
    {
        $result = [];

        while ($data = $this->stmt->fetch(FetchMode::ASSOCIATIVE)) {
            $this->hydrateRowData($data, $result);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateRowData(array $row, array &$result)
    {
        // 1) Initialize
        $id                 = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        $rowData            = $this->gatherRowData($row, $id, $nonemptyComponents);

        // 2) Now hydrate the data found in the current row.
        foreach ($rowData['data'] as $dqlAlias => $data) {
            $index = false;

            if (isset($this->rsm->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parent = $this->rsm->parentAliasMap[$dqlAlias];
                $path   = $parent . '.' . $dqlAlias;

                // missing parent data, skipping as RIGHT JOIN hydration is not supported.
                if (! isset($nonemptyComponents[$parent])) {
                    continue;
                }

                // Get a reference to the right element in the result tree.
                // This element will get the associated element attached.
                if ($this->rsm->isMixed && isset($this->rootAliases[$parent])) {
                    $first = reset($this->resultPointers);
                    // TODO: Exception if $key === null ?
                    $baseElement =& $this->resultPointers[$parent][key($first)];
                } elseif (isset($this->resultPointers[$parent])) {
                    $baseElement =& $this->resultPointers[$parent];
                } else {
                    unset($this->resultPointers[$dqlAlias]); // Ticket #1228

                    continue;
                }

                $relationAlias = $this->rsm->relationMap[$dqlAlias];
                $parentClass   = $this->metadataCache[$this->rsm->aliasMap[$parent]];
                $relation      = $parentClass->getProperty($relationAlias);

                // Check the type of the relation (many or single-valued)
                if (! $relation instanceof ToOneAssociationMetadata) {
                    $oneToOne = false;

                    if (! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = [];
                    }

                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $indexExists  = isset($this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]]);
                        $index        = $indexExists ? $this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? isset($baseElement[$relationAlias][$index]) : false;

                        if (! $indexExists || ! $indexIsValid) {
                            $element = $data;

                            if (isset($this->rsm->indexByMap[$dqlAlias])) {
                                $baseElement[$relationAlias][$row[$this->rsm->indexByMap[$dqlAlias]]] = $element;
                            } else {
                                $baseElement[$relationAlias][] = $element;
                            }

                            end($baseElement[$relationAlias]);

                            $this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]] = key($baseElement[$relationAlias]);
                        }
                    }
                } else {
                    $oneToOne = true;

                    if (( ! isset($nonemptyComponents[$dqlAlias])) &&
                        ( ! isset($baseElement[$relationAlias]))
                    ) {
                        $baseElement[$relationAlias] = null;
                    } elseif (! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = $data;
                    }
                }

                $coll =& $baseElement[$relationAlias];

                if (is_array($coll)) {
                    $this->updateResultPointer($coll, $index, $dqlAlias, $oneToOne);
                }
            } else {
                // It's a root result element

                $this->rootAliases[$dqlAlias] = true; // Mark as root
                $entityKey                    = $this->rsm->entityMappings[$dqlAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (! isset($nonemptyComponents[$dqlAlias])) {
                    $result[] = $this->rsm->isMixed
                        ? [$entityKey => null]
                        : null;

                    $resultKey = $this->resultCounter;
                    ++$this->resultCounter;

                    continue;
                }

                // Check for an existing element
                if ($this->isSimpleQuery || ! isset($this->identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                    $element = $this->rsm->isMixed
                        ? [$entityKey => $data]
                        : $data;

                    if (isset($this->rsm->indexByMap[$dqlAlias])) {
                        $resultKey          = $row[$this->rsm->indexByMap[$dqlAlias]];
                        $result[$resultKey] = $element;
                    } else {
                        $resultKey = $this->resultCounter;
                        $result[]  = $element;

                        ++$this->resultCounter;
                    }

                    $this->identifierMap[$dqlAlias][$id[$dqlAlias]] = $resultKey;
                } else {
                    $index     = $this->identifierMap[$dqlAlias][$id[$dqlAlias]];
                    $resultKey = $index;
                }

                $this->updateResultPointer($result, $index, $dqlAlias, false);
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                // this only ever happens when no object is fetched (scalar result only)
                $resultKey = isset($this->rsm->indexByMap['scalars'])
                    ? $row[$this->rsm->indexByMap['scalars']]
                    : $this->resultCounter - 1;
            }

            foreach ($rowData['scalars'] as $name => $value) {
                $result[$resultKey][$name] = $value;
            }
        }

        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (! isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }

            $scalarCount      = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);
            $onlyOneRootAlias = $scalarCount === 0 && count($rowData['newObjects']) === 1;

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $args   = $newObject['args'];
                $obj    = $newObject['obj'];

                if ($onlyOneRootAlias || count($args) === $scalarCount) {
                    $result[$resultKey] = $obj;

                    continue;
                }

                $result[$resultKey][$objIndex] = $obj;
            }
        }
    }

    /**
     * Updates the result pointer for an Entity. The result pointers point to the
     * last seen instance of each Entity type. This is used for graph construction.
     *
     * @param mixed[]  $coll     The element.
     * @param bool|int $index    Index of the element in the collection.
     * @param string   $dqlAlias
     * @param bool     $oneToOne Whether it is a single-valued association or not.
     *
     */
    private function updateResultPointer(array &$coll, $index, $dqlAlias, $oneToOne)
    {
        if ($coll === null) {
            unset($this->resultPointers[$dqlAlias]); // Ticket #1228

            return;
        }

        if ($oneToOne) {
            $this->resultPointers[$dqlAlias] =& $coll;

            return;
        }

        if ($index !== false) {
            $this->resultPointers[$dqlAlias] =& $coll[$index];

            return;
        }

        if (! $coll) {
            return;
        }

        end($coll);
        $this->resultPointers[$dqlAlias] =& $coll[key($coll)];
    }
}
