<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\SimpleAnnotationReader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache as CacheDriver;
use Doctrine\Common\Cache\Psr6\CacheAdapter;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\Exception\CacheException;
use Doctrine\ORM\Cache\Exception\MetadataCacheNotConfigured;
use Doctrine\ORM\Cache\Exception\MetadataCacheUsesNonPersistentCache;
use Doctrine\ORM\Cache\Exception\QueryCacheNotConfigured;
use Doctrine\ORM\Cache\Exception\QueryCacheUsesNonPersistentCache;
use Doctrine\ORM\Exception\InvalidEntityRepository;
use Doctrine\ORM\Exception\NamedNativeQueryNotFound;
use Doctrine\ORM\Exception\NamedQueryNotFound;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Exception\ProxyClassesAlwaysRegenerating;
use Doctrine\ORM\Exception\UnknownEntityNamespace;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\EntityListenerResolver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\ObjectRepository;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;

use function class_exists;
use function strtolower;
use function trim;

/**
 * Configuration container for all configuration options of Doctrine.
 * It combines all configuration options from DBAL & ORM.
 *
 * Internal note: When adding a new configuration option just write a getter/setter pair.
 */
class Configuration extends \Doctrine\DBAL\Configuration
{
    /**
     * Sets the directory where Doctrine generates any necessary proxy class files.
     *
     * @param string $dir
     *
     * @return void
     */
    public function setProxyDir($dir)
    {
        $this->_attributes['proxyDir'] = $dir;
    }

    /**
     * Gets the directory where Doctrine generates any necessary proxy class files.
     *
     * @deprecated 2.7 We're switch to `ocramius/proxy-manager` and this method isn't applicable any longer
     *
     * @see https://github.com/Ocramius/ProxyManager
     *
     * @return string|null
     */
    public function getProxyDir()
    {
        return $this->_attributes['proxyDir'] ?? null;
    }

    /**
     * Gets the strategy for automatically generating proxy classes.
     *
     * @deprecated 2.7 We're switch to `ocramius/proxy-manager` and this method isn't applicable any longer
     *
     * @see https://github.com/Ocramius/ProxyManager
     *
     * @return int Possible values are constants of Doctrine\Common\Proxy\AbstractProxyFactory.
     */
    public function getAutoGenerateProxyClasses()
    {
        return $this->_attributes['autoGenerateProxyClasses'] ?? AbstractProxyFactory::AUTOGENERATE_ALWAYS;
    }

    /**
     * Sets the strategy for automatically generating proxy classes.
     *
     * @param bool|int $autoGenerate Possible values are constants of Doctrine\Common\Proxy\AbstractProxyFactory.
     * True is converted to AUTOGENERATE_ALWAYS, false to AUTOGENERATE_NEVER.
     *
     * @return void
     */
    public function setAutoGenerateProxyClasses($autoGenerate)
    {
        $this->_attributes['autoGenerateProxyClasses'] = (int) $autoGenerate;
    }

    /**
     * Gets the namespace where proxy classes reside.
     *
     * @deprecated 2.7 We're switch to `ocramius/proxy-manager` and this method isn't applicable any longer
     *
     * @see https://github.com/Ocramius/ProxyManager
     *
     * @return string|null
     */
    public function getProxyNamespace()
    {
        return $this->_attributes['proxyNamespace'] ?? null;
    }

    /**
     * Sets the namespace where proxy classes reside.
     *
     * @param string $ns
     *
     * @return void
     */
    public function setProxyNamespace($ns)
    {
        $this->_attributes['proxyNamespace'] = $ns;
    }

    /**
     * Sets the cache driver implementation that is used for metadata caching.
     *
     * @return void
     *
     * @todo Force parameter to be a Closure to ensure lazy evaluation
     *       (as soon as a metadata cache is in effect, the driver never needs to initialize).
     */
    public function setMetadataDriverImpl(MappingDriver $driverImpl)
    {
        $this->_attributes['metadataDriverImpl'] = $driverImpl;
    }

    /**
     * Adds a new default annotation driver with a correctly configured annotation reader. If $useSimpleAnnotationReader
     * is true, the notation `@Entity` will work, otherwise, the notation `@ORM\Entity` will be supported.
     *
     * @param string|string[] $paths
     * @param bool            $useSimpleAnnotationReader
     * @psalm-param string|list<string> $paths
     *
     * @return AnnotationDriver
     */
    public function newDefaultAnnotationDriver($paths = [], $useSimpleAnnotationReader = true)
    {
        AnnotationRegistry::registerFile(__DIR__ . '/Mapping/Driver/DoctrineAnnotations.php');

        if ($useSimpleAnnotationReader) {
            // Register the ORM Annotations in the AnnotationRegistry
            $reader = new SimpleAnnotationReader();
            $reader->addNamespace('Doctrine\ORM\Mapping');
        } else {
            $reader = new AnnotationReader();
        }

        if (class_exists(ArrayCache::class)) {
            $reader = new CachedReader($reader, new ArrayCache());
        }

        return new AnnotationDriver(
            $reader,
            (array) $paths
        );
    }

