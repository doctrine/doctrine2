<?php

declare(strict_types=1);

namespace Doctrine\ORM\Event;

use Doctrine\Common\EventArgs;
use Doctrine\Common\EventDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\EntityListenerResolver;

/**
 * A method invoker based on entity lifecycle.
 */
class ListenersInvoker
{
    final public const int INVOKE_NONE      = 0;
    final public const int INVOKE_LISTENERS = 1;
    final public const int INVOKE_CALLBACKS = 2;
    final public const int INVOKE_MANAGER   = 4;

    /** The Entity listener resolver. */
    private readonly EntityListenerResolver $resolver;

    /** The EventDispatcher used for dispatching events. */
    private readonly EventDispatcher $eventDispatcher;

    public function __construct(EntityManagerInterface $em)
    {
        $this->eventDispatcher = $em->getEventManager();
        $this->resolver        = $em->getConfiguration()->getEntityListenerResolver();
    }

    /**
     * Get the subscribed event systems
     *
     * @param ClassMetadata $metadata  The entity metadata.
     * @param string        $eventName The entity lifecycle event.
     *
     * @phpstan-return int-mask-of<self::INVOKE_*> Bitmask of subscribed event systems.
     */
    public function getSubscribedSystems(ClassMetadata $metadata, string $eventName): int
    {
        $invoke = self::INVOKE_NONE;

        if (isset($metadata->lifecycleCallbacks[$eventName])) {
            $invoke |= self::INVOKE_CALLBACKS;
        }

        if (isset($metadata->entityListeners[$eventName])) {
            $invoke |= self::INVOKE_LISTENERS;
        }

        if ($this->eventDispatcher->hasListeners($eventName)) {
            $invoke |= self::INVOKE_MANAGER;
        }

        return $invoke;
    }

    /**
     * Dispatches the lifecycle event of the given entity.
     *
     * @param ClassMetadata $metadata  The entity metadata.
     * @param string        $eventName The entity lifecycle event.
     * @param object        $entity    The Entity on which the event occurred.
     * @param EventArgs     $event     The Event args.
     * @phpstan-param int-mask-of<self::INVOKE_*> $invoke Bitmask to invoke listeners.
     */
    public function invoke(
        ClassMetadata $metadata,
        string $eventName,
        object $entity,
        EventArgs $event,
        int $invoke,
    ): void {
        if ($invoke & self::INVOKE_CALLBACKS) {
            foreach ($metadata->lifecycleCallbacks[$eventName] as $callback) {
                $entity->$callback($event);
            }
        }

        if ($invoke & self::INVOKE_LISTENERS) {
            foreach ($metadata->entityListeners[$eventName] as $listener) {
                $class    = $listener['class'];
                $method   = $listener['method'];
                $instance = $this->resolver->resolve($class);

                $instance->$method($entity, $event);
            }
        }

        if ($invoke & self::INVOKE_MANAGER) {
            $this->eventDispatcher->dispatchEvent($eventName, $event);
        }
    }
}
