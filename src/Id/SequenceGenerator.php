<?php

declare(strict_types=1);

namespace Doctrine\ORM\Id;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Represents an ID generator that uses a database sequence.
 */
class SequenceGenerator extends AbstractIdGenerator
{
    private int $nextValue     = 0;
    private int|null $maxValue = null;

    /**
     * Initializes a new sequence generator.
     *
     * @param string $sequenceName   The name of the sequence.
     * @param int    $allocationSize The allocation size of the sequence.
     */
    public function __construct(
        private string $sequenceName,
        private int $allocationSize,
    ) {
    }

    #[Override]
    public function generateId(EntityManagerInterface $em, object|null $entity): int
    {
        if ($this->maxValue === null || $this->nextValue === $this->maxValue) {
            // Allocate new values
            $connection = $em->getConnection();
            $sql        = $connection->getDatabasePlatform()->getSequenceNextValSQL($this->sequenceName);

            if ($connection instanceof PrimaryReadReplicaConnection) {
                $connection->ensureConnectedToPrimary();
            }

            $this->nextValue = (int) $connection->fetchOne($sql);
            $this->maxValue  = $this->nextValue + $this->allocationSize;
        }

        return $this->nextValue++;
    }

    /**
     * Gets the maximum value of the currently allocated bag of values.
     */
    public function getCurrentMaxValue(): int|null
    {
        return $this->maxValue;
    }

    /**
     * Gets the next value that will be returned by generate().
     */
    public function getNextValue(): int
    {
        return $this->nextValue;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [
            'allocationSize' => $this->allocationSize,
            'sequenceName' => $this->sequenceName,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->sequenceName   = $data['sequenceName'];
        $this->allocationSize = $data['allocationSize'];
    }
}
