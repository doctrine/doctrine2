<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use BackedEnum;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\TypeProvider;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Internal\TypeProviderLocator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Tools\Pagination\LimitSubqueryWalker;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Generator;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;

use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function current;
use function end;
use function in_array;
use function is_array;
use function is_object;
use function ksort;
use function str_starts_with;

/**
 * Base class for all hydrators. A hydrator is a class that provides some form
 * of transformation of an SQL result set into another structure.
 *
 * @phpstan-type RowMapping = array{
 *     identifiers: array<array-key, array<string, mixed>>,
 *     entities: array<string, array<array-key, array<string, mixed>>>,
 *     scalars: array<array-key, array<string, mixed>>,
 *     newObjects: array<array-key, array<string, mixed>>
 * }
 * @phpstan-consistent-constructor
 */
abstract class AbstractHydrator
{
    /**
     * The ResultSetMapping.
     */
    protected ResultSetMapping|null $rsm = null;

    /**
     * The dbms Platform instance.
     */
    protected AbstractPlatform $platform;

    /**
     * The UnitOfWork of the associated EntityManager.
     */
    protected UnitOfWork $uow;

    private readonly TypeProvider|TypeRegistry $typeProvider;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     */
    protected IdentifierFlattener $identifierFlattener;

    /**
     * Local ClassMetadata cache to avoid going to the EntityManager all the time.
     *
     * @var array<string, ClassMetadata<object>>
     */
    protected array $metadataCache = [];

    /**
     * The cache used during row-by-row hydration.
     *
     * @var array<string, mixed[]|null>
     */
    protected array $cache = [];

    /**
     * The statement that provides the data to hydrate.
     */
    protected Result|null $stmt = null;

    /**
     * The query hints.
     *
     * @var array<string, mixed>
     */
    protected array $hints = [];

    /**
     * The result that is filled by {@see hydrateRow()}.
     *
     * @var mixed[]
     */
    protected array $hydrationResult = [];

    /**
     * The initial identifier hashes per DQL alias, copied for each row by {@see gatherRow()}.
     *
     * @var array<string, string>
     */
    protected array $rowIdTemplate = [];

    /**
     * Identifier hashes per DQL alias of the row last processed by {@see gatherRow()}.
     *
     * @var array<string, string>
     */
    protected array $gatheredRowId = [];

    /**
     * DQL aliases with at least one non NULL identifier value in the row last processed by {@see gatherRow()}.
     *
     * @var array<string, bool>
     */
    protected array $gatheredNonemptyComponents = [];

    /**
     * Whether the current hydration was started through {@see toIterable()},
     * so that each row is hydrated on its own.
     */
    protected bool $iterating = false;

    /**
     * Columns of the current result grouped by how they are hydrated, computed once from the first row.
     *
     * @var RowMapping|null
     */
    private array|null $rowMapping = null;

    /**
     * Per hydrator class and row method, the class outside of the ORM that overrides it.
     *
     * @var array<class-string, array{hydrateRowData: class-string|null, gatherRow: class-string|null, gatherRowData: class-string|null, gatherScalarRowData: class-string|null}>
     */
    private static array $legacyOverrides = [];

    private bool $legacyHydrateRowData = false;

    private bool $legacyGatherRowData = false;

    private bool $legacyGatherScalarRowData = false;

    private bool $customGatherRow = false;

    /**
     * Initializes a new instance of a class derived from <tt>AbstractHydrator</tt>.
     */
    public function __construct(protected EntityManagerInterface $em)
    {
        $this->platform            = $em->getConnection()->getDatabasePlatform();
        $this->uow                 = $em->getUnitOfWork();
        $this->typeProvider        = TypeProviderLocator::fromConnection($em->getConnection());
        $this->identifierFlattener = new IdentifierFlattener($this->uow, $em->getMetadataFactory());
    }

