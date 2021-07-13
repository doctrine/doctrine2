<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

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
     * @psalm-param array<string, mixed> $mapping
     *
     * @return void
     */
    public function addResolveTargetEntity($originalEntity, $newEntity, array $mapping)
    {
        $mapping['targetEntity']                                   = ltrim($newEntity, '\\');
        $this->resolveTargetEntities[ltrim($originalEntity, '\\')] = $mapping;
    }

    /**
     * @internal this is an event callback, and should not be called directly
     *
     * @return void
     */
    public function onClassMetadataNotFound(OnClassMetadataNotFoundEventArgs $args)
    {
        if (array_key_exists($args->getClassName(), $this->resolveTargetEntities)) {
            $args->setFoundMetadata(
                $args
                    ->getObjectManager()
                    ->getClassMetadata($this->resolveTargetEntities[$args->getClassName()]['targetEntity'])
            );
        }
    }

    /**
     * Processes event and resolves new target entity names.
     *
     * @internal this is an event callback, and should not be called directly
     *
     * @return void
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args)
    {
        $cm = $args->getClassMetadata();

        foreach ($cm->associationMappings as $mapping) {
            if (isset($this->resolveTargetEntities[$mapping['targetEntity']])) {
                $this->remapAssociation($cm, $mapping);
            }
        }

        foreach ($this->resolveTargetEntities as $interface => $data) {
            if ($data['targetEntity'] === $cm->getName()) {
                $args->getEntityManager()->getMetadataFactory()->setMetadataFor($interface, $cm);
            }
        }

        foreach ($cm->discriminatorMap as $value => $class) {
            if (isset($this->resolveTargetEntities[$class])) {
                $cm->addDiscriminatorMapClass($value, $this->resolveTargetEntities[$class]['targetEntity']);
            }
        }
    }

    /**
     * @param mixed[] $mapping
     */
    private function remapAssociation(ClassMetadata $classMetadata, array $mapping): void
    {
        $newMapping              = $this->resolveTargetEntities[$mapping['targetEntity']];
        $newMapping              = array_replace_recursive($mapping, $newMapping);
        $newMapping['fieldName'] = $mapping['fieldName'];

        unset($classMetadata->associationMappings[$mapping['fieldName']]);

        switch ($mapping['type']) {
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
