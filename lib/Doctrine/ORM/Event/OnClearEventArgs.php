<?php

declare(strict_types=1);

namespace Doctrine\ORM\Event;

use Doctrine\Common\EventArgs;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provides event arguments for the onClear event.
 *
 * @link        www.doctrine-project.org
 */
class OnClearEventArgs extends EventArgs
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var string|null */
    private $entityClass;

    /**
     * @param string|null $entityClass Optional entity class.
     */
    public function __construct(EntityManagerInterface $em, $entityClass = null)
    {
        $this->em          = $em;
        $this->entityClass = $entityClass;
    }

    /**
     * Retrieves associated EntityManager.
     *
     * @return EntityManagerInterface
     */
    public function getEntityManager()
    {
        return $this->em;
    }

    /**
     * Name of the entity class that is cleared, or empty if all are cleared.
     *
     * @return string|null
     */
    public function getEntityClass()
    {
        return $this->entityClass;
    }

    /**
     * Checks if event clears all entities.
     *
     * @return bool
     */
    public function clearsAllEntities()
    {
        return $this->entityClass === null;
    }
}
