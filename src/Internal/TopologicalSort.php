<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal;

use Doctrine\ORM\Internal\TopologicalSort\CycleDetectedException;

use function array_keys;
use function spl_object_id;

/**
 * TopologicalSort implements topological sorting, which is an ordering
 * algorithm for directed graphs (DG) using a depth-first searching (DFS)
 * to traverse the graph built in memory.
 * This algorithm has a linear running time based on nodes (V) and edges
 * between the nodes (E), resulting in a computational complexity of O(V + E).
 *
 * @internal
 */
final class TopologicalSort
{
    private const int NOT_VISITED = 1;
    private const int IN_PROGRESS = 2;
    private const int VISITED     = 3;

    /**
     * Array of all nodes, indexed by object ids.
     *
     * @var array<int, object>
     */
    private array $nodes = [];

    /**
     * DFS state for the different nodes, indexed by node object id and using one of
     * this class' constants as value.
     *
     * @var array<int, self::*>
     */
    private array $states = [];

    /**
     * Edges between the nodes. The first-level key is the object id of the outgoing
     * node; the second array maps the destination node by object id as key. The final
     * boolean value indicates whether the edge is optional or not.
     *
     * @var array<int, array<int, bool>>
     */
    private array $edges = [];

    /**
     * Builds up the result during the DFS.
     *
     * @var list<object>
     */
    private array $sortResult = [];

    public function addNode(object $node): void
    {
        $id                = spl_object_id($node);
        $this->nodes[$id]  = $node;
        $this->states[$id] = self::NOT_VISITED;
        $this->edges[$id]  = [];
    }

    public function hasNode(object $node): bool
    {
        return isset($this->nodes[spl_object_id($node)]);
    }

    /**
     * Adds a new edge between two nodes to the graph
     *
     * @param bool $optional This indicates whether the edge may be ignored during the topological sort if it is necessary to break cycles.
     */
    public function addEdge(object $from, object $to, bool $optional): void
    {
        $fromId = spl_object_id($from);
        $toId   = spl_object_id($to);

        if (isset($this->edges[$fromId][$toId]) && $this->edges[$fromId][$toId] === false) {
            return; // we already know about this dependency, and it is not optional
        }

        $this->edges[$fromId][$toId] = $optional;
    }

    /**
     * Returns a topological sort of all nodes. When we have an edge A->B between two nodes
     * A and B, then B will be listed before A in the result. Visually speaking, when ordering
     * the nodes in the result order from left to right, all edges point to the left.
     *
     * @return list<object>
     */
    public function sort(): array
    {
        foreach (array_keys($this->nodes) as $oid) {
            if ($this->states[$oid] === self::NOT_VISITED) {
                $this->visit($oid);
            }
        }

        return $this->sortResult;
    }

    private function visit(int $oid): void
    {
        if ($this->states[$oid] === self::IN_PROGRESS) {
            // This node is already on the current DFS stack. We've found a cycle!
            throw new CycleDetectedException($this->nodes[$oid]);
        }

        if ($this->states[$oid] === self::VISITED) {
            // We've reached a node that we've already seen, including all
            // other nodes that are reachable from here. We're done here, return.
            return;
        }

        $this->states[$oid] = self::IN_PROGRESS;

        // Continue the DFS downwards the edge list
        foreach ($this->edges[$oid] as $adjacentId => $optional) {
            try {
                $this->visit($adjacentId);
            } catch (CycleDetectedException $exception) {
                if ($exception->isCycleCollected()) {
                    // There is a complete cycle downstream of the current node. We cannot
                    // do anything about that anymore.
                    throw $exception;
                }

                if ($optional) {
                    // The current edge is part of a cycle, but it is optional and the closest
                    // such edge while backtracking. Break the cycle here by skipping the edge
                    // and continuing with the next one.
                    continue;
                }

                // We have found a cycle and cannot break it at $edge. Best we can do
                // is to backtrack from the current vertex, hoping that somewhere up the
                // stack this can be salvaged.
                $this->states[$oid] = self::NOT_VISITED;
                $exception->addToCycle($this->nodes[$oid]);

                throw $exception;
            }
        }

        // We have traversed all edges and visited all other nodes reachable from here.
        // So we're done with this vertex as well.

        $this->states[$oid] = self::VISITED;
        $this->sortResult[] = $this->nodes[$oid];
    }
}