    /**
     * Initiates a row-by-row hydration.
     *
     * @phpstan-param array<string, mixed> $hints
     *
     * @return Generator<array-key, mixed>
     *
     * @final
     */
    final public function toIterable(Result $stmt, ResultSetMapping $resultSetMapping, array $hints = []): Generator
    {
        $this->stmt      = $stmt;
        $this->rsm       = $resultSetMapping;
        $this->hints     = [Query::HINT_INTERNAL_ITERATION => true] + $hints;
        $this->iterating = true;

        $this->detectLegacyOverrides();

        $evm = $this->em->getEventManager();

        $evm->addEventListener([Events::onClear], $this);

        $this->prepare();

        try {
            while (true) {
                $row = $this->statement()->fetchAssociative();

                if ($row === false) {
                    break;
                }

                $this->uow->enterHydration();

                try {
                    if ($this->legacyHydrateRowData) {
                        $result = [];
                        /** @phpstan-ignore method.deprecated */
                        $this->hydrateRowData($row, $result);
                    } else {
                        $this->hydrateRow($row);
                        $result = $this->takeResult();
                    }

                    $this->cleanupAfterRowIteration();
                } finally {
                    $this->uow->leaveHydration();
                }

                if (count($result) === 1) {
                    if (count($resultSetMapping->indexByMap) === 0) {
                        yield end($result);
                    } else {
                        yield from $result;
                    }
                } elseif (is_object(current($result))) {
                    yield $result;
                } else {
                    yield array_merge(...$result);
                }
            }
        } finally {
            $this->uow->enterHydration();
            $this->finishHydration();
        }
    }

