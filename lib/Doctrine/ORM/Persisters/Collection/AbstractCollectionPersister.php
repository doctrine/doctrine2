<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Collection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\UnitOfWork;

/**
 * Base class for all collection persisters.
 */
abstract class AbstractCollectionPersister implements CollectionPersister
{
    /** @var EntityManagerInterface */
    protected $em;

    /** @var Connection */
    protected $conn;

    /** @var UnitOfWork */
    protected $uow;

    /**
     * The database platform.
     *
     * @var AbstractPlatform
     */
    protected $platform;

    /** @var QuoteStrategy */
    protected $quoteStrategy;

    /**
     * Initializes a new instance of a class derived from AbstractCollectionPersister.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em            = $em;
        $this->uow           = $em->getUnitOfWork();
        $this->conn          = $em->getConnection();
        $this->platform      = $this->conn->getDatabasePlatform();
        $this->quoteStrategy = $em->getConfiguration()->getQuoteStrategy();
    }

    /**
     * Check if entity is in a valid state for operations.
     *
     * @param object $entity
     *
     * @return bool
     */
    protected function isValidEntityState($entity)
    {
        $entityState = $this->uow->getEntityState($entity, UnitOfWork::STATE_NEW);

        if ($entityState === UnitOfWork::STATE_NEW) {
            return false;
        }

        // If Entity is scheduled for inclusion, it is not in this collection.
        // We can assure that because it would have return true before on array check
        return ! ($entityState === UnitOfWork::STATE_MANAGED && $this->uow->isScheduledForInsert($entity));
    }
}
