<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\ListenersInvoker;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Class, which can handle completion of hydration cycle and produce some of tasks.
 * In current implementation triggers deferred postLoad event.
 */
final class HydrationCompleteHandler
{
    /** @var ListenersInvoker */
    private $listenersInvoker;

    /** @var EntityManagerInterface */
    private $em;

    /** @var mixed[][] */
    private $deferredPostLoadInvocations = [];

    /**
     * Constructor for this object
     */
    public function __construct(ListenersInvoker $listenersInvoker, EntityManagerInterface $em)
    {
        $this->listenersInvoker = $listenersInvoker;
        $this->em               = $em;
    }

    /**
     * Method schedules invoking of postLoad entity to the very end of current hydration cycle.
     *
     * @param object $entity
     */
    public function deferPostLoadInvoking(ClassMetadata $class, $entity): void
    {
        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::postLoad);

        if ($invoke === ListenersInvoker::INVOKE_NONE) {
            return;
        }

        $this->deferredPostLoadInvocations[] = [$class, $invoke, $entity];
    }

    /**
     * This method should me called after any hydration cycle completed.
     *
     * Method fires all deferred invocations of postLoad events
     */
    public function hydrationComplete(): void
    {
        $toInvoke                          = $this->deferredPostLoadInvocations;
        $this->deferredPostLoadInvocations = [];

        foreach ($toInvoke as $classAndEntity) {
            [$class, $invoke, $entity] = $classAndEntity;

            $this->listenersInvoker->invoke(
                $class,
                Events::postLoad,
                $entity,
                new LifecycleEventArgs($entity, $this->em),
                $invoke
            );
        }
    }
}
