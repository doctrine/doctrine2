<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\ListenersInvoker;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Class, which can handle completion of hydration cycle and produce some of tasks.
 * In current implementation triggers deferred postLoad event.
 */
final class HydrationCompleteHandler
{
    /** @var mixed[][] */
    private array $deferredPostLoadInvocations = [];

    private int $hydrationDepth = 0;

    public function __construct(
        private readonly ListenersInvoker $listenersInvoker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Method schedules invoking of postLoad entity to the very end of current hydration cycle.
     */
    public function deferPostLoadInvoking(ClassMetadata $class, object $entity): void
    {
        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::postLoad);

        if ($invoke === ListenersInvoker::INVOKE_NONE) {
            return;
        }

        $this->deferredPostLoadInvocations[] = [$class, $invoke, $entity];
    }

    /**
     * Marks the start of a section in which entities are being hydrated. Sections nest,
     * for example when loading an association starts a new hydration during an outer one.
     *
     * Each call has to be paired with {@see leaveHydration()}, also when an exception is
     * thrown. A section must not stay open while control is handed back to user code, so
     * row-by-row hydration opens one per row instead of one for the whole iteration.
     */
    public function enterHydration(): void
    {
        $this->hydrationDepth++;
    }

    /**
     * Marks the end of a section started with {@see enterHydration()}. This does not fire
     * postLoad events, {@see hydrationComplete()} has to be called afterwards for that.
     */
    public function leaveHydration(): void
    {
        $this->hydrationDepth--;
    }

    /**
     * This method should be called after any hydration cycle completed.
     *
     * Method fires all deferred invocations of postLoad events, unless a hydration
     * is still in progress. The outermost hydration then fires them when it completes.
     */
    public function hydrationComplete(): void
    {
        if ($this->hydrationDepth > 0) {
            return;
        }

        $toInvoke                          = $this->deferredPostLoadInvocations;
        $this->deferredPostLoadInvocations = [];

        foreach ($toInvoke as $classAndEntity) {
            [$class, $invoke, $entity] = $classAndEntity;

            $this->listenersInvoker->invoke(
                $class,
                Events::postLoad,
                $entity,
                new PostLoadEventArgs($entity, $this->em),
                $invoke,
            );
        }
    }
}
