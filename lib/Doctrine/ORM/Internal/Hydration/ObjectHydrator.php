<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\FetchMode;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use ProxyManager\Proxy\GhostObjectInterface;
use function array_fill_keys;
use function array_keys;
use function count;
use function key;
use function spl_object_id;

/**
 * The ObjectHydrator constructs an object graph out of an SQL result set.
 *
 * {@internal Highly performance-sensitive code. }}
 */
class ObjectHydrator extends AbstractHydrator
{
    /**
     * @var mixed[][]
     */
    private $identifierMap = [];

    /**
     * @var mixed[]
     */
    private $resultPointers = [];

    /**
     * @var string[]
     */
    private $idTemplate = [];

    /**
     * @var int
     */
    private $resultCounter = 0;

    /**
     * @var bool
     */
    private $rootAliases = [];

    /**
     * @var Collection[]|object[][]
     */
    private $initializedCollections = [];

    /**
     * @var Collection[]|object[][]
     */
    private $existingCollections = [];

    /**
     * {@inheritdoc}
     */
    protected function prepare()
    {
        if (! isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD])) {
            $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] = true;
        }

        foreach ($this->rsm->aliasMap as $dqlAlias => $className) {
            $this->identifierMap[$dqlAlias] = [];
            $this->idTemplate[$dqlAlias]    = '';

            // Remember which associations are "fetch joined", so that we know where to inject
            // collection stubs or proxies and where not.
            if (! isset($this->rsm->relationMap[$dqlAlias])) {
                continue;
            }

            $parent = $this->rsm->parentAliasMap[$dqlAlias];

            if (! isset($this->rsm->aliasMap[$parent])) {
                throw HydrationException::parentObjectOfRelationNotFound($dqlAlias, $parent);
            }

            $sourceClassName = $this->rsm->aliasMap[$parent];
            $sourceClass     = $this->getClassMetadata($sourceClassName);
            $association     = $sourceClass->getProperty($this->rsm->relationMap[$dqlAlias]);

            $this->hints['fetched'][$parent][$association->getName()] = true;

            if ($association instanceof ManyToManyAssociationMetadata) {
                continue;
            }

            // Mark any non-collection opposite sides as fetched, too.
            if ($association->getMappedBy()) {
                $this->hints['fetched'][$dqlAlias][$association->getMappedBy()] = true;

                continue;
            }

            // handle fetch-joined owning side bi-directional one-to-one associations
            if ($association->getInversedBy()) {
                $class        = $this->getClassMetadata($className);
                $inverseAssoc = $class->getProperty($association->getInversedBy());

                if (! ($inverseAssoc instanceof ToOneAssociationMetadata)) {
                    continue;
                }

                $this->hints['fetched'][$dqlAlias][$inverseAssoc->getName()] = true;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function cleanup()
    {
        $eagerLoad = (isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD])) && $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] === true;

        parent::cleanup();

        $this->identifierMap          =
        $this->initializedCollections =
        $this->existingCollections    =
        $this->resultPointers         = [];

        if ($eagerLoad) {
            $this->uow->triggerEagerLoads();
        }

        $this->uow->hydrationComplete();
    }

    /**
     * {@inheritdoc}
     */
    protected function hydrateAllData()
    {
        $result = [];

        while ($row = $this->stmt->fetch(FetchMode::ASSOCIATIVE)) {
            $this->hydrateRowData($row, $result);
        }

        // Take snapshots from all newly initialized collections
        foreach ($this->initializedCollections as $coll) {
            $coll->takeSnapshot();
        }

        return $result;
    }

    /**
     * Initializes a related collection.
     *
     * @param object        $entity         The entity to which the collection belongs.
     * @param ClassMetadata $class
     * @param string        $fieldName      The name of the field on the entity that holds the collection.
     * @param string        $parentDqlAlias Alias of the parent fetch joining this collection.
     *
     * @return PersistentCollection
     */
    private function initRelatedCollection($entity, $class, $fieldName, $parentDqlAlias)
    {
        /** @var ToManyAssociationMetadata $association */
        $association = $class->getProperty($fieldName);
        $value       = $association->getValue($entity);
        $oid         = spl_object_id($entity);

        if (! $value instanceof PersistentCollection) {
            $value = $association->wrap($entity, $value, $this->em);

            $association->setValue($entity, $value);

            $this->uow->setOriginalEntityProperty($oid, $fieldName, $value);

            $this->initializedCollections[$oid . $fieldName] = $value;
        } elseif (isset($this->hints[Query::HINT_REFRESH]) ||
            (isset($this->hints['fetched'][$parentDqlAlias][$fieldName]) && ! $value->isInitialized())
        ) {
            // Is already PersistentCollection, but either REFRESH or FETCH-JOIN and UNINITIALIZED!
            $value->setDirty(false);
            $value->setInitialized(true);
            $value->unwrap()->clear();

            $this->initializedCollections[$oid . $fieldName] = $value;
        } else {
            // Is already PersistentCollection, and DON'T REFRESH or FETCH-JOIN!
            $this->existingCollections[$oid . $fieldName] = $value;
        }

        return $value;
    }

    /**
     * Gets an entity instance.
     *
     * @param mixed[] $data     The instance data.
     * @param string  $dqlAlias The DQL alias of the entity's class.
     *
     * @return object The entity.
     *
     * @throws HydrationException
     */
    private function getEntity(array $data, $dqlAlias)
    {
        $className = $this->rsm->aliasMap[$dqlAlias];

        if (isset($this->rsm->discriminatorColumns[$dqlAlias])) {
            $fieldName = $this->rsm->discriminatorColumns[$dqlAlias];

            if (! isset($this->rsm->metaMappings[$fieldName])) {
                throw HydrationException::missingDiscriminatorMetaMappingColumn($className, $fieldName, $dqlAlias);
            }

            $discrColumn = $this->rsm->metaMappings[$fieldName];

            if (! isset($data[$discrColumn])) {
                throw HydrationException::missingDiscriminatorColumn($className, $discrColumn, $dqlAlias);
            }

            if ($data[$discrColumn] === '') {
                throw HydrationException::emptyDiscriminatorValue($dqlAlias);
            }

            $discrMap           = $this->metadataCache[$className]->discriminatorMap;
            $discriminatorValue = (string) $data[$discrColumn];

            if (! isset($discrMap[$discriminatorValue])) {
                throw HydrationException::invalidDiscriminatorValue($discriminatorValue, array_keys($discrMap));
            }

            $className = $discrMap[$discriminatorValue];

            unset($data[$discrColumn]);
        }

        if (isset($this->hints[Query::HINT_REFRESH_ENTITY], $this->rootAliases[$dqlAlias])) {
            $id = $this->em->getIdentifierFlattener()->flattenIdentifier($this->metadataCache[$className], $data);

            $this->em->getUnitOfWork()->registerManaged($this->hints[Query::HINT_REFRESH_ENTITY], $id, $data);
        }

        $this->hints['fetchAlias'] = $dqlAlias;

        return $this->uow->createEntity($className, $data, $this->hints);
    }

    /**
     * @param string  $className
     * @param mixed[] $data
     *
     * @return mixed
     */
    private function getEntityFromIdentityMap($className, array $data)
    {
        /* @var ClassMetadata $class */
        $class = $this->metadataCache[$className];
        $id    = $this->em->getIdentifierFlattener()->flattenIdentifier($class, $data);

        return $this->uow->tryGetById($id, $class->getRootClassName());
    }

    /**
     * Hydrates a single row in an SQL result set.
     *
     * @internal
     * First, the data of the row is split into chunks where each chunk contains data
     * that belongs to a particular component/class. Afterwards, all these chunks
     * are processed, one after the other. For each chunk of class data only one of the
     * following code paths is executed:
     *
     * Path A: The data chunk belongs to a joined/associated object and the association
     *         is collection-valued.
     * Path B: The data chunk belongs to a joined/associated object and the association
     *         is single-valued.
     * Path C: The data chunk belongs to a root result element/object that appears in the topmost
     *         level of the hydrated result. A typical example are the objects of the type
     *         specified by the FROM clause in a DQL query.
     *
     * @param mixed[] $row    The data of the row to process.
     * @param mixed[] $result The result array to fill.
     */
    protected function hydrateRowData(array $row, array &$result)
    {
        // Initialize
        $id                 = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        // Split the row data into chunks of class data.
        $rowData = $this->gatherRowData($row, $id, $nonemptyComponents);

        // reset result pointers for each data row
        $this->resultPointers = [];

        // Hydrate the data chunks
        foreach ($rowData['data'] as $dqlAlias => $data) {
            $entityName = $this->rsm->aliasMap[$dqlAlias];

            if (isset($this->rsm->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parentAlias = $this->rsm->parentAliasMap[$dqlAlias];
                // we need the $path to save into the identifier map which entities were already
                // seen for this parent-child relationship
                $path = $parentAlias . '.' . $dqlAlias;

                // We have a RIGHT JOIN result here. Doctrine cannot hydrate RIGHT JOIN Object-Graphs
                if (! isset($nonemptyComponents[$parentAlias])) {
                    // TODO: Add special case code where we hydrate the right join objects into identity map at least
                    continue;
                }

                $parentClass   = $this->metadataCache[$this->rsm->aliasMap[$parentAlias]];
                $relationField = $this->rsm->relationMap[$dqlAlias];
                $association   = $parentClass->getProperty($relationField);

                // Get a reference to the parent object to which the joined element belongs.
                if ($this->rsm->isMixed && isset($this->rootAliases[$parentAlias])) {
                    $objectClass  = $this->resultPointers[$parentAlias];
                    $parentObject = $objectClass[key($objectClass)];
                } elseif (isset($this->resultPointers[$parentAlias])) {
                    $parentObject = $this->resultPointers[$parentAlias];
                } else {
                    // Parent object of relation not found, mark as not-fetched again
                    $element = $this->getEntity($data, $dqlAlias);

                    // Update result pointer and provide initial fetch data for parent
                    $this->resultPointers[$dqlAlias]               = $element;
                    $rowData['data'][$parentAlias][$relationField] = $element;

                    // Mark as not-fetched again
                    unset($this->hints['fetched'][$parentAlias][$relationField]);
                    continue;
                }

                $oid = spl_object_id($parentObject);

                // Check the type of the relation (many or single-valued)
                if (! ($association instanceof ToOneAssociationMetadata)) {
                    // PATH A: Collection-valued association
                    $reflFieldValue = $association->getValue($parentObject);

                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $collKey = $oid . $relationField;
                        if (isset($this->initializedCollections[$collKey])) {
                            $reflFieldValue = $this->initializedCollections[$collKey];
                        } elseif (! isset($this->existingCollections[$collKey])) {
                            $reflFieldValue = $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                        }

                        $indexExists  = isset($this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]]);
                        $index        = $indexExists ? $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? isset($reflFieldValue[$index]) : false;

                        if (! $indexExists || ! $indexIsValid) {
                            if (isset($this->existingCollections[$collKey])) {
                                $element = $this->getEntityFromIdentityMap($entityName, $data);

                                // Collection exists, only look for the element in the identity map.
                                if ($element) {
                                    $this->resultPointers[$dqlAlias] = $element;
                                } else {
                                    unset($this->resultPointers[$dqlAlias]);
                                }
                            } else {
                                $element = $this->getEntity($data, $dqlAlias);

                                if (isset($this->rsm->indexByMap[$dqlAlias])) {
                                    $indexValue = $row[$this->rsm->indexByMap[$dqlAlias]];
                                    $reflFieldValue->hydrateSet($indexValue, $element);
                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] = $indexValue;
                                } else {
                                    $reflFieldValue->hydrateAdd($element);
                                    $reflFieldValue->last();
                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] = $reflFieldValue->key();
                                }
                                // Update result pointer
                                $this->resultPointers[$dqlAlias] = $element;
                            }
                        } else {
                            // Update result pointer
                            $this->resultPointers[$dqlAlias] = $reflFieldValue[$index];
                        }
                    } elseif (! $reflFieldValue) {
                        $reflFieldValue = $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                    } elseif ($reflFieldValue instanceof PersistentCollection && $reflFieldValue->isInitialized() === false) {
                        $reflFieldValue->setInitialized(true);
                    }
                } else {
                    // PATH B: Single-valued association
                    $reflFieldValue = $association->getValue($parentObject);

                    if (! $reflFieldValue || isset($this->hints[Query::HINT_REFRESH]) ||
                        ($reflFieldValue instanceof GhostObjectInterface && ! $reflFieldValue->isProxyInitialized())) {
                        // we only need to take action if this value is null,
                        // we refresh the entity or its an uninitialized proxy.
                        if (isset($nonemptyComponents[$dqlAlias])) {
                            $element = $this->getEntity($data, $dqlAlias);

                            $association->setValue($parentObject, $element);
                            $this->uow->setOriginalEntityProperty($oid, $relationField, $element);

                            $mappedBy    = $association->getMappedBy();
                            $targetClass = $this->metadataCache[$association->getTargetEntity()];

                            if ($association->isOwningSide()) {
                                // TODO: Just check hints['fetched'] here?
                                // If there is an inverse mapping on the target class its bidirectional
                                if ($association->getInversedBy()) {
                                    $inverseAssociation = $targetClass->getProperty($association->getInversedBy());

                                    if ($inverseAssociation instanceof ToOneAssociationMetadata) {
                                        $inverseAssociation->setValue($element, $parentObject);

                                        $this->uow->setOriginalEntityProperty(
                                            spl_object_id($element),
                                            $inverseAssociation->getName(),
                                            $parentObject
                                        );
                                    }
                                }
                            } else {
                                // For sure bidirectional, as there is no inverse side in unidirectional mappings
                                $inverseAssociation = $targetClass->getProperty($mappedBy);

                                $inverseAssociation->setValue($element, $parentObject);

                                $this->uow->setOriginalEntityProperty(
                                    spl_object_id($element),
                                    $mappedBy,
                                    $parentObject
                                );
                            }

                            // Update result pointer
                            $this->resultPointers[$dqlAlias] = $element;
                        } else {
                            $association->setValue($parentObject, null);

                            $this->uow->setOriginalEntityProperty($oid, $relationField, null);
                        }
                    // else leave $reflFieldValue null for single-valued associations
                    } else {
                        // Update result pointer
                        $this->resultPointers[$dqlAlias] = $reflFieldValue;
                    }
                }
            } else {
                // PATH C: Its a root result element
                $this->rootAliases[$dqlAlias] = true; // Mark as root alias
                $entityKey                    = $this->rsm->entityMappings[$dqlAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (! isset($nonemptyComponents[$dqlAlias])) {
                    if ($this->rsm->isMixed) {
                        $result[] = [$entityKey => null];
                    } else {
                        $result[] = null;
                    }
                    $resultKey = $this->resultCounter;
                    ++$this->resultCounter;
                    continue;
                }

                // check for existing result from the iterations before
                if (! isset($this->identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                    $element = $this->getEntity($data, $dqlAlias);

                    if ($this->rsm->isMixed) {
                        $element = [$entityKey => $element];
                    }

                    if (isset($this->rsm->indexByMap[$dqlAlias])) {
                        $resultKey = $row[$this->rsm->indexByMap[$dqlAlias]];

                        if (isset($this->hints['collection'])) {
                            $this->hints['collection']->hydrateSet($resultKey, $element);
                        }

                        $result[$resultKey] = $element;
                    } else {
                        $resultKey = $this->resultCounter;
                        ++$this->resultCounter;

                        if (isset($this->hints['collection'])) {
                            $this->hints['collection']->hydrateAdd($element);
                        }

                        $result[] = $element;
                    }

                    $this->identifierMap[$dqlAlias][$id[$dqlAlias]] = $resultKey;

                    // Update result pointer
                    $this->resultPointers[$dqlAlias] = $element;
                } else {
                    // Update result pointer
                    $index                           = $this->identifierMap[$dqlAlias][$id[$dqlAlias]];
                    $this->resultPointers[$dqlAlias] = $result[$index];
                    $resultKey                       = $index;
                }
            }

            if (isset($this->hints[Query::HINT_INTERNAL_ITERATION]) && $this->hints[Query::HINT_INTERNAL_ITERATION]) {
                $this->uow->hydrationComplete();
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                $resultKey = (isset($this->rsm->indexByMap['scalars']))
                    ? $row[$this->rsm->indexByMap['scalars']]
                    : $this->resultCounter - 1;
            }

            foreach ($rowData['scalars'] as $name => $value) {
                $result[$resultKey][$name] = $value;
            }
        }

        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (! isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }


            $hasNoScalars = ! (isset($rowData['scalars']) && $rowData['scalars']);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $obj = $newObject['obj'];

                if ($hasNoScalars && count($rowData['newObjects']) === 1) {
                    $result[$resultKey] = $obj;

                    continue;
                }

                $result[$resultKey][$objIndex] = $obj;
            }
        }
    }

    /**
     * When executed in a hydrate() loop we may have to clear internal state to
     * decrease memory consumption.
     *
     * @param mixed $eventArgs
     */
    public function onClear($eventArgs)
    {
        parent::onClear($eventArgs);

        $aliases = array_keys($this->identifierMap);

        $this->identifierMap = array_fill_keys($aliases, []);
    }
}
