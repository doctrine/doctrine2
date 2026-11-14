<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Override;

use function assert;
use function is_array;
use function is_object;
use function reset;

/**
 * Default hydrator cache for entities
 */
class DefaultEntityHydrator implements EntityHydrator
{
    private readonly UnitOfWork $uow;
    private readonly IdentifierFlattener $identifierFlattener;

    /** @var array<string,mixed> */
    private static array $hints = [Query::HINT_CACHE_ENABLED => true];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->uow                 = $em->getUnitOfWork();
        $this->identifierFlattener = new IdentifierFlattener($em->getUnitOfWork(), $em->getMetadataFactory());
    }

    #[Override]
    public function buildCacheEntry(ClassMetadata $metadata, EntityCacheKey $key, object $entity): EntityCacheEntry
    {
        $data = $this->uow->getOriginalEntityData($entity);
        $data = [...$data, ...$metadata->getIdentifierValues($entity)]; // why update has no identifier values ?

        if ($metadata->requiresFetchAfterChange) {
            if ($metadata->isVersioned) {
                assert($metadata->versionField !== null);
                $data[$metadata->versionField] = $metadata->getFieldValue($entity, $metadata->versionField);
            }

            foreach ($metadata->fieldMappings as $name => $fieldMapping) {
                if (isset($fieldMapping->generated)) {
                    $data[$name] = $metadata->getFieldValue($entity, $name);
                }
            }
        }

        foreach ($metadata->associationMappings as $name => $assoc) {
            if (! isset($data[$name])) {
                continue;
            }

            if (! $assoc->isToOne()) {
                unset($data[$name]);

                continue;
            }

            if (! isset($assoc->cache)) {
                $targetClassMetadata = $this->em->getClassMetadata($assoc->targetEntity);
                $owningAssociation   = $this->em->getMetadataFactory()->getOwningSide($assoc);
                $associationIds      = $this->identifierFlattener->flattenIdentifier(
                    $targetClassMetadata,
                    $targetClassMetadata->getIdentifierValues($data[$name]),
                );

                unset($data[$name]);

                foreach ($associationIds as $fieldName => $fieldValue) {
                    if (isset($targetClassMetadata->fieldMappings[$fieldName])) {
                        assert($owningAssociation->isToOneOwningSide());
                        $fieldMapping = $targetClassMetadata->fieldMappings[$fieldName];

                        $data[$owningAssociation->targetToSourceKeyColumns[$fieldMapping->columnName]] = $fieldValue;

                        continue;
                    }

                    $targetAssoc = $targetClassMetadata->associationMappings[$fieldName];

                    assert($assoc->isToOneOwningSide());
                    foreach ($assoc->targetToSourceKeyColumns as $referencedColumn => $localColumn) {
                        if (isset($targetAssoc->sourceToTargetKeyColumns[$referencedColumn])) {
                            $data[$localColumn] = $fieldValue;
                        }
                    }
                }

                continue;
            }

            if (! isset($assoc->id)) {
                $targetClass = $data[$name]::class;
                $targetId    = $this->uow->getEntityIdentifier($data[$name]);
                $data[$name] = new AssociationCacheEntry($targetClass, $targetId);

                continue;
            }

            // handle association identifier
            $targetId = is_object($data[$name]) && $this->uow->isInIdentityMap($data[$name])
                ? $this->uow->getEntityIdentifier($data[$name])
                : $data[$name];

            // @TODO - fix it !
            // handle UnitOfWork#createEntity hash generation
            if (! is_array($targetId)) {
                assert($assoc->isToOneOwningSide());
                $data[reset($assoc->joinColumnFieldNames)] = $targetId;

                $targetEntity = $this->em->getClassMetadata($assoc->targetEntity);
                $targetId     = [$targetEntity->identifier[0] => $targetId];
            }

            $data[$name] = new AssociationCacheEntry($assoc->targetEntity, $targetId);
        }

        return new EntityCacheEntry($metadata->name, $data);
    }

    #[Override]
    public function loadCacheEntry(ClassMetadata $metadata, EntityCacheKey $key, EntityCacheEntry $entry, object|null $entity = null): object|null
    {
        $data  = $entry->data;
        $hints = self::$hints;

        if ($entity !== null) {
            $hints[Query::HINT_REFRESH]        = true;
            $hints[Query::HINT_REFRESH_ENTITY] = $entity;
        }

        foreach ($metadata->associationMappings as $name => $assoc) {
            if (! isset($assoc->cache) || ! isset($data[$name])) {
                continue;
            }

            $assocClass  = $data[$name]->class;
            $assocId     = $data[$name]->identifier;
            $isEagerLoad = ($assoc->fetch === ClassMetadata::FETCH_EAGER || ($assoc->isOneToOne() && ! $assoc->isOwningSide()));

            if (! $isEagerLoad) {
                $data[$name] = $this->em->getReference($assocClass, $assocId);

                continue;
            }

            $assocMetadata  = $this->em->getClassMetadata($assoc->targetEntity);
            $assocKey       = new EntityCacheKey($assocMetadata->rootEntityName, $assocId);
            $assocPersister = $this->uow->getEntityPersister($assoc->targetEntity);
            $assocRegion    = $assocPersister->getCacheRegion();
            $assocEntry     = $assocRegion->get($assocKey);

            if ($assocEntry === null) {
                return null;
            }

            $data[$name] = $this->uow->createEntity($assocEntry->class, $assocEntry->resolveAssociationEntries($this->em), $hints);
        }

        if ($entity !== null) {
            $this->uow->registerManaged($entity, $key->identifier, $data);
        }

        $result = $this->uow->createEntity($entry->class, $data, $hints);

        $this->uow->hydrationComplete();

        return $result;
    }
}
