<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Override;

use function array_key_exists;
use function array_replace_recursive;
use function ltrim;

/**
 * ResolveTargetEntityListener
 *
 * Mechanism to overwrite interfaces or classes specified as association
 * targets.
 */
class ResolveTargetEntityListener implements EventSubscriber
{
    /** @var mixed[][] indexed by original entity name */
    private array $resolveTargetEntities = [];

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getSubscribedEvents(): array
    {
        return [
            Events::loadClassMetadata,
            Events::onClassMetadataNotFound,
        ];
    }

    /**
     * Adds a target-entity class name to resolve to a new class name.
     *
     * @phpstan-param array<string, mixed> $mapping
     */
    public function addResolveTargetEntity(string $originalEntity, string $newEntity, array $mapping): void
    {
        $mapping['targetEntity']                                   = ltrim($newEntity, '\\');
        $this->resolveTargetEntities[ltrim($originalEntity, '\\')] = $mapping;
    }

    /** @internal this is an event callback, and should not be called directly */
    public function onClassMetadataNotFound(OnClassMetadataNotFoundEventArgs $args): void
    {
        if (array_key_exists($args->getClassName(), $this->resolveTargetEntities)) {
            $args->setFoundMetadata(
                $args
                    ->getObjectManager()
                    ->getClassMetadata($this->resolveTargetEntities[$args->getClassName()]['targetEntity']),
            );
        }
    }

    /**
     * Processes event and resolves new target entity names.
     *
     * @internal this is an event callback, and should not be called directly
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $cm = $args->getClassMetadata();

        foreach ($cm->associationMappings as $mapping) {
            if (isset($this->resolveTargetEntities[$mapping->targetEntity])) {
                $this->remapAssociation($cm, $mapping);
            }
        }

        foreach ($cm->discriminatorMap as $value => $class) {
            if (isset($this->resolveTargetEntities[$class])) {
                $cm->addDiscriminatorMapClass($value, $this->resolveTargetEntities[$class]['targetEntity']);
            }
        }
    }

    private function remapAssociation(ClassMetadata $classMetadata, AssociationMapping $mapping): void
    {
        $newMapping              = $this->resolveTargetEntities[$mapping->targetEntity];
        $newMapping              = array_replace_recursive(
            $mapping->toArray(),
            $newMapping,
        );
        $newMapping['fieldName'] = $mapping->fieldName;

        unset($classMetadata->associationMappings[$mapping->fieldName]);

        switch ($mapping->type()) {
            case ClassMetadata::MANY_TO_MANY:
                $classMetadata->mapManyToMany($newMapping);
                break;
            case ClassMetadata::MANY_TO_ONE:
                $classMetadata->mapManyToOne($newMapping);
                break;
            case ClassMetadata::ONE_TO_MANY:
                $classMetadata->mapOneToMany($newMapping);
                break;
            case ClassMetadata::ONE_TO_ONE:
                $classMetadata->mapOneToOne($newMapping);
                break;
        }
    }
}
