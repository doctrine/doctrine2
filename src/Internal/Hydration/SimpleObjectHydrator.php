<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query;
use Exception;
use Override;
use RuntimeException;
use ValueError;

use function array_keys;
use function array_search;
use function assert;
use function count;
use function is_array;
use function key;
use function reset;
use function sprintf;

class SimpleObjectHydrator extends AbstractHydrator
{
    use SQLResultCasing;

    private ClassMetadata|null $class = null;

    #[Override]
    protected function prepare(): void
    {
        if (count($this->resultSetMapping()->aliasMap) !== 1) {
            throw new RuntimeException('Cannot use SimpleObjectHydrator with a ResultSetMapping that contains more than one object result.');
        }

        if ($this->resultSetMapping()->scalarMappings) {
            throw new RuntimeException('Cannot use SimpleObjectHydrator with a ResultSetMapping that contains scalar mappings.');
        }

        $this->class = $this->getClassMetadata(reset($this->resultSetMapping()->aliasMap));

        $this->hints['isPartial'] = count($this->rsm->partialAliases) > 0;
    }

    #[Override]
    protected function cleanup(): void
    {
        parent::cleanup();

        $this->uow->triggerEagerLoads();
        $this->uow->hydrationComplete();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function hydrateAllData(): array
    {
        $this->hydrateAllRows();

        $this->em->getUnitOfWork()->triggerEagerLoads();

        return $this->takeResult();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function hydrateRow(array $row): void
    {
        assert($this->class !== null);
        $entityName       = $this->class->name;
        $data             = [];
        $discrColumnValue = null;

        // We need to find the correct entity class name if we have inheritance in resultset
        if ($this->class->inheritanceType !== ClassMetadata::INHERITANCE_TYPE_NONE) {
            $discrColumn     = $this->class->getDiscriminatorColumn();
            $discrColumnName = $this->getSQLResultCasing($this->platform, $discrColumn->name);

            // Find mapped discriminator column from the result set.
            $metaMappingDiscrColumnName = array_search($discrColumnName, $this->resultSetMapping()->metaMappings, true);
            if ($metaMappingDiscrColumnName) {
                $discrColumnName = $metaMappingDiscrColumnName;
            }

            if (! isset($row[$discrColumnName])) {
                throw HydrationException::missingDiscriminatorColumn(
                    $entityName,
                    $discrColumnName,
                    key($this->resultSetMapping()->aliasMap),
                );
            }

            if ($row[$discrColumnName] === '') {
                throw HydrationException::emptyDiscriminatorValue(key(
                    $this->resultSetMapping()->aliasMap,
                ));
            }

            $discrMap = $this->class->discriminatorMap;

            if (! isset($discrMap[$row[$discrColumnName]])) {
                throw HydrationException::invalidDiscriminatorValue($row[$discrColumnName], array_keys($discrMap));
            }

            $entityName       = $discrMap[$row[$discrColumnName]];
            $discrColumnValue = $row[$discrColumnName];

            unset($row[$discrColumnName]);
        }

        foreach ($row as $column => $value) {
            // An ObjectHydrator should be used instead of SimpleObjectHydrator
            if (isset($this->resultSetMapping()->relationMap[$column])) {
                throw new Exception(sprintf('Unable to retrieve association information for column "%s"', $column));
            }

            $cacheKeyInfo = $this->hydrateColumnInfo($column);

            if (! $cacheKeyInfo) {
                continue;
            }

            // If we have inheritance in resultset, make sure the field belongs to the correct class
            if (isset($cacheKeyInfo['discriminatorValueMap']) && ! isset($cacheKeyInfo['discriminatorValueMap'][(string) $discrColumnValue])) {
                continue;
            }

            // Check if value is null before conversion (because some types convert null to something else)
            $valueIsNull = $value === null;

            // Convert field to a valid PHP value
            if (isset($cacheKeyInfo['type'])) {
                $type  = $cacheKeyInfo['type'];
                $value = $type->convertToPHPValue($value, $this->platform);
            }

            if ($value !== null && isset($cacheKeyInfo['enumType'])) {
                $originalValue = $currentValue = $value;
                try {
                    if (! is_array($originalValue)) {
                        $value = $this->buildEnum($originalValue, $cacheKeyInfo['enumType']);
                    } else {
                        $value = [];
                        foreach ($originalValue as $i => $currentValue) {
                            $value[$i] = $this->buildEnum($currentValue, $cacheKeyInfo['enumType']);
                        }
                    }
                } catch (ValueError $e) {
                    throw MappingException::invalidEnumValue(
                        $entityName,
                        $cacheKeyInfo['fieldName'],
                        (string) $currentValue,
                        $cacheKeyInfo['enumType'],
                        $e,
                    );
                }
            }

            $fieldName = $cacheKeyInfo['fieldName'];

            // Prevent overwrite in case of inherit classes using same property name (See AbstractHydrator)
            if (! isset($data[$fieldName]) || ! $valueIsNull) {
                $data[$fieldName] = $value;
            }
        }

        if (isset($this->hints[Query::HINT_REFRESH_ENTITY])) {
            $this->registerManaged($this->class, $this->hints[Query::HINT_REFRESH_ENTITY], $data);
        }

        $uow    = $this->em->getUnitOfWork();
        $entity = $uow->createEntity($entityName, $data, $this->hints);

        $this->hydrationResult[] = $entity;
    }
}