    /**
     * Detects the row methods that this hydrator overrides outside of the ORM,
     * so that they are still called during hydration.
     */
    private function detectLegacyOverrides(): void
    {
        self::$legacyOverrides[static::class] ??= [
            'hydrateRowData' => $this->findOverride('hydrateRowData'),
            'gatherRow' => $this->findOverride('gatherRow'),
            'gatherRowData' => $this->findOverride('gatherRowData'),
            'gatherScalarRowData' => $this->findOverride('gatherScalarRowData'),
        ];

        $overrides = self::$legacyOverrides[static::class];

        $this->legacyHydrateRowData      = $overrides['hydrateRowData'] !== null;
        $this->legacyGatherRowData       = $overrides['gatherRowData'] !== null;
        $this->legacyGatherScalarRowData = $overrides['gatherScalarRowData'] !== null;
        $this->customGatherRow           = $this->legacyGatherRowData || $overrides['gatherRow'] !== null;

        foreach (['hydrateRowData' => 'hydrateRow', 'gatherRowData' => 'gatherRow', 'gatherScalarRowData' => 'gatherScalarRow'] as $method => $replacement) {
            if ($overrides[$method] === null) {
                continue;
            }

            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/pull/12728',
                'Overriding %s::%s() in %s is deprecated, override %s() instead.',
                self::class,
                $method,
                $overrides[$method],
                $replacement,
            );
        }
    }

    /** @return class-string|null The class outside of the ORM that overrides the method. */
    private function findOverride(string $method): string|null
    {
        $declaringClass = (new ReflectionMethod($this, $method))->getDeclaringClass()->name;

        return str_starts_with($declaringClass, __NAMESPACE__ . '\\') ? null : $declaringClass;
    }

    final protected function statement(): Result
    {
        if ($this->stmt === null) {
            throw new LogicException('Uninitialized _stmt property');
        }

        return $this->stmt;
    }

    final protected function resultSetMapping(): ResultSetMapping
    {
        if ($this->rsm === null) {
            throw new LogicException('Uninitialized _rsm property');
        }

        return $this->rsm;
    }

    /**
     * Hydrates all rows returned by the passed statement instance at once.
     *
     * @phpstan-param array<string, string> $hints
     */
    public function hydrateAll(Result $stmt, ResultSetMapping $resultSetMapping, array $hints = []): mixed
    {
        $this->stmt  = $stmt;
        $this->rsm   = $resultSetMapping;
        $this->hints = $hints;

        $this->detectLegacyOverrides();

        $this->em->getEventManager()->addEventListener([Events::onClear], $this);
        $this->prepare();

        $this->uow->enterHydration();

        try {
            $result = $this->hydrateAllData();
        } finally {
            $this->finishHydration();
        }

        return $result;
    }

    /**
     * Runs the cleanup, which can load further entities, as part of the hydration
     * and leaves it, so that deferred events fire even if the cleanup fails.
     */
    private function finishHydration(): void
    {
        try {
            $this->cleanup();
        } finally {
            $this->uow->leaveHydration();
        }
    }

    /**
     * When executed in a hydrate() loop we have to clear internal state to
     * decrease memory consumption.
     */
    public function onClear(mixed $eventArgs): void
    {
    }

    /**
     * Executes one-time preparation tasks, once each time hydration is started
     * through {@link hydrateAll} or {@link toIterable()}.
     */
    protected function prepare(): void
    {
    }

    /**
     * Executes one-time cleanup tasks at the end of a hydration that was initiated
     * through {@link hydrateAll} or {@link toIterable()}.
     */
    protected function cleanup(): void
    {
        $this->statement()->free();

        $this->stmt                       = null;
        $this->rsm                        = null;
        $this->cache                      = [];
        $this->metadataCache              = [];
        $this->hydrationResult            = [];
        $this->rowIdTemplate              = [];
        $this->gatheredRowId              = [];
        $this->gatheredNonemptyComponents = [];
        $this->iterating                  = false;
        $this->rowMapping                 = null;

        $this
            ->em
            ->getEventManager()
            ->removeEventListener([Events::onClear], $this);
    }

    protected function cleanupAfterRowIteration(): void
    {
    }

    /**
     * Hydrates a single row from the current statement instance into {@see $hydrationResult}.
     *
     * Template method.
     *
     * @param mixed[] $row The row data.
     *
     * @throws HydrationException
     */
    protected function hydrateRow(array $row): void
    {
        throw new HydrationException('hydrateRow() not implemented by this hydrator.');
    }

    /**
     * Hydrates a single row from the current statement instance.
     *
     * @deprecated Override and call {@see hydrateRow()} instead, which fills {@see $hydrationResult}.
     *
     * @param mixed[] $row    The row data.
     * @param mixed[] $result The result to fill.
     *
     * @throws HydrationException
     */
    protected function hydrateRowData(array $row, array &$result): void
    {
        $this->hydrationResult = $result;
        $result                = [];

        try {
            $this->hydrateRow($row);
        } finally {
            $result                = $this->hydrationResult;
            $this->hydrationResult = [];
        }
    }

    /**
     * Hydrates all remaining rows of the current statement instance into {@see $hydrationResult}.
     *
     * Calls {@see hydrateRowData()} instead of {@see hydrateRow()} for hydrators that still
     * override the deprecated method.
     */
    final protected function hydrateAllRows(): void
    {
        $stmt = $this->statement();

        if (! $this->legacyHydrateRowData) {
            while ($row = $stmt->fetchAssociative()) {
                $this->hydrateRow($row);
            }

            return;
        }

        $result                = $this->hydrationResult;
        $this->hydrationResult = [];

        while ($row = $stmt->fetchAssociative()) {
            /** @phpstan-ignore method.deprecated */
            $this->hydrateRowData($row, $result);
        }

        $this->hydrationResult = $result;
    }

    /**
     * Hydrates all rows from the current statement instance at once.
     */
    abstract protected function hydrateAllData(): mixed;

    /**
     * Returns the hydrated {@see $hydrationResult} and resets it, without keeping a second reference to it.
     *
     * @return mixed[]
     */
    protected function takeResult(): array
    {
        $result                = $this->hydrationResult;
        $this->hydrationResult = [];

        return $result;
    }

    /**
     * Processes a row of the result set.
     *
     * Used for identity-based hydration (HYDRATE_OBJECT and HYDRATE_ARRAY).
     * Puts the elements of a result row into a new array, grouped by the dql alias
     * they belong to. The column names in the result set are mapped to their
     * field names during this procedure as well as any necessary conversions on
     * the values applied. Scalar values are kept in a specific key 'scalars'.
     *
     * The identifier hashes (Dql-Alias => ID-Hash) are stored in {@see $gatheredRowId}, and
     * the DQL aliases with at least one non NULL identifier value in {@see $gatheredNonemptyComponents}.
     *
     * @param mixed[] $data SQL Result Row.
     *
     * @return array<string, array<string, mixed>> An array with all the fields
     *                                             (name => value) of the data
     *                                             row, grouped by their
     *                                             component alias.
     * @phpstan-return array{
     *                   data: array<array-key, array>,
     *                   newObjects?: array<array-key, array{
     *                       class: ReflectionClass,
     *                       args: array,
     *                       obj: object
     *                   }>,
     *                   scalars?: array
     *               }
     */
    protected function gatherRow(array $data): array
    {
        if (! $this->legacyGatherRowData) {
            return $this->doGatherRow($data);
        }

        $id                 = $this->rowIdTemplate;
        $nonemptyComponents = [];
        /** @phpstan-ignore method.deprecated */
        $rowData = $this->gatherRowData($data, $id, $nonemptyComponents);

        $this->gatheredRowId              = $id;
        $this->gatheredNonemptyComponents = $nonemptyComponents;

        return $rowData;
    }

    /**
     * Processes a row of the result set.
     *
     * @deprecated Override and call {@see gatherRow()} instead, which stores the identifier hashes
     *             in {@see $gatheredRowId} and the non-empty DQL aliases in {@see $gatheredNonemptyComponents}.
     *
     * @param mixed[] $data SQL Result Row.
     * @phpstan-param array<string, string> $id                 Dql-Alias => ID-Hash.
     * @phpstan-param array<string, bool>   $nonemptyComponents Does this DQL-Alias has at least one non NULL value?
     *
     * @return array<string, array<string, mixed>>
     * @phpstan-return array{
     *                   data: array<array-key, array>,
     *                   newObjects?: array<array-key, array{
     *                       class: ReflectionClass,
     *                       args: array,
     *                       obj: object
     *                   }>,
     *                   scalars?: array
     *               }
     */
    protected function gatherRowData(array $data, array &$id, array &$nonemptyComponents): array
    {
        $rowIdTemplate       = $this->rowIdTemplate;
        $this->rowIdTemplate = $id;

        try {
            $rowData = $this->doGatherRow($data);
        } finally {
            $this->rowIdTemplate = $rowIdTemplate;
        }

        $id                 = $this->gatheredRowId;
        $nonemptyComponents = $this->gatheredNonemptyComponents + $nonemptyComponents;

        return $rowData;
    }

    /**
     * @param mixed[] $data SQL Result Row.
     *
     * @return array<string, array<string, mixed>>
     * @phpstan-return array{
     *                   data: array<array-key, array>,
     *                   newObjects?: array<array-key, array{
     *                       class: ReflectionClass,
     *                       args: array,
     *                       obj: object
     *                   }>,
     *                   scalars?: array
     *               }
     */
    private function doGatherRow(array $data, bool $lazyEntityData = false): array
    {
        $this->rowMapping ??= $this->buildRowMapping($data);

        $rowData = ['data' => [], 'newObjects' => []];

        foreach ($this->rsm->newObjectMappings as $mapping) {
            if (! array_key_exists($mapping['objIndex'], $this->rsm->newObject)) {
                $this->rsm->newObject[$mapping['objIndex']] = $mapping['className'];
            }
        }

        foreach ($this->rsm->newObject as $objIndex => $newObject) {
            $rowData['newObjects'][$objIndex]['class'] = new ReflectionClass($newObject);
            $rowData['newObjects'][$objIndex]['args']  = [];
        }

        $this->gatherRowIdentifiers($data);

        foreach ($this->rowMapping['newObjects'] as $column => $cacheKeyInfo) {
            $value = $cacheKeyInfo['type']->convertToPHPValue($data[$column], $this->platform);

            if ($value !== null && isset($cacheKeyInfo['enumType'])) {
                $value = $this->buildEnum($value, $cacheKeyInfo['enumType']);
            }

            $rowData['newObjects'][$cacheKeyInfo['objIndex']]['args'][$cacheKeyInfo['argIndex']] = $value;
        }

        foreach ($this->rowMapping['scalars'] as $column => $cacheKeyInfo) {
            $value = $cacheKeyInfo['type']->convertToPHPValue($data[$column], $this->platform);

            if ($value !== null && isset($cacheKeyInfo['enumType'])) {
                $value = $this->buildEnum($value, $cacheKeyInfo['enumType']);
            }

            $rowData['scalars'][$cacheKeyInfo['fieldName']] = $value;
        }

        foreach ($this->rowMapping['entities'] as $dqlAlias => $columns) {
            $rowData['data'][$dqlAlias] = $lazyEntityData ? null : $this->gatherEntityRowData($data, $dqlAlias);
        }

        $nestedEntities = [];
        /**@var string $argAlias */
        foreach ($this->resultSetMapping()->nestedNewObjectArguments as ['ownerIndex' => $ownerIndex, 'argIndex' => $argIndex, 'argAlias' => $argAlias]) {
            if (array_key_exists($argAlias, $rowData['newObjects'])) {
                ksort($rowData['newObjects'][$argAlias]['args']);
                $rowData['newObjects'][$ownerIndex]['args'][$argIndex] = $rowData['newObjects'][$argAlias]['class']->newInstanceArgs($rowData['newObjects'][$argAlias]['args']);
                unset($rowData['newObjects'][$argAlias]);
            } elseif (array_key_exists($argAlias, $rowData['data'])) {
                if (! array_key_exists($argAlias, $nestedEntities)) {
                    $nestedEntities[$argAlias]  = '';
                    $rowData['data'][$argAlias] = $this->hydrateNestedEntity(
                        $rowData['data'][$argAlias] ?? $this->gatherEntityRowData($data, $argAlias),
                        $argAlias,
                    );
                }

                $rowData['newObjects'][$ownerIndex]['args'][$argIndex] = $rowData['data'][$argAlias];
            } else {
                throw new LogicException($argAlias . ' does not exist');
            }
        }

        foreach (array_keys($nestedEntities) as $entity) {
            unset($rowData['data'][$entity]);
        }

        foreach ($rowData['newObjects'] as $objIndex => $newObject) {
            ksort($rowData['newObjects'][$objIndex]['args']);
            $obj = $rowData['newObjects'][$objIndex]['class']->newInstanceArgs($rowData['newObjects'][$objIndex]['args']);

            $rowData['newObjects'][$objIndex]['obj'] = $obj;
        }

        return $rowData;
    }

    /**
     * Processes a row of the result set like {@see gatherRow()}, but leaves the entity data
     * of each DQL alias as null, to be gathered on demand with {@see gatherEntityRowData()}.
     *
     * Hydrators that override {@see gatherRow()} or {@see gatherRowData()} expect the entity data
     * in these methods, so it is gathered eagerly for them.
     *
     * @internal
     *
     * @param mixed[] $data SQL Result Row.
     *
     * @return array<string, array<array-key, mixed>>
     * @phpstan-return array{
     *                   data: array<array-key, array<string, mixed>|null>,
     *                   newObjects?: array<array-key, array{
     *                       class: ReflectionClass<object>,
     *                       args: array<array-key, mixed>,
     *                       obj: object
     *                   }>,
     *                   scalars?: array<string, mixed>
     *               }
     */
    final protected function gatherRowWithLazyEntityData(array $data): array
    {
        if ($this->customGatherRow) {
            return $this->gatherRow($data);
        }

        return $this->doGatherRow($data, true);
    }

    /**
     * Gathers the fields (name => value) of the given DQL alias from a row of the result set.
     *
     * @internal
     *
     * @param mixed[] $data SQL Result Row.
     *
     * @return array<string, mixed>
     */
    final protected function gatherEntityRowData(array $data, string $dqlAlias): array
    {
        $entityData = [];

        foreach ($this->rowMapping['entities'][$dqlAlias] as $column => $cacheKeyInfo) {
            $fieldName = $cacheKeyInfo['fieldName'];

            // If there are field name collisions in the child class, then we need
            // to only hydrate if we are looking at the correct discriminator value
            if (
                isset($cacheKeyInfo['discriminatorColumn'], $data[$cacheKeyInfo['discriminatorColumn']])
                && ! isset($cacheKeyInfo['discriminatorValueMap'][(string) $data[$cacheKeyInfo['discriminatorColumn']]])
            ) {
                continue;
            }

            // in an inheritance hierarchy the same field could be defined several times.
            // We overwrite this value so long we don't have a non-null value, that value we keep.
            // Per definition it cannot be that a field is defined several times and has several values.
            if (isset($entityData[$fieldName])) {
                continue;
            }

            $type  = $cacheKeyInfo['type'];
            $value = $type
                ? $type->convertToPHPValue($data[$column], $this->platform)
                : $data[$column];

            if ($value !== null && isset($cacheKeyInfo['enumType'])) {
                $value = $this->buildEnum($value, $cacheKeyInfo['enumType']);
            }

            $entityData[$fieldName] = $value;
        }

        return $entityData;
    }

    /**
     * Stores the identifier hashes of the row in {@see $gatheredRowId} and the DQL aliases
     * with at least one non NULL identifier value in {@see $gatheredNonemptyComponents}.
     *
     * @param mixed[] $data SQL Result Row.
     */
    private function gatherRowIdentifiers(array $data): void
    {
        $id                 = $this->rowIdTemplate;
        $nonemptyComponents = [];

        foreach ($this->rowMapping['identifiers'] as $column => $cacheKeyInfo) {
            $value = $data[$column];

            if (
                $value === null
                || (
                    isset($cacheKeyInfo['discriminatorColumn'], $data[$cacheKeyInfo['discriminatorColumn']])
                    && ! isset($cacheKeyInfo['discriminatorValueMap'][(string) $data[$cacheKeyInfo['discriminatorColumn']]])
                )
            ) {
                continue;
            }

            $dqlAlias                      = $cacheKeyInfo['dqlAlias'];
            $id[$dqlAlias]                .= '|' . $value;
            $nonemptyComponents[$dqlAlias] = true;
        }

        $this->gatheredRowId              = $id;
        $this->gatheredNonemptyComponents = $nonemptyComponents;
    }

    /**
     * Groups the columns of a result row by how they are hydrated, so that the column
     * information is looked up once per result instead of once per row.
     *
     * @param mixed[] $data SQL Result Row.
     *
     * @return RowMapping
     */
    private function buildRowMapping(array $data): array
    {
        $rowMapping = ['identifiers' => [], 'entities' => [], 'scalars' => [], 'newObjects' => []];

        foreach (array_keys($data) as $column) {
            $cacheKeyInfo = $this->hydrateColumnInfo((string) $column);
            if ($cacheKeyInfo === null) {
                continue;
            }

            if (isset($cacheKeyInfo['isNewObjectParameter'])) {
                $rowMapping['newObjects'][$column] = $cacheKeyInfo;
            } elseif (isset($cacheKeyInfo['isScalar'])) {
                $rowMapping['scalars'][$column] = $cacheKeyInfo;
            } else {
                $rowMapping['entities'][$cacheKeyInfo['dqlAlias']][$column] = $cacheKeyInfo;

                if ($cacheKeyInfo['isIdentifier'] ?? false) {
                    $rowMapping['identifiers'][$column] = $cacheKeyInfo;
                }
            }
        }

        return $rowMapping;
    }

    /** @param mixed[] $data pre-hydrated SQL Result Row. */
    protected function hydrateNestedEntity(array $data, string $dqlAlias): mixed
    {
        return $data;
    }

    /**
     * Processes a row of the result set.
     *
     * Used for HYDRATE_SCALAR. This is a variant of _gatherRowData() that
     * simply converts column names to field names and properly converts the
     * values according to their types. The resulting row has the same number
     * of elements as before.
     *
     * @param mixed[] $data
     * @phpstan-param array<string, mixed> $data
     *
     * @return mixed[] The processed row.
     * @phpstan-return array<string, mixed>
     */
    protected function gatherScalarRow(array $data): array
    {
        if ($this->legacyGatherScalarRowData) {
            /** @phpstan-ignore method.deprecated */
            return $this->gatherScalarRowData($data);
        }

        return $this->doGatherScalarRow($data);
    }

    /**
     * Processes a row of the result set.
     *
     * @deprecated Override and call {@see gatherScalarRow()} instead.
     *
     * @param mixed[] $data
     * @phpstan-param array<string, mixed> $data
     *
     * @return mixed[] The processed row.
     * @phpstan-return array<string, mixed>
     */
    protected function gatherScalarRowData(array &$data): array
    {
        return $this->doGatherScalarRow($data);
    }

    /**
     * @param mixed[] $data
     * @phpstan-param array<string, mixed> $data
     *
     * @return mixed[] The processed row.
     * @phpstan-return array<string, mixed>
     */
    private function doGatherScalarRow(array $data): array
    {
        $rowData = [];

        foreach ($data as $key => $value) {
            $cacheKeyInfo = $this->hydrateColumnInfo($key);
            if ($cacheKeyInfo === null) {
                continue;
            }

            $fieldName = $cacheKeyInfo['fieldName'];

            // WARNING: BC break! We know this is the desired behavior to type convert values, but this
            // erroneous behavior exists since 2.0 and we're forced to keep compatibility.
            if (! isset($cacheKeyInfo['isScalar'])) {
                $type  = $cacheKeyInfo['type'];
                $value = $type ? $type->convertToPHPValue($value, $this->platform) : $value;

                $fieldName = $cacheKeyInfo['dqlAlias'] . '_' . $fieldName;
            }

            $rowData[$fieldName] = $value;
        }

        return $rowData;
    }

    /**
     * Retrieve column information from ResultSetMapping.
     *
     * @param string $key Column name
     *
     * @return mixed[]|null
     * @phpstan-return array<string, mixed>|null
     */
    protected function hydrateColumnInfo(string $key): array|null
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        switch (true) {
            // NOTE: Most of the times it's a field mapping, so keep it first!!!
            case isset($this->rsm->fieldMappings[$key]):
                $classMetadata = $this->getClassMetadata($this->rsm->declaringClasses[$key]);
                $fieldName     = $this->rsm->fieldMappings[$key];
                $fieldMapping  = $classMetadata->fieldMappings[$fieldName];
                $ownerMap      = $this->rsm->columnOwnerMap[$key];
                $columnInfo    = [
                    'isIdentifier' => in_array($fieldName, $classMetadata->identifier, true),
                    'fieldName'    => $fieldName,
                    'type'         => $this->typeProvider->get($fieldMapping->type),
                    'dqlAlias'     => $ownerMap,
                    'enumType'     => $this->rsm->enumMappings[$key] ?? null,
                ];

                // the current discriminator value must be saved in order to disambiguate fields hydration,
                // should there be field name collisions
                if ($classMetadata->parentClasses && isset($this->rsm->discriminatorColumns[$ownerMap])) {
                    $discriminatorValues = $this->getDiscriminatorValues($classMetadata);

                    return $this->cache[$key] = array_merge(
                        $columnInfo,
                        [
                            'discriminatorColumn'   => $this->rsm->discriminatorColumns[$ownerMap],
                            'discriminatorValue'    => $classMetadata->discriminatorValue,
                            'discriminatorValues'   => $discriminatorValues,
                            'discriminatorValueMap' => array_flip($discriminatorValues),
                        ],
                    );
                }

                return $this->cache[$key] = $columnInfo;

            case isset($this->rsm->newObjectMappings[$key]):
                // WARNING: A NEW object is also a scalar, so it must be declared before!
                $mapping = $this->rsm->newObjectMappings[$key];

                return $this->cache[$key] = [
                    'isScalar'             => true,
                    'isNewObjectParameter' => true,
                    'fieldName'            => $this->rsm->scalarMappings[$key],
                    'type'                 => $this->typeProvider->get($this->rsm->typeMappings[$key]),
                    'argIndex'             => $mapping['argIndex'],
                    'objIndex'             => $mapping['objIndex'],
                    'enumType'             => $this->rsm->enumMappings[$key] ?? null,
                ];

            case isset($this->rsm->scalarMappings[$key], $this->hints[LimitSubqueryWalker::FORCE_DBAL_TYPE_CONVERSION]):
                return $this->cache[$key] = [
                    'fieldName' => $this->rsm->scalarMappings[$key],
                    'type'      => $this->typeProvider->get($this->rsm->typeMappings[$key]),
                    'dqlAlias'  => '',
                    'enumType'  => $this->rsm->enumMappings[$key] ?? null,
                ];

            case isset($this->rsm->scalarMappings[$key]):
                return $this->cache[$key] = [
                    'isScalar'  => true,
                    'fieldName' => $this->rsm->scalarMappings[$key],
                    'type'      => $this->typeProvider->get($this->rsm->typeMappings[$key]),
                    'enumType'  => $this->rsm->enumMappings[$key] ?? null,
                ];

            case isset($this->rsm->metaMappings[$key]):
                // Meta column (has meaning in relational schema only, i.e. foreign keys or discriminator columns).
                $fieldName = $this->rsm->metaMappings[$key];
                $dqlAlias  = $this->rsm->columnOwnerMap[$key];
                $type      = isset($this->rsm->typeMappings[$key])
                    ? $this->typeProvider->get($this->rsm->typeMappings[$key])
                    : null;

                // Cache metadata fetch
                $this->getClassMetadata($this->rsm->aliasMap[$dqlAlias]);

                return $this->cache[$key] = [
                    'isIdentifier' => isset($this->rsm->isIdentifierColumn[$dqlAlias][$key]),
                    'isMetaColumn' => true,
                    'fieldName'    => $fieldName,
                    'type'         => $type,
                    'dqlAlias'     => $dqlAlias,
                    'enumType'     => $this->rsm->enumMappings[$key] ?? null,
                ];
        }

        // this column is a left over, maybe from a LIMIT query hack for example in Oracle or DB2
        // maybe from an additional column that has not been defined in a NativeQuery ResultSetMapping.
        return null;
    }

    /**
     * @return string[]
     * @phpstan-return non-empty-list<string>
     */
    private function getDiscriminatorValues(ClassMetadata $classMetadata): array
    {
        $values = array_map(
            fn (string $subClass): string => (string) $this->getClassMetadata($subClass)->discriminatorValue,
            $classMetadata->subClasses,
        );

        $values[] = (string) $classMetadata->discriminatorValue;

        return $values;
    }

    /**
     * Retrieve ClassMetadata associated to entity class name.
     */
    protected function getClassMetadata(string $className): ClassMetadata
    {
        if (! isset($this->metadataCache[$className])) {
            $this->metadataCache[$className] = $this->em->getClassMetadata($className);
        }

        return $this->metadataCache[$className];
    }

    /**
     * Register entity as managed in UnitOfWork.
     *
     * @param mixed[] $data
     */
    protected function registerManaged(ClassMetadata $class, object $entity, array $data): void
    {
        $this->em->getUnitOfWork()->registerManaged(
            $entity,
            $this->identifierFlattener->flattenIdentifier($class, $data),
            $data,
        );
    }

    /**
     * @param class-string<BackedEnum> $enumType
     *
     * @return BackedEnum|array<BackedEnum>
     */
    final protected function buildEnum(mixed $value, string $enumType): BackedEnum|array
    {
        $reflection  = new ReflectionEnum($enumType);
        $isIntBacked = $reflection->isBacked() && $reflection->getBackingType()->getName() === 'int';

        if (is_array($value)) {
            return array_map(
                static fn ($value) => $enumType::from($isIntBacked ? (int) $value : $value),
                $value,
            );
        }

        $value = $isIntBacked ? (int) $value : $value;

        return $enumType::from($value);
    }
}
