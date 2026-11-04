<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use function array_key_last;
use function count;
use function is_array;
use function key;
use function reset;

/**
 * The ArrayHydrator produces a nested array "graph" that is often (not always)
 * interchangeable with the corresponding object graph for read-only access.
 *
 * Elements of DQL aliases that other aliases are joined to are stored as nodes
 * in a flat list and referenced by their node id, so that joined elements can be
 * attached to them in later rows. The nested arrays are built once in {@see takeResult()}.
 */
class ArrayHydrator extends AbstractHydrator
{
    private bool $isSimpleQuery = false;

    /** @var mixed[] */
    private array $identifierMap = [];

    /**
     * The node id of the last seen element per DQL alias.
     *
     * @var array<string, int>
     */
    private array $resultPointers = [];

    private int $resultCounter = 0;

    /** @var array<string, bool> */
    private array $legacyRootAliases = [];

    /** @var mixed[] */
    private array $legacyResultPointers = [];

    /**
     * DQL aliases that other aliases are joined to and whose elements are stored as nodes.
     *
     * @var array<string, true>
     */
    private array $nodeAliases = [];

    /**
     * Relation aliases per DQL alias that hold node ids.
     *
     * @var array<string, array<string, true>>
     */
    private array $nodeRelations = [];

    /** @var list<mixed[]> */
    private array $nodes = [];

    /** @var list<string> */
    private array $nodeDqlAliases = [];

    /** @var list<array{array-key, array-key|null, int}> */
    private array $rootNodes = [];

    protected function prepare(): void
    {
        $resultSetMapping    = $this->resultSetMapping();
        $this->isSimpleQuery = count($resultSetMapping->aliasMap) <= 1;

        foreach ($resultSetMapping->aliasMap as $dqlAlias => $className) {
            $this->identifierMap[$dqlAlias]        = [];
            $this->legacyResultPointers[$dqlAlias] = [];
            $this->rowIdTemplate[$dqlAlias]        = '';
        }

        foreach ($resultSetMapping->parentAliasMap as $parent) {
            $this->nodeAliases[$parent] = true;
        }

        foreach ($resultSetMapping->parentAliasMap as $dqlAlias => $parent) {
            if (isset($this->nodeAliases[$dqlAlias])) {
                $this->nodeRelations[$parent][$resultSetMapping->relationMap[$dqlAlias]] = true;
            }
        }
    }

    protected function cleanup(): void
    {
        parent::cleanup();

        $this->identifierMap  =
        $this->resultPointers =
        $this->nodeAliases    =
        $this->nodeRelations  =
        $this->nodes          =
        $this->nodeDqlAliases =
        $this->rootNodes      = [];
        $this->resultCounter  = 0;
    }

    /**
     * {@inheritDoc}
     */
    protected function hydrateAllData(): array
    {
        $this->hydrateAllRows();

        return $this->takeResult();
    }

    /**
     * {@inheritDoc}
     */
    protected function takeResult(): array
    {
        if (count($this->nodeDqlAliases) > 0) {
            $this->materializeNodes();
        }

        return parent::takeResult();
    }

