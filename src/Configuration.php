<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\SecondLevelCachePlugin;
use Doctrine\ORM\Exception\InvalidEntityRepository;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\EntityListenerResolver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Mapping\TypedFieldMapper;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;

use function class_exists;
use function is_a;
use function method_exists;
use function strtolower;

/**
 * Configuration container for all configuration options of Doctrine.
 * It combines all configuration options from DBAL & ORM.
 *
 * Internal note: When adding a new configuration option just write a getter/setter pair.
 */
class Configuration extends \Doctrine\DBAL\Configuration
{
    /** @var mixed[] */
    protected array $attributes = [];

    /** @phpstan-var array<class-string<AbstractPlatform>, ClassMetadata::GENERATOR_TYPE_*> */
    private $identityGenerationPreferences = [];

    /** @phpstan-param array<class-string<AbstractPlatform>, ClassMetadata::GENERATOR_TYPE_*> $value */
    public function setIdentityGenerationPreferences(array $value): void
    {
        $this->identityGenerationPreferences = $value;
    }

    /** @phpstan-return array<class-string<AbstractPlatform>, ClassMetadata::GENERATOR_TYPE_*> $value */
    public function getIdentityGenerationPreferences(): array
    {
        return $this->identityGenerationPreferences;
    }

    public function getUseSchemaEditorApi(): bool
    {
        return $this->attributes['use_schema_editor_api'] ?? false;
    }