    /**
     * Adds a namespace under a certain alias.
     *
     * @param string $alias
     * @param string $namespace
     *
     * @return void
     */
    public function addEntityNamespace($alias, $namespace)
    {
        $this->_attributes['entityNamespaces'][$alias] = $namespace;
    }

    /**
     * Resolves a registered namespace alias to the full namespace.
     *
     * @param string $entityNamespaceAlias
     *
     * @return string
     *
     * @throws UnknownEntityNamespace
     */
    public function getEntityNamespace($entityNamespaceAlias)
    {
        if (! isset($this->_attributes['entityNamespaces'][$entityNamespaceAlias])) {
            throw UnknownEntityNamespace::fromNamespaceAlias($entityNamespaceAlias);
        }

        return trim($this->_attributes['entityNamespaces'][$entityNamespaceAlias], '\\');
    }

    /**
     * Sets the entity alias map.
     *
     * @psalm-param array<string, string> $entityNamespaces
     *
     * @return void
     */
    public function setEntityNamespaces(array $entityNamespaces)
    {
        $this->_attributes['entityNamespaces'] = $entityNamespaces;
    }

    /**
     * Retrieves the list of registered entity namespace aliases.
     *
     * @psalm-return array<string, string>
     */
    public function getEntityNamespaces()
    {
        return $this->_attributes['entityNamespaces'];
    }

    /**
     * Gets the cache driver implementation that is used for the mapping metadata.
     *
     * @return MappingDriver|null
     */
    public function getMetadataDriverImpl()
    {
        return $this->_attributes['metadataDriverImpl'] ?? null;
    }

    /**
     * Gets the cache driver implementation that is used for the query cache (SQL cache).
     *
     * @return CacheDriver|null
     */
    public function getQueryCacheImpl()
    {
        return $this->_attributes['queryCacheImpl'] ?? null;
    }

    /**
     * Sets the cache driver implementation that is used for the query cache (SQL cache).
     *
     * @return void
     */
    public function setQueryCacheImpl(CacheDriver $cacheImpl)
    {
        $this->_attributes['queryCacheImpl'] = $cacheImpl;
    }

    /**
     * Gets the cache driver implementation that is used for the hydration cache (SQL cache).
     *
     * @return CacheDriver|null
     */
    public function getHydrationCacheImpl()
    {
        return $this->_attributes['hydrationCacheImpl'] ?? null;
    }

    /**
     * Sets the cache driver implementation that is used for the hydration cache (SQL cache).
     *
     * @return void
     */
    public function setHydrationCacheImpl(CacheDriver $cacheImpl)
    {
        $this->_attributes['hydrationCacheImpl'] = $cacheImpl;
    }

