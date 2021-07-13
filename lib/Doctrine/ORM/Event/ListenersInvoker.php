<?php

declare(strict_types=1);

namespace Doctrine\ORM\Event;

use Doctrine\Common\EventArgs;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\EntityListenerResolver;

/**
 * A method invoker based on entity lifecycle.
 */
class ListenersInvoker
{
    public const INVOKE_NONE      = 0;
    public const INVOKE_LISTENERS = 1;
    public const INVOKE_CALLBACKS = 2;
    public const INVOKE_MANAGER   = 4;

    /** @var EntityListenerResolver The Entity listener resolver. */
    private $resolver;

    /**
     * The EventManager used for dispatching events.
     *
     * @var EventManager
     */
    private $eventManager;

    /**
     * Initializes a new ListenersInvoker instance.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->eventManager = $em->getEventManager();
        $this->resolver     = $em->getConfiguration()->getEntityListenerResolver();
    }

    /**
     * Get the subscribed event systems
     *
     * @param ClassMetadata $metadata  The entity metadata.
     * @param string        $eventName The entity lifecycle event.
     *
     * @return int Bitmask of subscribed event systems.
     */
    public function getSubscribedSystems(ClassMetadata $metadata, $eventName)
    {
        $invoke = self::INVOKE_NONE;

        if (isset($metadata->lifecycleCallbacks[$eventName])) {
            $invoke |= self::INVOKE_CALLBACKS;
        }

        if (isset($metadata->entityListeners[$eventName])) {
            $invoke |= self::INVOKE_LISTENERS;
        }

        if ($this->eventManager->hasListeners($eventName)) {
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
     * @param int           $invoke    Bitmask to invoke listeners.
     *
     * @return void
     */
    public function invoke(ClassMetadata $metadata, $eventName, $entity, EventArgs $event, $invoke)
    {
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
            $this->eventManager->dispatchEvent($eventName, $event);
        }
    }
}
