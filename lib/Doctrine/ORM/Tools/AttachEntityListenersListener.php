<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

use function ltrim;

/**
 * Mechanism to programmatically attach entity listeners.
 */
class AttachEntityListenersListener
{
    /** @var mixed[][] */
    private $entityListeners = [];

    /**
     * Adds a entity listener for a specific entity.
     *
     * @param string      $entityClass      The entity to attach the listener.
     * @param string      $listenerClass    The listener class.
     * @param string      $eventName        The entity lifecycle event.
     * @param string|null $listenerCallback The listener callback method or NULL to use $eventName.
     *
     * @return void
     */
    public function addEntityListener($entityClass, $listenerClass, $eventName, $listenerCallback = null)
    {
        $this->entityListeners[ltrim($entityClass, '\\')][] = [
            'event'  => $eventName,
            'class'  => $listenerClass,
            'method' => $listenerCallback ?: $eventName,
        ];
    }

    /**
     * Processes event and attach the entity listener.
     *
     * @return void
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event)
    {
        $metadata = $event->getClassMetadata();

        if (! isset($this->entityListeners[$metadata->name])) {
            return;
        }

        foreach ($this->entityListeners[$metadata->name] as $listener) {
            $metadata->addEntityListener($listener['event'], $listener['class'], $listener['method']);
        }

        unset($this->entityListeners[$metadata->name]);
    }
}