    /**
     * Gets the cache driver implementation that is used for metadata caching.
     *
     * @deprecated Deprecated in favor of getMetadataCache
     *
     * @return CacheDriver|null
     */
    public function getMetadataCacheImpl()
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8650',
            'Method %s() is deprecated and will be removed in Doctrine ORM 3.0. Use getMetadataCache() instead.',
            __METHOD__
        );

        if (isset($this->_attributes['metadataCacheImpl'])) {
            return $this->_attributes['metadataCacheImpl'];
        }

        return isset($this->_attributes['metadataCache']) ? DoctrineProvider::wrap($this->_attributes['metadataCache']) : null;
    }

    /**
     * Sets the cache driver implementation that is used for metadata caching.
     *
     * @deprecated Deprecated in favor of setMetadataCache
     *
     * @return void
     */
    public function setMetadataCacheImpl(CacheDriver $cacheImpl)
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/issues/8650',
            'Method %s() is deprecated and will be removed in Doctrine ORM 3.0. Use setMetadataCache() instead.',
            __METHOD__
        );

        $this->_attributes['metadataCacheImpl'] = $cacheImpl;
        $this->_attributes['metadataCache']     = CacheAdapter::wrap($cacheImpl);
    }

    public function getMetadataCache(): ?CacheItemPoolInterface
    {
        return $this->_attributes['metadataCache'] ?? null;
    }

    public function setMetadataCache(CacheItemPoolInterface $cache): void
    {
        $this->_attributes['metadataCache']     = $cache;
        $this->_attributes['metadataCacheImpl'] = DoctrineProvider::wrap($cache);
    }

    /**
     * Adds a named DQL query to the configuration.
     *
     * @param string $name The name of the query.
     * @param string $dql  The DQL query string.
     *
     * @return void
     */
    public function addNamedQuery($name, $dql)
    {
        $this->_attributes['namedQueries'][$name] = $dql;
    }

    /**
     * Gets a previously registered named DQL query.
     *
     * @param string $name The name of the query.
     *
     * @return string The DQL query.
     *
     * @throws NamedQueryNotFound
     */
    public function getNamedQuery($name)
    {
        if (! isset($this->_attributes['namedQueries'][$name])) {
            throw NamedQueryNotFound::fromName($name);
        }

        return $this->_attributes['namedQueries'][$name];
    }

    /**
     * Adds a named native query to the configuration.
     *
     * @param string                 $name The name of the query.
     * @param string                 $sql  The native SQL query string.
     * @param Query\ResultSetMapping $rsm  The ResultSetMapping used for the results of the SQL query.
     *
     * @return void
     */
    public function addNamedNativeQuery($name, $sql, Query\ResultSetMapping $rsm)
    {
        $this->_attributes['namedNativeQueries'][$name] = [$sql, $rsm];
    }

    /**
     * Gets the components of a previously registered named native query.
     *
     * @param string $name The name of the query.
     *
     * @return mixed[]
     * @psalm-return array{string, ResultSetMapping} A tuple with the first element being the SQL string and the second
     *                                               element being the ResultSetMapping.
     *
     * @throws NamedNativeQueryNotFound
     */
    public function getNamedNativeQuery($name)
    {
        if (! isset($this->_attributes['namedNativeQueries'][$name])) {
            throw NamedNativeQueryNotFound::fromName($name);
        }

        return $this->_attributes['namedNativeQueries'][$name];
    }

    /**
     * Ensures that this Configuration instance contains settings that are
     * suitable for a production environment.
     *
     * @return void
     *
     * @throws ProxyClassesAlwaysRegenerating
     * @throws CacheException If a configuration setting has a value that is not
     *                        suitable for a production environment.
     */
    public function ensureProductionSettings()
    {
        $queryCacheImpl = $this->getQueryCacheImpl();

        if (! $queryCacheImpl) {
            throw QueryCacheNotConfigured::create();
        }

        if ($queryCacheImpl instanceof ArrayCache) {
            throw QueryCacheUsesNonPersistentCache::fromDriver($queryCacheImpl);
        }

        if ($this->getAutoGenerateProxyClasses()) {
            throw ProxyClassesAlwaysRegenerating::create();
        }

        if (! $this->getMetadataCache()) {
            throw MetadataCacheNotConfigured::create();
        }

        $metadataCacheImpl = $this->getMetadataCacheImpl();

        if ($metadataCacheImpl instanceof ArrayCache) {
            throw MetadataCacheUsesNonPersistentCache::fromDriver($metadataCacheImpl);
        }
    }

    /**
     * Registers a custom DQL function that produces a string value.
     * Such a function can then be used in any DQL statement in any place where string
     * functions are allowed.
     *
     * DQL function names are case-insensitive.
     *
     * @param string          $name      Function name.
     * @param string|callable $className Class name or a callable that returns the function.
     *
     * @return void
     */
    public function addCustomStringFunction($name, $className)
    {
        $this->_attributes['customStringFunctions'][strtolower($name)] = $className;
    }

    /**
     * Gets the implementation class name of a registered custom string DQL function.
     *
     * @param string $name
     *
     * @return string|null
     * @psalm-return ?class-string
     */
    public function getCustomStringFunction($name)
    {
        $name = strtolower($name);

        return $this->_attributes['customStringFunctions'][$name] ?? null;
    }

    /**
     * Sets a map of custom DQL string functions.
     *
     * Keys must be function names and values the FQCN of the implementing class.
     * The function names will be case-insensitive in DQL.
     *
     * Any previously added string functions are discarded.
     *
     * @psalm-param array<string, class-string> $functions The map of custom
     *                                                     DQL string functions.
     *
     * @return void
     */
    public function setCustomStringFunctions(array $functions)
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
     * @param string          $name      Function name.
     * @param string|callable $className Class name or a callable that returns the function.
     *
     * @return void
     */
    public function addCustomNumericFunction($name, $className)
    {
        $this->_attributes['customNumericFunctions'][strtolower($name)] = $className;
    }

    /**
     * Gets the implementation class name of a registered custom numeric DQL function.
     *
     * @param string $name
     *
     * @return string|null
     * @psalm-return ?class-string
     */
    public function getCustomNumericFunction($name)
    {
        $name = strtolower($name);

        return $this->_attributes['customNumericFunctions'][$name] ?? null;
    }

    /**
     * Sets a map of custom DQL numeric functions.
     *
     * Keys must be function names and values the FQCN of the implementing class.
     * The function names will be case-insensitive in DQL.
     *
     * Any previously added numeric functions are discarded.
     *
     * @psalm-param array<string, class-string> $functions The map of custom
     *                                                     DQL numeric functions.
     *
     * @return void
     */
    public function setCustomNumericFunctions(array $functions)
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
     * @param string          $name      Function name.
     * @param string|callable $className Class name or a callable that returns the function.
     * @psalm-param class-string|callable $className
     *
     * @return void
     */
    public function addCustomDatetimeFunction($name, $className)
    {
        $this->_attributes['customDatetimeFunctions'][strtolower($name)] = $className;
    }

    /**
     * Gets the implementation class name of a registered custom date/time DQL function.
     *
     * @param string $name
     *
     * @return string|null
     * @psalm-return ?class-string $name
     */
    public function getCustomDatetimeFunction($name)
    {
        $name = strtolower($name);

        return $this->_attributes['customDatetimeFunctions'][$name] ?? null;
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
     * @psalm-param array<string, string> $functions
     *
     * @return void
     */
    public function setCustomDatetimeFunctions(array $functions)
    {
        foreach ($functions as $name => $className) {
            $this->addCustomDatetimeFunction($name, $className);
        }
    }

    /**
     * Sets the custom hydrator modes in one pass.
     *
     * @param array<string, class-string> $modes An array of ($modeName => $hydrator).
     *
     * @return void
     */
    public function setCustomHydrationModes($modes)
    {
        $this->_attributes['customHydrationModes'] = [];

        foreach ($modes as $modeName => $hydrator) {
            $this->addCustomHydrationMode($modeName, $hydrator);
        }
    }

    /**
     * Gets the hydrator class for the given hydration mode name.
     *
     * @param string $modeName The hydration mode name.
     *
     * @return string|null The hydrator class name.
     * @psalm-return ?class-string
     */
    public function getCustomHydrationMode($modeName)
    {
        return $this->_attributes['customHydrationModes'][$modeName] ?? null;
    }

    /**
     * Adds a custom hydration mode.
     *
     * @param string $modeName The hydration mode name.
     * @param string $hydrator The hydrator class name.
     * @psalm-param class-string $hydrator
     *
     * @return void
     */
    public function addCustomHydrationMode($modeName, $hydrator)
    {
        $this->_attributes['customHydrationModes'][$modeName] = $hydrator;
    }

    /**
     * Sets a class metadata factory.
     *
     * @param string $cmfName
     * @psalm-param class-string $cmfName
     *
     * @return void
     */
    public function setClassMetadataFactoryName($cmfName)
    {
        $this->_attributes['classMetadataFactoryName'] = $cmfName;
    }

    /**
     * @return string
     * @psalm-return class-string
     */
    public function getClassMetadataFactoryName()
    {
        if (! isset($this->_attributes['classMetadataFactoryName'])) {
            $this->_attributes['classMetadataFactoryName'] = ClassMetadataFactory::class;
        }

        return $this->_attributes['classMetadataFactoryName'];
    }

    /**
     * Adds a filter to the list of possible filters.
     *
     * @param string $name      The name of the filter.
     * @param string $className The class name of the filter.
     *
     * @return void
     */
    public function addFilter($name, $className)
    {
        $this->_attributes['filters'][$name] = $className;
    }

    /**
     * Gets the class name for a given filter name.
     *
     * @param string $name The name of the filter.
     *
     * @return string|null The class name of the filter, or null if it is not
     *  defined.
     * @psalm-return ?class-string
     */
    public function getFilterClassName($name)
    {
        return $this->_attributes['filters'][$name] ?? null;
    }

    /**
     * Sets default repository class.
     *
     * @param string $className
     *
     * @return void
     *
     * @throws InvalidEntityRepository If $classname is not an ObjectRepository.
     */
    public function setDefaultRepositoryClassName($className)
    {
        $reflectionClass = new ReflectionClass($className);

        if (! $reflectionClass->implementsInterface(ObjectRepository::class)) {
            throw InvalidEntityRepository::fromClassName($className);
        }

        $this->_attributes['defaultRepositoryClassName'] = $className;
    }

    /**
     * Get default repository class.
     *
     * @return string
     * @psalm-return class-string
     */
    public function getDefaultRepositoryClassName()
    {
        return $this->_attributes['defaultRepositoryClassName'] ?? EntityRepository::class;
    }

    /**
     * Sets naming strategy.
     *
     * @return void
     */
    public function setNamingStrategy(NamingStrategy $namingStrategy)
    {
        $this->_attributes['namingStrategy'] = $namingStrategy;
    }

    /**
     * Gets naming strategy..
     *
     * @return NamingStrategy
     */
    public function getNamingStrategy()
    {
        if (! isset($this->_attributes['namingStrategy'])) {
            $this->_attributes['namingStrategy'] = new DefaultNamingStrategy();
        }

        return $this->_attributes['namingStrategy'];
    }

    /**
     * Sets quote strategy.
     *
     * @return void
     */
    public function setQuoteStrategy(QuoteStrategy $quoteStrategy)
    {
        $this->_attributes['quoteStrategy'] = $quoteStrategy;
    }

    /**
     * Gets quote strategy.
     *
     * @return QuoteStrategy
     */
    public function getQuoteStrategy()
    {
        if (! isset($this->_attributes['quoteStrategy'])) {
            $this->_attributes['quoteStrategy'] = new DefaultQuoteStrategy();
        }

        return $this->_attributes['quoteStrategy'];
    }

    /**
     * Set the entity listener resolver.
     *
     * @return void
     */
    public function setEntityListenerResolver(EntityListenerResolver $resolver)
    {
        $this->_attributes['entityListenerResolver'] = $resolver;
    }

    /**
     * Get the entity listener resolver.
     *
     * @return EntityListenerResolver
     */
    public function getEntityListenerResolver()
    {
        if (! isset($this->_attributes['entityListenerResolver'])) {
            $this->_attributes['entityListenerResolver'] = new DefaultEntityListenerResolver();
        }

        return $this->_attributes['entityListenerResolver'];
    }

    /**
     * Set the entity repository factory.
     *
     * @return void
     */
    public function setRepositoryFactory(RepositoryFactory $repositoryFactory)
    {
        $this->_attributes['repositoryFactory'] = $repositoryFactory;
    }

    /**
     * Get the entity repository factory.
     *
     * @return RepositoryFactory
     */
    public function getRepositoryFactory()
    {
        return $this->_attributes['repositoryFactory'] ?? new DefaultRepositoryFactory();
    }

    /**
     * @return bool
     */
    public function isSecondLevelCacheEnabled()
    {
        return $this->_attributes['isSecondLevelCacheEnabled'] ?? false;
    }

    /**
     * @param bool $flag
     *
     * @return void
     */
    public function setSecondLevelCacheEnabled($flag = true)
    {
        $this->_attributes['isSecondLevelCacheEnabled'] = (bool) $flag;
    }

    /**
     * @return void
     */
    public function setSecondLevelCacheConfiguration(CacheConfiguration $cacheConfig)
    {
        $this->_attributes['secondLevelCacheConfiguration'] = $cacheConfig;
    }

    /**
     * @return CacheConfiguration|null
     */
    public function getSecondLevelCacheConfiguration()
    {
        if (! isset($this->_attributes['secondLevelCacheConfiguration']) && $this->isSecondLevelCacheEnabled()) {
            $this->_attributes['secondLevelCacheConfiguration'] = new CacheConfiguration();
        }

        return $this->_attributes['secondLevelCacheConfiguration'] ?? null;
    }

    /**
     * Returns query hints, which will be applied to every query in application
     *
     * @psalm-return array<string, mixed>
     */
    public function getDefaultQueryHints()
    {
        return $this->_attributes['defaultQueryHints'] ?? [];
    }

    /**
     * Sets array of query hints, which will be applied to every query in application
     *
     * @psalm-param array<string, mixed> $defaultQueryHints
     *
     * @return void
     */
    public function setDefaultQueryHints(array $defaultQueryHints)
    {
        $this->_attributes['defaultQueryHints'] = $defaultQueryHints;
    }

    /**
     * Gets the value of a default query hint. If the hint name is not recognized, FALSE is returned.
     *
     * @param string $name The name of the hint.
     *
     * @return mixed The value of the hint or FALSE, if the hint name is not recognized.
     */
    public function getDefaultQueryHint($name)
    {
        return $this->_attributes['defaultQueryHints'][$name] ?? false;
    }

    /**
     * Sets a default query hint. If the hint name is not recognized, it is silently ignored.
     *
     * @param string $name  The name of the hint.
     * @param mixed  $value The value of the hint.
     *
     * @return void
     */
    public function setDefaultQueryHint($name, $value)
    {
        $this->_attributes['defaultQueryHints'][$name] = $value;
    }
}
