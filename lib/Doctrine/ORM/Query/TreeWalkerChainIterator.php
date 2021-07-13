<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use ArrayAccess;
use Doctrine\ORM\AbstractQuery;
use Iterator;

use function key;
use function next;
use function reset;

/**
 * @template-implements Iterator<TreeWalker>
 * @template-implements ArrayAccess<int, TreeWalker>
 */
class TreeWalkerChainIterator implements Iterator, ArrayAccess
{
    /** @var class-string<TreeWalker>[] */
    private $walkers = [];
    /** @var TreeWalkerChain */
    private $treeWalkerChain;
    /** @var AbstractQuery */
    private $query;
    /** @var ParserResult */
    private $parserResult;

    /**
     * @param AbstractQuery $query
     * @param ParserResult  $parserResult
     */
    public function __construct(TreeWalkerChain $treeWalkerChain, $query, $parserResult)
    {
        $this->treeWalkerChain = $treeWalkerChain;
        $this->query           = $query;
        $this->parserResult    = $parserResult;
    }

    /**
     * @return string|false
     * @psalm-return class-string<TreeWalker>|false
     */
    public function rewind()
    {
        return reset($this->walkers);
    }

    /**
     * @return TreeWalker|null
     */
    public function current()
    {
        return $this->offsetGet(key($this->walkers));
    }

    /**
     * @return int
     */
    public function key()
    {
        return key($this->walkers);
    }

    /**
     * @return TreeWalker|null
     */
    public function next()
    {
        next($this->walkers);

        return $this->offsetGet(key($this->walkers));
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return key($this->walkers) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset)
    {
        return isset($this->walkers[$offset]);
    }

    /**
     * @param mixed $offset
     * @psalm-param array-key|null $offset
     *
     * @return TreeWalker|null
     */
    public function offsetGet($offset)
    {
        if ($this->offsetExists($offset)) {
            return new $this->walkers[$offset](
                $this->query,
                $this->parserResult,
                $this->treeWalkerChain->getQueryComponents()
            );
        }

        return null;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $value
     * @psalm-param array-key|null $offset
     */
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->walkers[] = $value;
        } else {
            $this->walkers[$offset] = $value;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        if ($this->offsetExists($offset)) {
            unset($this->walkers[$offset]);
        }
    }
}
