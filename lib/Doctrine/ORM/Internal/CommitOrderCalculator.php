<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal;

use stdClass;

use function array_reverse;

/**
 * CommitOrderCalculator implements topological sorting, which is an ordering
 * algorithm for directed graphs (DG) and/or directed acyclic graphs (DAG) by
 * using a depth-first searching (DFS) to traverse the graph built in memory.
 * This algorithm have a linear running time based on nodes (V) and dependency
 * between the nodes (E), resulting in a computational complexity of O(V + E).
 */
class CommitOrderCalculator
{
    public const NOT_VISITED = 0;
    public const IN_PROGRESS = 1;
    public const VISITED     = 2;

    /**
     * Matrix of nodes (aka. vertex).
     * Keys are provided hashes and values are the node definition objects.
     *
     * The node state definition contains the following properties:
     *
     * - <b>state</b> (integer)
     * Whether the node is NOT_VISITED or IN_PROGRESS
     *
     * - <b>value</b> (object)
     * Actual node value
     *
     * - <b>dependencyList</b> (array<string>)
     * Map of node dependencies defined as hashes.
     *
     * @var array<stdClass>
     */
    private $nodeList = [];

    /**
     * Volatile variable holding calculated nodes during sorting process.
     *
     * @psalm-var list<object>
     */
    private $sortedNodeList = [];

    /**
     * Checks for node (vertex) existence in graph.
     *
     * @param string $hash
     *
     * @return bool
     */
    public function hasNode($hash)
    {
        return isset($this->nodeList[$hash]);
    }

    /**
     * Adds a new node (vertex) to the graph, assigning its hash and value.
     *
     * @param string $hash
     * @param object $node
     *
     * @return void
     */
    public function addNode($hash, $node)
    {
        $vertex = new stdClass();

        $vertex->hash           = $hash;
        $vertex->state          = self::NOT_VISITED;
        $vertex->value          = $node;
        $vertex->dependencyList = [];

        $this->nodeList[$hash] = $vertex;
    }

    /**
     * Adds a new dependency (edge) to the graph using their hashes.
     *
     * @param string $fromHash
     * @param string $toHash
     * @param int    $weight
     *
     * @return void
     */
    public function addDependency($fromHash, $toHash, $weight)
    {
        $vertex = $this->nodeList[$fromHash];
        $edge   = new stdClass();

        $edge->from   = $fromHash;
        $edge->to     = $toHash;
        $edge->weight = $weight;

        $vertex->dependencyList[$toHash] = $edge;
    }

    /**
     * Return a valid order list of all current nodes.
     * The desired topological sorting is the reverse post order of these searches.
     *
     * {@internal Highly performance-sensitive method.}
     *
     * @psalm-return list<object>
     */
    public function sort()
    {
        foreach ($this->nodeList as $vertex) {
            if ($vertex->state !== self::NOT_VISITED) {
                continue;
            }

            $this->visit($vertex);
        }

        $sortedList = $this->sortedNodeList;

        $this->nodeList       = [];
        $this->sortedNodeList = [];

        return array_reverse($sortedList);
    }

    /**
     * Visit a given node definition for reordering.
     *
     * {@internal Highly performance-sensitive method.}
     */
    private function visit(stdClass $vertex): void
    {
        $vertex->state = self::IN_PROGRESS;

        foreach ($vertex->dependencyList as $edge) {
            $adjacentVertex = $this->nodeList[$edge->to];

            switch ($adjacentVertex->state) {
                case self::VISITED:
                    // Do nothing, since node was already visited
                    break;

                case self::IN_PROGRESS:
                    if (
                        isset($adjacentVertex->dependencyList[$vertex->hash]) &&
                        $adjacentVertex->dependencyList[$vertex->hash]->weight < $edge->weight
                    ) {
                        // If we have some non-visited dependencies in the in-progress dependency, we
                        // need to visit them before adding the node.
                        foreach ($adjacentVertex->dependencyList as $adjacentEdge) {
                            $adjacentEdgeVertex = $this->nodeList[$adjacentEdge->to];

                            if ($adjacentEdgeVertex->state === self::NOT_VISITED) {
                                $this->visit($adjacentEdgeVertex);
                            }
                        }

                        $adjacentVertex->state = self::VISITED;

                        $this->sortedNodeList[] = $adjacentVertex->value;
                    }

                    break;

                case self::NOT_VISITED:
                    $this->visit($adjacentVertex);
            }
        }

        if ($vertex->state !== self::VISITED) {
            $vertex->state = self::VISITED;

            $this->sortedNodeList[] = $vertex->value;
        }
    }
}
