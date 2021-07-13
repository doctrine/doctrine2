<?php

declare(strict_types=1);

namespace Doctrine\ORM\Id;

use Doctrine\ORM\EntityManager;
use Serializable;

use function serialize;
use function unserialize;

/**
 * Represents an ID generator that uses a database sequence.
 */
class SequenceGenerator extends AbstractIdGenerator implements Serializable
{
    /**
     * The allocation size of the sequence.
     *
     * @var int
     */
    private $_allocationSize;

    /**
     * The name of the sequence.
     *
     * @var string
     */
    private $_sequenceName;

    /** @var int */
    private $_nextValue = 0;

    /** @var int|null */
    private $_maxValue = null;

    /**
     * Initializes a new sequence generator.
     *
     * @param string $sequenceName   The name of the sequence.
     * @param int    $allocationSize The allocation size of the sequence.
     */
    public function __construct($sequenceName, $allocationSize)
    {
        $this->_sequenceName   = $sequenceName;
        $this->_allocationSize = $allocationSize;
    }

    /**
     * {@inheritDoc}
     */
    public function generate(EntityManager $em, $entity)
    {
        if ($this->_maxValue === null || $this->_nextValue === $this->_maxValue) {
            // Allocate new values
            $conn = $em->getConnection();
            $sql  = $conn->getDatabasePlatform()->getSequenceNextValSQL($this->_sequenceName);

            // Using `query` to force usage of the master server in MasterSlaveConnection
            $this->_nextValue = (int) $conn->executeQuery($sql)->fetchOne();
            $this->_maxValue  = $this->_nextValue + $this->_allocationSize;
        }

        return $this->_nextValue++;
    }

    /**
     * Gets the maximum value of the currently allocated bag of values.
     *
     * @return int|null
     */
    public function getCurrentMaxValue()
    {
        return $this->_maxValue;
    }

    /**
     * Gets the next value that will be returned by generate().
     *
     * @return int
     */
    public function getNextValue()
    {
        return $this->_nextValue;
    }

    /**
     * @return string
     */
    public function serialize()
    {
        return serialize(
            [
                'allocationSize' => $this->_allocationSize,
                'sequenceName'   => $this->_sequenceName,
            ]
        );
    }

    /**
     * @param string $serialized
     *
     * @return void
     */
    public function unserialize($serialized)
    {
        $array = unserialize($serialized);

        $this->_sequenceName   = $array['sequenceName'];
        $this->_allocationSize = $array['allocationSize'];
    }
}