    public function setUseSchemaEditorApi(bool $useSchemaEditorApi): void
    {
        /** @phpstan-ignore function.alreadyNarrowedType (This API is not always available) */
        $schemaEditorApiIsAvailable = method_exists(Schema::class, 'edit');

        if ($useSchemaEditorApi && ! $schemaEditorApiIsAvailable) {
            throw new LogicException('Using the schema editor API requires doctrine/dbal 4.5 or higher.');
        }

        if (! $useSchemaEditorApi && $schemaEditorApiIsAvailable) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/pull/12780',
                'Disabling the schema editor API is deprecated and will be impossible in Doctrine ORM 4.0.',
            );
        }

        $this->attributes['use_schema_editor_api'] = $useSchemaEditorApi;
    }

    /**
     * Sets the cache driver implementation that is used for metadata caching.
     *
     * @todo Force parameter to be a Closure to ensure lazy evaluation
     *       (as soon as a metadata cache is in effect, the driver never needs to initialize).
     */
    public function setMetadataDriverImpl(MappingDriver $driverImpl): void
    {
        $this->attributes['metadataDriverImpl'] = $driverImpl;
    }

    /**
     * Sets the entity alias map.
     *
     * @phpstan-param array<string, string> $entityNamespaces
     */
    public function setEntityNamespaces(array $entityNamespaces): void
    {
        $this->attributes['entityNamespaces'] = $entityNamespaces;
    }

    /**
     * Retrieves the list of registered entity namespace aliases.
     *
     * @phpstan-return array<string, string>
     */
    public function getEntityNamespaces(): array
    {
        return $this->attributes['entityNamespaces'];
    }

    /**
     * Gets the cache driver implementation that is used for the mapping metadata.
     */
    public function getMetadataDriverImpl(): MappingDriver|null
    {
        return $this->attributes['metadataDriverImpl'] ?? null;
    }

    /**
     * Gets the cache driver implementation that is used for the query cache (SQL cache).
     */
    public function getQueryCache(): CacheItemPoolInterface|null
    {
        return $this->attributes['queryCache'] ?? null;
    }

    /**
     * Sets the cache driver implementation that is used for the query cache (SQL cache).
     */
    public function setQueryCache(CacheItemPoolInterface $cache): void
    {
        $this->attributes['queryCache'] = $cache;
    }

    public function getHydrationCache(): CacheItemPoolInterface|null
    {
        return $this->attributes['hydrationCache'] ?? null;
    }

    public function setHydrationCache(CacheItemPoolInterface $cache): void
    {
        $this->attributes['hydrationCache'] = $cache;
    }

    public function getMetadataCache(): CacheItemPoolInterface|null
    {
        return $this->attributes['metadataCache'] ?? null;
    }

    public function setMetadataCache(CacheItemPoolInterface $cache): void
    {
        $this->attributes['metadataCache'] = $cache;
    }

    /**
     * Registers a custom DQL function that produces a string value.
     * Such a function can then be used in any DQL statement in any place where string
     * functions are allowed.
     *
     * DQL function names are case-insensitive.
     *
     * @param class-string|callable $className Class name or a callable that returns the function.
     * @phpstan-param class-string<FunctionNode>|callable(string):FunctionNode $className
     */
    public function addCustomStringFunction(string $name, string|callable $className): void
    {
        $this->attributes['customStringFunctions'][strtolower($name)] = $className;
    }

    /**
     * Gets the implementation class name of a registered custom string DQL function.
     *
     * @phpstan-return class-string<FunctionNode>|callable(string):FunctionNode|null
     */
    public function getCustomStringFunction(string $name): string|callable|null
    {
        $name = strtolower($name);

        return $this->attributes['customStringFunctions'][$name] ?? null;
    }

    /**
     * Sets a map of custom DQL string functions.
     *
     * Keys must be function names and values the FQCN of the implementing class.
     * The function names will be case-insensitive in DQL.
     *
     * Any previously added string functions are discarded.
     *
     * @phpstan-param array<string, class-string<FunctionNode>|callable(string):FunctionNode> $functions The map of custom
     *                                                     DQL string functions.
     */
    public function setCustomStringFunctions(array $functions): void
    {
        foreach ($functions as $name => $className) {
            $this->addCustomStringFunction($name, $className);
        }
    }

    /**
     * Registers a custom DQL function that produces a numeric value.
     * Such a function can then be used in any DQL statement in any place where numeric
     * functions are allowed.
     *
     * DQL function names are case-insensitive.
     *
     * @param class-string|callable $className Class name or a callable that returns the function.
     * @phpstan-param class-string<FunctionNode>|callable(string):FunctionNode $className
     */
    public function addCustomNumericFunction(string $name, string|callable $className): void
    {
        $this->attributes['customNumericFunctions'][strtolower($name)] = $className;
    }

    /**
     * Gets the implementation class name of a registered custom numeric DQL function.
     *
     * @phpstan-return class-string<FunctionNode>|callable(string):FunctionNode|null
     */
    public function getCustomNumericFunction(string $name): string|callable|null
    {
        $name = strtolower($name);

        return $this->attributes['customNumericFunctions'][$name] ?? null;
    }

    /**
     * Sets a map of custom DQL numeric functions.
     *
     * Keys must be function names and values the FQCN of the implementing class.
     * The function names will be case-insensitive in DQL.
     *
     * Any previously added numeric functions are discarded.
     *
     * @param array<string, class-string> $functions The map of custom
     *                                               DQL numeric functions.
     */
    public function setCustomNumericFunctions(array $functions): void
    {
        foreach ($functions as $name => $className) {
            $this->addCustomNumericFunction($name, $className);
        }
    }

    /**
     * Registers a custom DQL function that produces a date/time value.
     * Such a function can then be used in any DQL statement in any place where date/time
     * functions are allowed.
     *
     * DQL function names are case-insensitive.
     *
     * @param string|callable $className Class name or a callable that returns the function.
     * @phpstan-param class-string<FunctionNode>|callable(string):FunctionNode $className
     */
    public function addCustomDatetimeFunction(string $name, string|callable $className): void
    {
        $this->attributes['customDatetimeFunctions'][strtolower($name)] = $className;
    }

    /**
     * Gets the implementation class name of a registered custom date/time DQL function.
     *
     * @return class-string|callable|null
     */
    public function getCustomDatetimeFunction(string $name): string|callable|null
    {
        $name = strtolower($name);

        return $this->attributes['customDatetimeFunctions'][$name] ?? null;
    }

    /**
     * Sets a map of custom DQL date/time functions.
     *
     * Keys must be function names and values the FQCN of the implementing class.
     * The function names will be case-insensitive in DQL.
     *
     * Any previously added date/time functions are discarded.
     *
     * @param array $functions The map of custom DQL date/time functions.
     * @phpstan-param array<string, class-string<FunctionNode>|callable(string):FunctionNode> $functions
     */
    public function setCustomDatetimeFunctions(array $functions): void
    {
        foreach ($functions as $name => $className) {
            $this->addCustomDatetimeFunction($name, $className);
        }
    }

    /**
     * Sets a TypedFieldMapper for php typed fields to DBAL types auto-completion.
     */
    public function setTypedFieldMapper(TypedFieldMapper|null $typedFieldMapper): void
    {
        $this->attributes['typedFieldMapper'] = $typedFieldMapper;
    }

    /**
     * Gets a TypedFieldMapper for php typed fields to DBAL types auto-completion.
     */
    public function getTypedFieldMapper(): TypedFieldMapper|null
    {
        return $this->attributes['typedFieldMapper'] ?? null;
    }

    /**
     * Sets the custom hydrator modes in one pass.
     *
     * @param array<string, class-string<AbstractHydrator>> $modes An array of ($modeName => $hydrator).
     */
    public function setCustomHydrationModes(array $modes): void
    {
        $this->attributes['customHydrationModes'] = [];

        foreach ($modes as $modeName => $hydrator) {
            $this->addCustomHydrationMode($modeName, $hydrator);
        }
    }

    /**
     * Gets the hydrator class for the given hydration mode name.
     *
     * @return class-string<AbstractHydrator>|null
     */
    public function getCustomHydrationMode(string $modeName): string|null
    {
        return $this->attributes['customHydrationModes'][$modeName] ?? null;
    }

    /**
     * Adds a custom hydration mode.
     *
     * @param class-string<AbstractHydrator> $hydrator
     */
    public function addCustomHydrationMode(string $modeName, string $hydrator): void
    {
        $this->attributes['customHydrationModes'][$modeName] = $hydrator;
    }

    /**
     * Sets a class metadata factory.
     *
     * @param class-string $cmfName
     */
    public function setClassMetadataFactoryName(string $cmfName): void
    {
        $this->attributes['classMetadataFactoryName'] = $cmfName;
    }

    /** @return class-string */
    public function getClassMetadataFactoryName(): string
    {
        if (! isset($this->attributes['classMetadataFactoryName'])) {
            $this->attributes['classMetadataFactoryName'] = ClassMetadataFactory::class;
        }

        return $this->attributes['classMetadataFactoryName'];
    }

    /**
     * Adds a filter to the list of possible filters.
     *
     * @param class-string<SQLFilter> $className The class name of the filter.
     */
    public function addFilter(string $name, string $className): void
    {
        $this->attributes['filters'][$name] = $className;
    }

    /**
     * Gets the class name for a given filter name.
     *
     * @return class-string<SQLFilter>|null The class name of the filter,
     *                                      or null if it is not defined.
     */
    public function getFilterClassName(string $name): string|null
    {
        return $this->attributes['filters'][$name] ?? null;
    }

    /**
     * Sets default repository class.
     *
     * @param class-string<EntityRepository> $className
     *
     * @throws InvalidEntityRepository If $classname is not an ObjectRepository.
     */
    public function setDefaultRepositoryClassName(string $className): void
    {
        if (! class_exists($className) || ! is_a($className, EntityRepository::class, true)) {
            throw InvalidEntityRepository::fromClassName($className);
        }

        $this->attributes['defaultRepositoryClassName'] = $className;
    }

    /**
     * Get default repository class.
     *
     * @return class-string<EntityRepository>
     */
    public function getDefaultRepositoryClassName(): string
    {
        return $this->attributes['defaultRepositoryClassName'] ?? EntityRepository::class;
    }

    /**
     * Sets naming strategy.
     */
    public function setNamingStrategy(NamingStrategy $namingStrategy): void
    {
        $this->attributes['namingStrategy'] = $namingStrategy;
    }

    /**
     * Gets naming strategy..
     */
    public function getNamingStrategy(): NamingStrategy
    {
        if (! isset($this->attributes['namingStrategy'])) {
            $this->attributes['namingStrategy'] = new DefaultNamingStrategy();
        }

        return $this->attributes['namingStrategy'];
    }

    /**
     * Sets quote strategy.
     */
    public function setQuoteStrategy(QuoteStrategy $quoteStrategy): void
    {
        $this->attributes['quoteStrategy'] = $quoteStrategy;
    }

    /**
     * Gets quote strategy.
     */
    public function getQuoteStrategy(): QuoteStrategy
    {
        if (! isset($this->attributes['quoteStrategy'])) {
            $this->attributes['quoteStrategy'] = new DefaultQuoteStrategy();
        }

        return $this->attributes['quoteStrategy'];
    }

    /**
     * Set the entity listener resolver.
     */
    public function setEntityListenerResolver(EntityListenerResolver $resolver): void
    {
        $this->attributes['entityListenerResolver'] = $resolver;
    }

    /**
     * Get the entity listener resolver.
     */
    public function getEntityListenerResolver(): EntityListenerResolver
    {
        if (! isset($this->attributes['entityListenerResolver'])) {
            $this->attributes['entityListenerResolver'] = new DefaultEntityListenerResolver();
        }

        return $this->attributes['entityListenerResolver'];
    }

    /**
     * Set the entity repository factory.
     */
    public function setRepositoryFactory(RepositoryFactory $repositoryFactory): void
    {
        $this->attributes['repositoryFactory'] = $repositoryFactory;
    }

    /**
     * Get the entity repository factory.
     */
    public function getRepositoryFactory(): RepositoryFactory
    {
        return $this->attributes['repositoryFactory'] ?? new DefaultRepositoryFactory();
    }

    public function isSecondLevelCacheEnabled(): bool
    {
        return $this->attributes['isSecondLevelCacheEnabled'] ?? false;
    }

    public function setSecondLevelCacheEnabled(bool $flag = true): void
    {
        if ($flag && ! class_exists(SecondLevelCachePlugin::class)) {
            throw new LogicException('Using the second level cache requires installing an additional package. Install with composer require doctrine/orm-plugin-second-level-cache');
        }

        $this->attributes['isSecondLevelCacheEnabled'] = $flag;
    }

    public function setSecondLevelCacheConfiguration(CacheConfiguration $cacheConfig): void
    {
        $this->attributes['secondLevelCacheConfiguration'] = $cacheConfig;
    }

    public function getSecondLevelCacheConfiguration(): CacheConfiguration|null
    {
        if (! isset($this->attributes['secondLevelCacheConfiguration']) && $this->isSecondLevelCacheEnabled()) {
            $this->attributes['secondLevelCacheConfiguration'] = new CacheConfiguration();
        }

        return $this->attributes['secondLevelCacheConfiguration'] ?? null;
    }

    /**
     * Returns query hints, which will be applied to every query in application
     *
     * @phpstan-return array<string, mixed>
     */
    public function getDefaultQueryHints(): array
    {
        return $this->attributes['defaultQueryHints'] ?? [];
    }

    /**
     * Sets array of query hints, which will be applied to every query in application
     *
     * @phpstan-param array<string, mixed> $defaultQueryHints
     */
    public function setDefaultQueryHints(array $defaultQueryHints): void
    {
        $this->attributes['defaultQueryHints'] = $defaultQueryHints;
    }

    /**
     * Gets the value of a default query hint. If the hint name is not recognized, FALSE is returned.
     *
     * @return mixed The value of the hint or FALSE, if the hint name is not recognized.
     */
    public function getDefaultQueryHint(string $name): mixed
    {
        return $this->attributes['defaultQueryHints'][$name] ?? false;
    }

    /**
     * Sets a default query hint. If the hint name is not recognized, it is silently ignored.
     */
    public function setDefaultQueryHint(string $name, mixed $value): void
    {
        $this->attributes['defaultQueryHints'][$name] = $value;
    }

    /**
     * Gets a list of entity class names to be ignored by the SchemaTool
     *
     * @return list<class-string>
     */
    public function getSchemaIgnoreClasses(): array
    {
        return $this->attributes['schemaIgnoreClasses'] ?? [];
    }

    /**
     * Sets a list of entity class names to be ignored by the SchemaTool
     *
     * @param list<class-string> $schemaIgnoreClasses List of entity class names
     */
    public function setSchemaIgnoreClasses(array $schemaIgnoreClasses): void
    {
        $this->attributes['schemaIgnoreClasses'] = $schemaIgnoreClasses;
    }

    public function setEagerFetchBatchSize(int $batchSize = 100): void
    {
        $this->attributes['fetchModeSubselectBatchSize'] = $batchSize;
    }

    public function getEagerFetchBatchSize(): int
    {
        return $this->attributes['fetchModeSubselectBatchSize'] ?? 100;
    }

    public function setDefaultStringTypeSchemaLength(int $length): void
    {
        $this->attributes['defaultStringTypeSchemaLength'] = $length;
    }

    public function getDefaultStringTypeSchemaLength(): int
    {
        return $this->attributes['defaultStringTypeSchemaLength'] ?? 255;
    }
}