    /**
     * {@inheritDoc}
     */
    protected function hydrateRow(array $row): void
    {
        $rowData            = $this->gatherRow($row);
        $id                 = $this->gatheredRowId;
        $nonemptyComponents = $this->gatheredNonemptyComponents;

        if ($this->iterating) {
            $resultKey = $this->hydrateIteratedRowEntities($row, $rowData['data'], $nonemptyComponents);
        } else {
            foreach ($rowData['data'] as $dqlAlias => $data) {
                if (isset($this->resultSetMapping()->parentAliasMap[$dqlAlias])) {
                    // It's a joined result

                    $parent = $this->resultSetMapping()->parentAliasMap[$dqlAlias];
                    $path   = $parent . '.' . $dqlAlias;

                    // missing parent data, skipping as RIGHT JOIN hydration is not supported.
                    if (! isset($nonemptyComponents[$parent])) {
                        continue;
                    }

                    if (! isset($this->resultPointers[$parent])) {
                        unset($this->resultPointers[$dqlAlias]); // Ticket #1228

                        continue;
                    }

                    $baseNode      = $this->resultPointers[$parent];
                    $relationAlias = $this->resultSetMapping()->relationMap[$dqlAlias];
                    $parentClass   = $this->metadataCache[$this->resultSetMapping()->aliasMap[$parent]];
                    $relation      = $parentClass->associationMappings[$relationAlias];

                    // Check the type of the relation (many or single-valued)
                    if (! $relation->isToOne()) {
                        $index = false;

                        if (! isset($this->nodes[$baseNode][$relationAlias])) {
                            $this->nodes[$baseNode][$relationAlias] = [];
                        }

                        if (isset($nonemptyComponents[$dqlAlias])) {
                            $indexExists  = isset($this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]]);
                            $index        = $indexExists ? $this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]] : false;
                            $indexIsValid = $index !== false ? isset($this->nodes[$baseNode][$relationAlias][$index]) : false;

                            if (! $indexExists || ! $indexIsValid) {
                                $element = isset($this->nodeAliases[$dqlAlias]) ? $this->createNode($data, $dqlAlias) : $data;

                                if (isset($this->resultSetMapping()->indexByMap[$dqlAlias])) {
                                    $this->nodes[$baseNode][$relationAlias][$row[$this->resultSetMapping()->indexByMap[$dqlAlias]]] = $element;
                                } else {
                                    $this->nodes[$baseNode][$relationAlias][] = $element;
                                }

                                $index = array_key_last($this->nodes[$baseNode][$relationAlias]);

                                $this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]] = $index;
                            }
                        }

                        if (! isset($this->nodeAliases[$dqlAlias])) {
                            continue;
                        }

                        if ($index === false) {
                            $index = array_key_last($this->nodes[$baseNode][$relationAlias]);
                        }

                        if ($index !== null && isset($this->nodes[$baseNode][$relationAlias][$index])) {
                            $this->resultPointers[$dqlAlias] = $this->nodes[$baseNode][$relationAlias][$index];
                        }
                    } else {
                        if (! isset($this->nodes[$baseNode][$relationAlias])) {
                            $this->nodes[$baseNode][$relationAlias] = match (true) {
                                ! isset($nonemptyComponents[$dqlAlias]) => null,
                                isset($this->nodeAliases[$dqlAlias]) => $this->createNode($data, $dqlAlias),
                                default => $data,
                            };
                        }

                        if (isset($this->nodeAliases[$dqlAlias], $this->nodes[$baseNode][$relationAlias])) {
                            $this->resultPointers[$dqlAlias] = $this->nodes[$baseNode][$relationAlias];
                        }
                    }
                } else {
                    // It's a root result element

                    $entityKey = $this->resultSetMapping()->entityMappings[$dqlAlias] ?: 0;

                    // if this row has a NULL value for the root result id then make it a null result.
                    if (! isset($nonemptyComponents[$dqlAlias])) {
                        $this->hydrationResult[] = $this->resultSetMapping()->isMixed
                            ? [$entityKey => null]
                            : null;

                        $resultKey = $this->resultCounter;
                        ++$this->resultCounter;

                        continue;
                    }

                    // Check for an existing element
                    if ($this->isSimpleQuery || ! isset($this->identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                        $node    = isset($this->nodeAliases[$dqlAlias]) ? $this->createNode($data, $dqlAlias) : null;
                        $element = $node ?? $data;

                        if (isset($this->resultSetMapping()->indexByMap[$dqlAlias])) {
                            $resultKey                         = $row[$this->resultSetMapping()->indexByMap[$dqlAlias]];
                            $this->hydrationResult[$resultKey] = $this->resultSetMapping()->isMixed
                                ? [$entityKey => $element]
                                : $element;
                        } else {
                            $resultKey               = $this->resultCounter;
                            $this->hydrationResult[] = $this->resultSetMapping()->isMixed
                                ? [$entityKey => $element]
                                : $element;

                            ++$this->resultCounter;
                        }

                        $this->identifierMap[$dqlAlias][$id[$dqlAlias]] = $resultKey;

                        if ($node !== null) {
                            $this->resultPointers[$dqlAlias] = $node;
                            $this->rootNodes[]               = [
                                array_key_last($this->hydrationResult),
                                $this->resultSetMapping()->isMixed ? $entityKey : null,
                                $node,
                            ];
                        }
                    } else {
                        $resultKey = $this->identifierMap[$dqlAlias][$id[$dqlAlias]];

                        if (isset($this->nodeAliases[$dqlAlias])) {
                            $this->resultPointers[$dqlAlias] = $this->resultSetMapping()->isMixed
                                ? $this->hydrationResult[$resultKey][$entityKey]
                                : $this->hydrationResult[$resultKey];
                        }
                    }
                }
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                // this only ever happens when no object is fetched (scalar result only)
                $resultKey = isset($this->resultSetMapping()->indexByMap['scalars'])
                    ? $row[$this->resultSetMapping()->indexByMap['scalars']]
                    : $this->resultCounter - 1;
            }

            foreach ($rowData['scalars'] as $name => $value) {
                $this->hydrationResult[$resultKey][$name] = $value;
            }
        }

        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (! isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }

            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $args = $newObject['args'];
                $obj  = $newObject['obj'];

                if (count($args) === $scalarCount || ($scalarCount === 0 && count($rowData['newObjects']) === 1)) {
                    $this->hydrationResult[$resultKey] = $obj;

                    continue;
                }

                $this->hydrationResult[$resultKey][$objIndex] = $obj;
            }
        }
    }

    /**
     * Hydrates a row into the given result by reference.
     *
     * Only used for subclasses that still override this deprecated method.
     *
     * @deprecated Override and call {@see hydrateRow()} instead.
     *
     * {@inheritDoc}
     */
    protected function hydrateRowData(array $row, array &$result): void
    {
        // 1) Initialize
        $id                 = $this->rowIdTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        $rowData            = $this->gatherRowData($row, $id, $nonemptyComponents);

        // 2) Now hydrate the data found in the current row.
        foreach ($rowData['data'] as $dqlAlias => $data) {
            $index = false;

            if (isset($this->resultSetMapping()->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parent = $this->resultSetMapping()->parentAliasMap[$dqlAlias];
                $path   = $parent . '.' . $dqlAlias;

                // missing parent data, skipping as RIGHT JOIN hydration is not supported.
                if (! isset($nonemptyComponents[$parent])) {
                    continue;
                }

                // Get a reference to the right element in the result tree.
                // This element will get the associated element attached.
                if ($this->resultSetMapping()->isMixed && isset($this->legacyRootAliases[$parent])) {
                    $first = reset($this->legacyResultPointers);
                    // TODO: Exception if $key === null ?
                    $baseElement =& $this->legacyResultPointers[$parent][key($first)];
                } elseif (isset($this->legacyResultPointers[$parent])) {
                    $baseElement =& $this->legacyResultPointers[$parent];
                } else {
                    unset($this->legacyResultPointers[$dqlAlias]); // Ticket #1228

                    continue;
                }

                $relationAlias = $this->resultSetMapping()->relationMap[$dqlAlias];
                $parentClass   = $this->metadataCache[$this->resultSetMapping()->aliasMap[$parent]];
                $relation      = $parentClass->associationMappings[$relationAlias];

                // Check the type of the relation (many or single-valued)
                if (! $relation->isToOne()) {
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

                            if (isset($this->resultSetMapping()->indexByMap[$dqlAlias])) {
                                $baseElement[$relationAlias][$row[$this->resultSetMapping()->indexByMap[$dqlAlias]]] = $element;
                            } else {
                                $baseElement[$relationAlias][] = $element;
                            }

                            $this->identifierMap[$path][$id[$parent]][$id[$dqlAlias]] = array_key_last($baseElement[$relationAlias]);
                        }
                    }
                } else {
                    $oneToOne = true;

                    if (
                        ! isset($nonemptyComponents[$dqlAlias]) &&
                        ( ! isset($baseElement[$relationAlias]))
                    ) {
                        $baseElement[$relationAlias] = null;
                    } elseif (! isset($baseElement[$relationAlias])) {
                        $baseElement[$relationAlias] = $data;
                    }
                }

                $coll =& $baseElement[$relationAlias];

                if (is_array($coll)) {
                    $this->updateLegacyResultPointer($coll, $index, $dqlAlias, $oneToOne);
                }
            } else {
                // It's a root result element

                $this->legacyRootAliases[$dqlAlias] = true; // Mark as root
                $entityKey                          = $this->resultSetMapping()->entityMappings[$dqlAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (! isset($nonemptyComponents[$dqlAlias])) {
                    $result[] = $this->resultSetMapping()->isMixed
                        ? [$entityKey => null]
                        : null;

                    $resultKey = $this->resultCounter;
                    ++$this->resultCounter;

                    continue;
                }

                // Check for an existing element
                if ($this->isSimpleQuery || ! isset($this->identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                    $element = $this->resultSetMapping()->isMixed
                        ? [$entityKey => $data]
                        : $data;

                    if (isset($this->resultSetMapping()->indexByMap[$dqlAlias])) {
                        $resultKey          = $row[$this->resultSetMapping()->indexByMap[$dqlAlias]];
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

                $this->updateLegacyResultPointer($result, $index, $dqlAlias, false);
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                // this only ever happens when no object is fetched (scalar result only)
                $resultKey = isset($this->resultSetMapping()->indexByMap['scalars'])
                    ? $row[$this->resultSetMapping()->indexByMap['scalars']]
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

            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $args = $newObject['args'];
                $obj  = $newObject['obj'];

                if (count($args) === $scalarCount || ($scalarCount === 0 && count($rowData['newObjects']) === 1)) {
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
     * @param mixed[]|null     $coll     The element.
     * @param string|int|false $index    Index of the element in the collection.
     * @param bool             $oneToOne Whether it is a single-valued association or not.
     */
    private function updateLegacyResultPointer(
        array|null &$coll,
        string|int|false $index,
        string $dqlAlias,
        bool $oneToOne,
    ): void {
        if ($coll === null) {
            unset($this->legacyResultPointers[$dqlAlias]); // Ticket #1228

            return;
        }

        if ($oneToOne) {
            $this->legacyResultPointers[$dqlAlias] =& $coll;

            return;
        }

        if ($index !== false) {
            $this->legacyResultPointers[$dqlAlias] =& $coll[$index];

            return;
        }

        if (! $coll) {
            return;
        }

        $this->legacyResultPointers[$dqlAlias] =& $coll[array_key_last($coll)];
    }

    /**
     * Hydrates the entities of a row on their own, as used by {@see toIterable()}.
     *
     * A single row holds at most one element per DQL alias, and joined DQL aliases
     * come after their parent, so the elements are first collected in row order,
     * with an empty relation in their parent, and then attached to their parent in
     * reverse row order, which completes each element before it is attached.
     *
     * @param mixed[]                $row
     * @param array<string, mixed[]> $data
     * @param array<string, bool>    $nonemptyComponents
     *
     * @return array-key|null The result key of the last root element.
     */
    private function hydrateIteratedRowEntities(array $row, array $data, array $nonemptyComponents): int|string|null
    {
        $resultSetMapping = $this->resultSetMapping();
        $elements         = [];
        $joinedAliases    = [];
        $rootAliases      = [];

        foreach ($data as $dqlAlias => $elementData) {
            if (! isset($resultSetMapping->parentAliasMap[$dqlAlias])) {
                $rootAliases[] = $dqlAlias;

                if (isset($nonemptyComponents[$dqlAlias])) {
                    $elements[$dqlAlias] = $elementData;
                }

                continue;
            }

            $parent = $resultSetMapping->parentAliasMap[$dqlAlias];

            // missing parent data, skipping as RIGHT JOIN hydration is not supported.
            if (! isset($elements[$parent])) {
                continue;
            }

            $relationAlias = $resultSetMapping->relationMap[$dqlAlias];

            if (! isset($elements[$parent][$relationAlias])) {
                $elements[$parent][$relationAlias] = $this->metadataCache[$resultSetMapping->aliasMap[$parent]]->associationMappings[$relationAlias]->isToOne()
                    ? null
                    : [];
            }

            if (! isset($nonemptyComponents[$dqlAlias])) {
                continue;
            }

            $elements[$dqlAlias] = $elementData;
            $joinedAliases[]     = $dqlAlias;
        }

        for ($i = count($joinedAliases) - 1; $i >= 0; --$i) {
            $dqlAlias      = $joinedAliases[$i];
            $parent        = $resultSetMapping->parentAliasMap[$dqlAlias];
            $relationAlias = $resultSetMapping->relationMap[$dqlAlias];

            if (! is_array($elements[$parent][$relationAlias])) {
                $elements[$parent][$relationAlias] = $elements[$dqlAlias];
            } elseif (isset($resultSetMapping->indexByMap[$dqlAlias])) {
                $elements[$parent][$relationAlias][$row[$resultSetMapping->indexByMap[$dqlAlias]]] = $elements[$dqlAlias];
            } else {
                $elements[$parent][$relationAlias][] = $elements[$dqlAlias];
            }
        }

        $resultKey = null;

        foreach ($rootAliases as $dqlAlias) {
            $entityKey = $resultSetMapping->entityMappings[$dqlAlias] ?: 0;
            $element   = $elements[$dqlAlias] ?? null;

            $element = $resultSetMapping->isMixed ? [$entityKey => $element] : $element;

            if (isset($elements[$dqlAlias], $resultSetMapping->indexByMap[$dqlAlias])) {
                $resultKey                         = $row[$resultSetMapping->indexByMap[$dqlAlias]];
                $this->hydrationResult[$resultKey] = $element;
            } else {
                $this->hydrationResult[] = $element;
                $resultKey               = array_key_last($this->hydrationResult);
                ++$this->resultCounter;
            }
        }

        return $resultKey;
    }

    /**
     * Stores the data of an element that other DQL aliases are joined to and returns its node id.
     *
     * @param mixed[] $data
     */
    private function createNode(array $data, string $dqlAlias): int
    {
        $node                   = count($this->nodes);
        $this->nodes[]          = $data;
        $this->nodeDqlAliases[] = $dqlAlias;

        return $node;
    }

    /**
     * Replaces the node ids in the nodes and the result with the nested node data.
     *
     * Joined nodes are always created after the node they are joined to, so iterating
     * the nodes in reverse order completes each node before it is put into its parent.
     */
    private function materializeNodes(): void
    {
        $nodes          = $this->nodes;
        $this->nodes    = [];
        $nodeDqlAliases = $this->nodeDqlAliases;

        for ($node = count($nodeDqlAliases) - 1; $node >= 0; --$node) {
            if (! isset($this->nodeRelations[$nodeDqlAliases[$node]])) {
                continue;
            }

            foreach ($this->nodeRelations[$nodeDqlAliases[$node]] as $relationAlias => $true) {
                if (! isset($nodes[$node][$relationAlias])) {
                    continue;
                }

                if (! is_array($nodes[$node][$relationAlias])) {
                    $nodes[$node][$relationAlias] = $nodes[$nodes[$node][$relationAlias]];

                    continue;
                }

                $collection = [];

                foreach ($nodes[$node][$relationAlias] as $key => $child) {
                    $collection[$key] = $nodes[$child];
                }

                $nodes[$node][$relationAlias] = $collection;
            }
        }

        foreach ($this->rootNodes as [$resultKey, $entityKey, $node]) {
            if ($entityKey === null) {
                if (($this->hydrationResult[$resultKey] ?? null) === $node) {
                    $this->hydrationResult[$resultKey] = $nodes[$node];
                }
            } elseif (is_array($this->hydrationResult[$resultKey] ?? null) && ($this->hydrationResult[$resultKey][$entityKey] ?? null) === $node) {
                $this->hydrationResult[$resultKey][$entityKey] = $nodes[$node];
            }
        }

        $this->nodeDqlAliases =
        $this->rootNodes      = [];
    }
}
