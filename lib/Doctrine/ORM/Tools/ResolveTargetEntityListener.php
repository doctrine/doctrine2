<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use function array_key_exists;
use function ltrim;

/**
 * ResolveTargetEntityListener
 *
 * Mechanism to overwrite interfaces or classes specified as association
 * targets.
 */
class ResolveTargetEntityListener implements EventSubscriber
{
    /** @var string[] indexed by original entity name */
    private $resolveTargetEntities = [];

    /**
     * {@inheritDoc}
     */
    public function getSubscribedEvents()
    {
        return [
            Events::loadClassMetadata,
            Events::onClassMetadataNotFound,
        ];
    }

    /**
     * Adds a target-entity class name to resolve to a new class name.
     *
     * @param string $originalEntity
     * @param string $newEntity
     *
     */
    public function addResolveTargetEntity($originalEntity, $newEntity)
    {
        $this->resolveTargetEntities[ltrim($originalEntity, '\\')] = ltrim($newEntity, '\\');
    }

}
