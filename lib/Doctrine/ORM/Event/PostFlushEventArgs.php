<?php

declare(strict_types=1);

namespace Doctrine\ORM\Event;

use Doctrine\Common\EventArgs;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provides event arguments for the postFlush event.
 *
 * @link        www.doctrine-project.org
 */
class PostFlushEventArgs extends EventArgs
{
    /** @var EntityManager */
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Retrieves associated EntityManager.
     *
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }
}
