<?php

declare(strict_types=1);

namespace Doctrine\ORM\Event;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs as BaseLifecycleEventArgs;

/**
 * Lifecycle Events are triggered by the UnitOfWork during lifecycle transitions
 * of entities.
 *
 * @link   www.doctrine-project.org
 */
class LifecycleEventArgs extends BaseLifecycleEventArgs
{
    /**
     * Retrieves associated Entity.
     *
     * @return object
     */
    public function getEntity()
    {
        return $this->getObject();
    }

    /**
     * Retrieves associated EntityManager.
     *
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return $this->getObjectManager();
    }
}
