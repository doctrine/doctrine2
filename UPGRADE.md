# Upgrade to 3.0

## BC Break: `Expr::andX()` and `Expr::orX()` now use variadics

If you extend `Expr` and override these methods, you have to use the new signature.

old signature:
```php
class CustomExpr extends \Doctrine\ORM\Query\Expr
{
    public function andX($x = null){
     // code
    }

    public function orX($x = null){
     // code
    }

}
```
new signature:
```php
class CustomExpr extends \Doctrine\ORM\Query\Expr
{
    public function andX(...$clauses){
     // code
    }

    public function orX(...$clauses){
     // code
    }
}
```
## BC Break: Removed ability to clear cache via console with some cache drivers

The console commands `orm:clear-cache:metadata`, `orm:clear-cache:result`,
and `orm:clear-cache:query` cannot be used with the `ApcCache`, `ApcuCache`,
or `XcacheCache` because the memory is only available to the webserver process.

## BC Break: `orm:run-dql` command's `$depth` parameter removed

The `$depth` parameter has been removed, the dumping functionality
is now provided by [`symfony/var-dumper`](https://github.com/symfony/var-dumper).

## BC Break: Dropped `Doctrine\ORM\Tools\Setup::registerAutoloadDirectory()`

This method used deprecated Doctrine Autoloader and has been removed. Please rely on Composer autoloading instead.

## BC Break: Dropped automatic discriminator map discovery

Automatic discriminator map discovery exhibited multiple flaws
that can't be reliably addressed and supported:

 * discovered entries are not namespaced which leads to collisions,
 * the class name is part of the discriminator map, therefore the class
   must never be renamed.

As a consequence this feature has been dropped.

If your code relied on this feature, please build the discriminator map for
your inheritance tree manually where each entry is an unqualified lowercase
name of the member entities.

## BC Break: Missing type declaration added for identifier generators

The interfaces `Doctrine\ORM\Sequencing\Generator` and
`Doctrine\ORM\Sequencing\Planning\ValueGenerationPlan` now uses explicit type
declaration for parameters and return (as much as possible).

## BC Break: Removed possibility to extend the doctrine mapping xml schema with anything

If you want to extend it now you have to provide your own validation schema.

## BC Break: Entity Listeners no long support naming convention methods

If you want their behavior to be kept, please add the necessary Annotation methods (in case XML driver is used,
no changes are necessary).

## BC Break: Removed `Doctrine\ORM\Mapping\Exporter\VariableExporter` constants

This constant has been removed

 * `Doctrine\ORM\Mapping\Exporter\VariableExporter::INDENTATION`

## BC Break: Removed support for named queries and named native queries

These classes have been removed:

 * `Doctrine/ORM/Annotation/NamedQueries`
 * `Doctrine/ORM/Annotation/NamedQuery`
 * `Doctrine/ORM/Annotation/NamedNativeQueries`
 * `Doctrine/ORM/Annotation/NamedNativeQuery`
 * `Doctrine/ORM/Annotation/ColumnResult`
 * `Doctrine/ORM/Annotation/FieldResult`
 * `Doctrine/ORM/Annotation/EntityResult`
 * `Doctrine/ORM/Annotation/SqlResultSetMapping`
 * `Doctrine/ORM/Annotation/SqlResultSetMappings`

These methods have been removed:

 * `Doctrine/ORM/Configuration::addNamedQuery()`
 * `Doctrine/ORM/Configuration::getNamedQuery()`
 * `Doctrine/ORM/Configuration::addNamedNativeQuery()`
 * `Doctrine/ORM/Configuration::getNamedNativeQuery()`
 * `Doctrine/ORM/Decorator/EntityManagerDecorator::createNamedQuery()`
 * `Doctrine/ORM/Decorator/EntityManagerDecorator::createNamedNativeQuery()`
 * `Doctrine/ORM/EntityManager::createNamedQuery()`
 * `Doctrine/ORM/EntityManager::createNamedNativeQuery()`
 * `Doctrine/ORM/EntityManagerInterface::createNamedQuery()`
 * `Doctrine/ORM/EntityManagerInterface::createNamedNativeQuery()`
 * `Doctrine/ORM/EntityRepository::createNamedQuery()`
 * `Doctrine/ORM/EntityRepository::createNamedNativeQuery()`
 * `Doctrine/ORM/Mapping/ClassMetadata::getNamedQuery()`
 * `Doctrine/ORM/Mapping/ClassMetadata::getNamedQueries()`
 * `Doctrine/ORM/Mapping/ClassMetadata::addNamedQuery()`
 * `Doctrine/ORM/Mapping/ClassMetadata::hasNamedQuery()`
 * `Doctrine/ORM/Mapping/ClassMetadata::getNamedNativeQuery()`
 * `Doctrine/ORM/Mapping/ClassMetadata::getNamedNativeQueries()`
 * `Doctrine/ORM/Mapping/ClassMetadata::addNamedNativeQuery()`
 * `Doctrine/ORM/Mapping/ClassMetadata::hasNamedNativeQuery()`
 * `Doctrine\ORM\Mapping\ClassMetadata::addSqlResultSetMapping()`
 * `Doctrine\ORM\Mapping\ClassMetadata::getSqlResultSetMapping()`
 * `Doctrine\ORM\Mapping\ClassMetadata::getSqlResultSetMappings()`
 * `Doctrine\ORM\Mapping\ClassMetadata::hasSqlResultSetMapping()`
 
## BC Break: Removed support for entity namespace aliases

The support for namespace aliases has been removed.
Please migrate to using `::class` for referencing classes.

These methods have been removed:

 * `Doctrine\ORM\Configuration::addEntityNamespace()`
 * `Doctrine\ORM\Configuration::getEntityNamespace()`
 * `Doctrine\ORM\Configuration::setEntityNamespaces()`
 * `Doctrine\ORM\Configuration::getEntityNamespaces()`
 * `Doctrine\ORM\Mapping\AbstractClassMetadataFactory::getFqcnFromAlias()`
 * `Doctrine\ORM\ORMException::unknownEntityNamespace()`

## BC Break: Removed same-namespace class name resolution

Support for same-namespace class name resolution in mappings has been removed.
If you're using annotation driver, please migrate to references using `::class`.
If you're using XML driver, please migrate to fully qualified references.

These methods have been removed:

 * Doctrine\ORM\Mapping\ClassMetadata::fullyQualifiedClassName()

## BC Break: Removed code generators and related console commands

These console commands have been removed:

 * `orm:convert-mapping`
 * `orm:generate:entities`
 * `orm:generate-repositories`

These classes have been removed:

 * `Doctrine\ORM\Tools\EntityGenerator`
 * `Doctrine\ORM\Tools\EntityRepositoryGenerator`

The whole Doctrine\ORM\Tools\Export namespace with all its members has been removed as well.

## BC Break: proxies no longer implement `Doctrine\ORM\Proxy\Proxy`

Proxy objects no longer implement `Doctrine\ORM\Proxy\Proxy` nor
`Doctrine\Common\Persistence\Proxy`: instead, they implement
`ProxyManager\Proxy\GhostObjectInterface`.

These related classes have been removed:

 * `Doctrine\ORM\Proxy\ProxyFactory` - replaced by `Doctrine\ORM\Proxy\Factory\StaticProxyFactory`
   and `Doctrine\ORM\Proxy\Factory\ProxyFactory`
 * `Doctrine\ORM\Proxy\Proxy`
 * `Doctrine\ORM\Proxy\Autoloader` - we suggest using the composer autoloader instead
 * `Doctrine\ORM\Reflection\RuntimePublicReflectionProperty`

These methods have been removed:

 * `Doctrine\ORM\Configuration#getProxyDir()`
 * `Doctrine\ORM\Configuration#getAutoGenerateProxyClasses()`
 * `Doctrine\ORM\Configuration#getProxyNamespace()`

Proxy class names change: the generated proxies now follow
the [`ClassNameInflector`](https://github.com/Ocramius/ProxyManager/blob/2.1.1/src/ProxyManager/Inflector/ClassNameInflector.php)
naming.

Proxies are also always generated if not found: fatal errors due to missing
proxy classes should no longer occur with ORM default settings.

In addition to that, the following changes affect entity lazy-loading semantics:

 * `final` methods are now allowed
 * `__clone` is no longer called by the ORM
 * `__wakeup` is no longer called by the ORM
 * `serialize($proxy)` will lead to full recursive proxy initialization: please mitigate
   the recursive initialization by implementing
   the [`Serializable`](https://secure.php.net/manual/en/class.serializable.php) interface
 * `clone $proxy` will lead to full initialization of the cloned instance, not the
   original instance
 * lazy-loading a detached proxy no longer causes the proxy identifiers to be reset
   to `null`
 * identifier properties are always set when the ORM produces a proxy instance
 * calling a method on a proxy no longer causes proxy lazy-loading if the method does
   not access any un-initialized proxy state
 * accessing entity private state, even with reflection, will trigger lazy-loading

## BC Break: Removed `Doctrine\ORM\Version`

The `Doctrine\ORM\Version` class is no longer available: please refrain from checking the ORM version at runtime.

## BC Break: Removed `EntityManager#merge()` and `EntityManager#detach()` methods

Merge and detach semantics were a poor fit for the PHP "share-nothing" architecture.
In addition to that, merging/detaching caused multiple issues with data integrity
in the managed entity graph, which was constantly spawning more edge-case bugs/scenarios.

The following API methods were therefore removed:

* `EntityManager#merge()`
* `EntityManager#detach()`
* `UnitOfWork#merge()`
* `UnitOfWork#detach()`

Users are encouraged to migrate `EntityManager#detach()` calls to `EntityManager#clear()`.

In order to maintain performance on batch processing jobs, it is endorsed to enable
the second level cache (http://docs.doctrine-project.org/projects/doctrine-orm/en/latest/reference/second-level-cache.html)
on entities that are frequently reused across multiple `EntityManager#clear()` calls.

An alternative to `EntityManager#merge()` is not provided by ORM 3.0, since the merging
semantics should be part of the business domain rather than the persistence domain of an
application. If your application relies heavily on CRUD-alike interactions and/or `PATCH`
restful operations, you should look at alternatives such as [JMSSerializer](https://github.com/schmittjoh/serializer).

## BC Break: Added the final keyword for  `EntityManager`

Final keyword has been added to the ``EntityManager::class`` in order to ensure that EntityManager is not used as valid extension point. Valid extension point should be EntityManagerInterface.

## BC Break: ``EntityManagerInterface`` is now used instead of ``EntityManager`` in typehints

`Sequencing\Generator#generate()` now takes ``EntityManagerInterface`` as its first argument instead of ``EntityManager``. If you have any custom generators, please update your code accordingly.

## BC Break: Removed `EntityManager#flush($entity)` and `EntityManager#flush($entities)`

If your code relies on single entity flushing optimisations via
`EntityManager#flush($entity)`, the signature has been changed to
`EntityManager#flush()`.

Said API was affected by multiple data integrity bugs due to the fact
that change tracking was being restricted upon a subset of the managed
entities. The ORM cannot support committing subsets of the managed
entities while also guaranteeing data integrity, therefore this
utility was removed.

The `flush()` semantics remain the same, but the change tracking will be performed
on all entities managed by the unit of work, and not just on the provided
`$entity` or `$entities`, as the parameter is now completely ignored.

The same applies to `UnitOfWork#commit($entity)`, which now is simply
`UnitOfWork#commit()`.

If you would still like to perform batching operations over small `UnitOfWork`
instances, it is suggested to follow these paths instead:

 * eagerly use `EntityManager#clear()` in conjunction with a specific second level
   cache configuration (see http://docs.doctrine-project.org/projects/doctrine-orm/en/latest/reference/second-level-cache.html)
 * use an explicit change tracking policy (see http://docs.doctrine-project.org/projects/doctrine-orm/en/latest/reference/change-tracking-policies.html)

## BC Break: Removed ``YAML`` mapping drivers.

If your code relies on ``YamlDriver``  or ``SimpleYamlDriver``, you **MUST** change to
annotation or XML drivers instead.

## BC Break: Changed methods in ``ClassMetadata``

* ``ClassMetadata::addInheritedProperty``
* ``ClassMetadata::setDiscriminatorColumn``

## BC Break: Removed methods in ``ClassMetadata``

* ``ClassMetadata::getTypeOfField`` (to be removed, part of Common API)

## BC Break: Removed methods in ``ClassMetadata``

* ``ClassMetadata::setTableName`` => Use ``ClassMetadata::setPrimaryTable(['name' => ...])``
* ``ClassMetadata::getFieldMapping`` => Use ``ClassMetadata::getProperty()`` and its methods
* ``ClassMetadata::getQuotedColumnName`` => Use ``ClassMetadata::getProperty()::getQuotedColumnName()``
* ``ClassMetadata::getQuotedTableName``
* ``ClassMetadata::getQuotedJoinTableName``
* ``ClassMetadata::getQuotedIdentifierColumnNames``
* ``ClassMetadata::getIdentifierColumnNames`` => Use ``ClassMetadata::getIdentifierColumns($entityManager)``
* ``ClassMetadata::setVersionMetadata``
* ``ClassMetadata::setVersioned``
* ``ClassMetadata::invokeLifecycleCallbacks``
* ``ClassMetadata::isInheritedField`` => Use ``ClassMetadata::getProperty()::isInherited()``
* ``ClassMetadata::isUniqueField`` => Use ``ClassMetadata::getProperty()::isUnique()``
* ``ClassMetadata::isNullable`` => Use ``ClassMetadata::getProperty()::isNullable()``
* ``ClassMetadata::getTypeOfColumn()`` => Use ``PersisterHelper::getTypeOfColumn()``

## BC Break: Removed ``quoted`` index from table, field and sequence mappings

Quoting is now always called. Implement your own ``Doctrine\ORM\Mapping\NamingStrategy`` to manipulate
your schema, tables and column names to your custom desired naming convention.

## BC Break: Removed ``ClassMetadata::$fieldMappings[$fieldName]['requireSQLConversion']``

ORM Type SQL conversion is now always being applied, minimizing the risks of error prone code in ORM internals

## BC Break: Removed ``ClassMetadata::$columnNames``

If your code relies on this property, you should search/replace from this:

    $metadata->columnNames[$fieldName]

To this:

    $metadata->getProperty($fieldName)->getColumnName()

## BC Break: Renamed ``ClassMetadata::setIdentifierValues()`` to ``ClassMetadata::assignIdentifier()``

Provides a more meaningful name to method.

## BC Break: Removed ``ClassMetadata::$namespace``

The namespace property in ClassMetadata was only used when using association
classes in the same namespace and it was used to speedup ClassMetadata
creation purposes. Namespace could be easily inferred by asking ``\ReflectionClass``
which was already stored internally.

### BC Break: Removed ``ClassMetadata::$isVersioned``

Switched to a method alternative: ``ClassMetadata::isVersioned()``

## BC Break: Removed ``Doctrine\ORM\Mapping\ClassMetadataInfo``

There was no reason to keep a blank class. All references are now pointing
to ``Doctrine\ORM\Mapping\ClassMetadata``.

## BC Break: Annotations classes namespace change

All Annotations classes got moved from ``Doctrine\ORM\Mapping`` into a more
pertinent namespace ``Doctrine\ORM\Annotation``. This change was done to add
room for Metadata namespace refactoring.

## Minor BC break: Mappings now store ``DBAL\Type`` instances instead of strings

This leads to manual ``ResultSetMapping`` building instances to also hold Types in meta results.
Example:

    $rsm->addMetaResult('e ', 'e_discr', 'discr', false, Type::getType('string'));

## Enhancement: Mappings now store their declaring ``ClassMetadata``

Every field, association or embedded now contains a pointer to its declaring ``ClassMetadata``.

## Enhancement: Mappings now store their corresponding table name

Every field, association join column or inline embedded field/association holds a reference to its owning table name.


# Upgrade to 2.6

## Added `Doctrine\ORM\EntityRepository::count()` method

`Doctrine\ORM\EntityRepository::count()` has been added. This new method has different
signature than `Countable::count()` (required parameter) and therefore are not compatible.
If your repository implemented the `Countable` interface, you will have to use
`$repository->count([])` instead and not implement `Countable` interface anymore.

## Minor BC BREAK: `Doctrine\ORM\Tools\Console\ConsoleRunner` is now final

Since it's just an utilitarian class and should not be inherited.

## Minor BC BREAK: removed `Doctrine\ORM\Query\QueryException::associationPathInverseSideNotSupported()`

Method `Doctrine\ORM\Query\QueryException::associationPathInverseSideNotSupported()`
now has a required parameter `$pathExpr`.

## Minor BC BREAK: removed `Doctrine\ORM\Query\Parser#isInternalFunction()`

Method `Doctrine\ORM\Query\Parser#isInternalFunction()` was removed because
the distinction between internal function and user defined DQL was removed.
[#6500](https://github.com/doctrine/orm/pull/6500)

## Minor BC BREAK: removed `Doctrine\ORM\ORMException#overwriteInternalDQLFunctionNotAllowed()`

Method `Doctrine\ORM\Query\Parser#overwriteInternalDQLFunctionNotAllowed()` was
removed because of the choice to allow users to overwrite internal functions, ie
`AVG`, `SUM`, `COUNT`, `MIN` and `MAX`. [#6500](https://github.com/doctrine/orm/pull/6500)

## Minor BC BREAK: removed $className parameter on `AbstractEntityInheritancePersister#getSelectJoinColumnSQL()`

As `$className` parameter was not used in the method, it was safely removed.

## PHP 7.1 is now required

Doctrine 2.6 now requires PHP 7.1 or newer.

As a consequence, automatic cache setup in Doctrine\ORM\Tools\Setup::create*Configuration() was changed:
- APCu extension (ext-apcu) will now be used instead of abandoned APC (ext-apc).
- Memcached extension (ext-memcached) will be used instead of obsolete Memcache (ext-memcache).
- XCache support was dropped as it doesn't work with PHP 7.

# Upgrade to 2.5

## Minor BC BREAK: removed `Doctrine\ORM\Query\SqlWalker#walkCaseExpression()`

Method `Doctrine\ORM\Query\SqlWalker#walkCaseExpression()` was unused and part
of the internal API of the ORM, so it was removed. [#5600](https://github.com/doctrine/orm/pull/5600).

## Minor BC BREAK: query cache key time is now a float

As of 2.5.5, the `QueryCacheEntry#time` property will contain a float value
instead of an integer in order to have more precision and also to be consistent
with the `TimestampCacheEntry#time`.

## Minor BC BREAK: discriminator map must now include all non-transient classes

It is now required that you declare the root of an inheritance in the
discriminator map.

When declaring an inheritance map, it was previously possible to skip the root
of the inheritance in the discriminator map. This was actually a validation
mistake by Doctrine2 and led to problems when trying to persist instances of
that class.

If you don't plan to persist instances some classes in your inheritance, then
either:

 - make those classes `abstract`
 - map those classes as `MappedSuperclass`

## Minor BC BREAK: ``EntityManagerInterface`` instead of ``EntityManager`` in type-hints

As of 2.5, classes requiring the ``EntityManager`` in any method signature will now require
an ``EntityManagerInterface`` instead.
If you are extending any of the following classes, then you need to check following
signatures:

- ``Doctrine\ORM\Tools\DebugUnitOfWorkListener#dumpIdentityMap(EntityManagerInterface $em)``
- ``Doctrine\ORM\Mapping\ClassMetadataFactory#setEntityManager(EntityManagerInterface $em)``

## Minor BC BREAK: Custom Hydrators API change

As of 2.5, `AbstractHydrator` does not enforce the usage of cache as part of
API, and now provides you a clean API for column information through the method
`hydrateColumnInfo($column)`.
Cache variable being passed around by reference is no longer needed since
Hydrators are per query instantiated since Doctrine 2.4.

## Minor BC BREAK: Entity based ``EntityManager#clear()`` calls follow cascade detach

Whenever ``EntityManager#clear()`` method gets called with a given entity class
name, until 2.4, it was only detaching the specific requested entity.
As of 2.5, ``EntityManager`` will follow configured cascades, providing a better
memory management since associations will be garbage collected, optimizing
resources consumption on long running jobs.

## BC BREAK: NamingStrategy interface changes

1. A new method ``embeddedFieldToColumnName($propertyName, $embeddedColumnName)``

This method generates the column name for fields of embedded objects. If you implement your custom NamingStrategy, you
now also need to implement this new method.

2. A change to method ``joinColumnName()`` to include the $className

## Updates on entities scheduled for deletion are no longer processed

In Doctrine 2.4, if you modified properties of an entity scheduled for deletion, UnitOfWork would
produce an UPDATE statement to be executed right before the DELETE statement. The entity in question
was therefore present in ``UnitOfWork#entityUpdates``, which means that ``preUpdate`` and ``postUpdate``
listeners were (quite pointlessly) called. In ``preFlush`` listeners, it used to be possible to undo
the scheduled deletion for updated entities (by calling ``persist()`` if the entity was found in both
``entityUpdates`` and ``entityDeletions``). This does not work any longer, because the entire changeset
calculation logic is optimized away.

## Minor BC BREAK: Default lock mode changed from LockMode::NONE to null in method signatures

A misconception concerning default lock mode values in method signatures lead to unexpected behaviour
in SQL statements on SQL Server. With a default lock mode of ``LockMode::NONE`` throughout the
method signatures in ORM, the table lock hint ``WITH (NOLOCK)`` was appended to all locking related
queries by default. This could result in unpredictable results because an explicit ``WITH (NOLOCK)``
table hint tells SQL Server to run a specific query in transaction isolation level READ UNCOMMITTED
instead of the default READ COMMITTED transaction isolation level.
Therefore there now is a distinction between ``LockMode::NONE`` and ``null`` to be able to tell
Doctrine whether to add table lock hints to queries by intention or not. To achieve this, the following
method signatures have been changed to declare ``$lockMode = null`` instead of ``$lockMode = LockMode::NONE``:

- ``Doctrine\ORM\Cache\Persister\AbstractEntityPersister#getSelectSQL()``
- ``Doctrine\ORM\Cache\Persister\AbstractEntityPersister#load()``
- ``Doctrine\ORM\Cache\Persister\AbstractEntityPersister#refresh()``
- ``Doctrine\ORM\Decorator\EntityManagerDecorator#find()``
- ``Doctrine\ORM\EntityManager#find()``
- ``Doctrine\ORM\EntityRepository#find()``
- ``Doctrine\ORM\Persisters\BasicEntityPersister#getSelectSQL()``
- ``Doctrine\ORM\Persisters\BasicEntityPersister#load()``
- ``Doctrine\ORM\Persisters\BasicEntityPersister#refresh()``
- ``Doctrine\ORM\Persisters\EntityPersister#getSelectSQL()``
- ``Doctrine\ORM\Persisters\EntityPersister#load()``
- ``Doctrine\ORM\Persisters\EntityPersister#refresh()``
- ``Doctrine\ORM\Persisters\JoinedSubclassPersister#getSelectSQL()``

You should update signatures for these methods if you have subclassed one of the above classes.
Please also check the calling code of these methods in your application and update if necessary.

**Note:**
This in fact is really a minor BC BREAK and should not have any affect on database vendors
other than SQL Server because it is the only one that supports and therefore cares about
``LockMode::NONE``. It's really just a FIX for SQL Server environments using ORM.

## Minor BC BREAK: `__clone` method not called anymore when entities are instantiated via metadata API

As of PHP 5.6, instantiation of new entities is deferred to the
[`doctrine/instantiator`](https://github.com/doctrine/instantiator) library, which will avoid calling `__clone`
or any public API on instantiated objects.

## BC BREAK: `Doctrine\ORM\Repository\DefaultRepositoryFactory` is now `final`

Please implement the `Doctrine\ORM\Repository\RepositoryFactory` interface instead of extending
the `Doctrine\ORM\Repository\DefaultRepositoryFactory`.

## BC BREAK: New object expression DQL queries now respects user provided aliasing and not return consumed fields

When executing DQL queries with new object expressions, instead of returning DTOs numerically indexes, it will now respect user provided aliases. Consider the following query:

    SELECT new UserDTO(u.id,u.name) as user,new AddressDTO(a.street,a.postalCode) as address, a.id as addressId FROM User u INNER JOIN u.addresses a WITH a.isPrimary = true

Previously, your result would be similar to this:

    array(
        0=>array(
            0=>{UserDTO object},
            1=>{AddressDTO object},
            2=>{u.id scalar},
            3=>{u.name scalar},
            4=>{a.street scalar},
            5=>{a.postalCode scalar},
            'addressId'=>{a.id scalar},
        ),
        ...
    )

From now on, the resultset will look like this:

    array(
        0=>array(
            'user'=>{UserDTO object},
            'address'=>{AddressDTO object},
            'addressId'=>{a.id scalar}
        ),
        ...
    )

## Minor BC BREAK: added second parameter $indexBy in EntityRepository#createQueryBuilder method signature

Added way to access the underlying QueryBuilder#from() method's 'indexBy' parameter when using EntityRepository#createQueryBuilder()


# Upgrade to 2.4

## BC BREAK: Compatibility Bugfix in PersistentCollection#matching()

In Doctrine 2.3 it was possible to use the new ``matching($criteria)``
functionality by adding constraints for assocations based on ID:

    Criteria::expr()->eq('association', $assocation->getId());

This functionality does not work on InMemory collections however, because
in memory criteria compares object values based on reference.
As of 2.4 the above code will throw an exception. You need to change
offending code to pass the ``$assocation`` reference directly:

    Criteria::expr()->eq('association', $assocation);

## Composer is now the default autoloader

The test suite now runs with composer autoloading. Support for PEAR, and tarball autoloading is deprecated.
Support for GIT submodules is removed.

## OnFlush and PostFlush event always called

Before 2.4 the postFlush and onFlush events were only called when there were
actually entities that changed. Now these events are called no matter if there
are entities in the UoW or changes are found.

## Parenthesis are now considered in arithmetic expression

Before 2.4 parenthesis are not considered in arithmetic primary expression.
That's conceptually wrong, since it might result in wrong values. For example:

The DQL:

    SELECT 100 / ( 2 * 2 ) FROM MyEntity

Before 2.4 it generates the SQL:

    SELECT 100 / 2 * 2 FROM my_entity

Now parenthesis are considered, the previous DQL will generate:

    SELECT 100 / (2 * 2) FROM my_entity


# Upgrade to 2.3

## Auto Discriminator Map breaks userland implementations with Listener

The new feature to detect discriminator maps automatically when none
are provided breaks userland implementations doing this with a
listener in ``loadClassMetadata`` event.

## EntityManager#find() not calls EntityRepository#find() anymore

Previous to 2.3, calling ``EntityManager#find()`` would be delegated to
``EntityRepository#find()``.  This has lead to some unexpected behavior in the
core of Doctrine when people have overwritten the find method in their
repositories. That is why this behavior has been reversed in 2.3, and
``EntityRepository#find()`` calls ``EntityManager#find()`` instead.

## EntityGenerator add*() method generation

When generating an add*() method for a collection the EntityGenerator will now not
use the Type-Hint to get the singular for the collection name, but use the field-name
and strip a trailing "s" character if there is one.

## Merge copies non persisted properties too

When merging an entity in UoW not only mapped properties are copied, but also others.

## Query, QueryBuilder and NativeQuery parameters *BC break*

From now on, parameters in queries is an ArrayCollection instead of a simple array.
This affects heavily the usage of setParameters(), because it will not append anymore
parameters to query, but will actually override the already defined ones.
Whenever you are retrieving a parameter (ie. $query->getParameter(1)), you will
receive an instance of Query\Parameter, which contains the methods "getName",
"getValue" and "getType". Parameters are also only converted to when necessary, and
not when they are set.

Also, related functions were affected:

* execute($parameters, $hydrationMode) the argument $parameters can be either an key=>value array or an ArrayCollection instance
* iterate($parameters, $hydrationMode) the argument $parameters can be either an key=>value array or an ArrayCollection instance
* setParameters($parameters) the argument $parameters can be either an key=>value array or an ArrayCollection instance
* getParameters() now returns ArrayCollection instead of array
* getParameter($key) now returns Parameter instance instead of parameter value

## Query TreeWalker method renamed

Internal changes were made to DQL and SQL generation. If you have implemented your own TreeWalker,
you probably need to update it. The method walkJoinVariableDeclaration is now named walkJoin.

## New methods in TreeWalker interface *BC break*

Two methods getQueryComponents() and setQueryComponent() were added to the TreeWalker interface and all its implementations
including TreeWalkerAdapter, TreeWalkerChain and SqlWalker. If you have your own implementation not inheriting from one of the
above you must implement these new methods.

## Metadata Drivers

Metadata drivers have been rewritten to reuse code from Doctrine\Common. Anyone who is using the
`Doctrine\ORM\Mapping\Driver\Driver` interface should instead refer to
`Doctrine\Common\Persistence\Mapping\Driver\MappingDriver`. Same applies to
`Doctrine\ORM\Mapping\Driver\AbstractFileDriver`: you should now refer to
`Doctrine\Common\Persistence\Mapping\Driver\FileDriver`.

Also, following mapping drivers have been deprecated, please use their replacements in Doctrine\Common as listed:

 *  `Doctrine\ORM\Mapping\Driver\DriverChain`       => `Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain`
 *  `Doctrine\ORM\Mapping\Driver\PHPDriver`         => `Doctrine\Common\Persistence\Mapping\Driver\PHPDriver`
 *  `Doctrine\ORM\Mapping\Driver\StaticPHPDriver`   => `Doctrine\Common\Persistence\Mapping\Driver\StaticPHPDriver`


# Upgrade to 2.2

## ResultCache implementation rewritten

The result cache is completely rewritten and now works on the database result level, not inside the ORM AbstractQuery
anymore. This means that for result cached queries the hydration will now always be performed again, regardless of
the hydration mode. Affected areas are:

1. Fixes the problem that entities coming from the result cache were not registered in the UnitOfWork
   leading to problems during EntityManager#flush. Calls to EntityManager#merge are not necessary anymore.
2. Affects the array hydrator which now includes the overhead of hydration compared to caching the final result.

The API is backwards compatible however most of the getter methods on the `AbstractQuery` object are now
deprecated in favor of calling AbstractQuery#getQueryCacheProfile(). This method returns a `Doctrine\DBAL\Cache\QueryCacheProfile`
instance with access to result cache driver, lifetime and cache key.


## EntityManager#getPartialReference() creates read-only entity

Entities returned from EntityManager#getPartialReference() are now marked as read-only if they
haven't been in the identity map before. This means objects of this kind never lead to changes
in the UnitOfWork.


## Fields omitted in a partial DQL query or a native query are never updated

Fields of an entity that are not returned from a partial DQL Query or native SQL query
will never be updated through an UPDATE statement.


## Removed support for onUpdate in @JoinColumn

The onUpdate foreign key handling makes absolutely no sense in an ORM. Additionally Oracle doesn't even support it. Support for it is removed.


## Changes in Annotation Handling

There have been some changes to the annotation handling in Common 2.2 again, that affect how people with old configurations
from 2.0 have to configure the annotation driver if they don't use `Configuration::newDefaultAnnotationDriver()`:

    // Register the ORM Annotations in the AnnotationRegistry
    AnnotationRegistry::registerFile('path/to/Doctrine/ORM/Mapping/Driver/DoctrineAnnotations.php');

    $reader = new \Doctrine\Common\Annotations\SimpleAnnotationReader();
    $reader->addNamespace('Doctrine\ORM\Mapping');
    $reader = new \Doctrine\Common\Annotations\CachedReader($reader, new ArrayCache());

    $driver = new AnnotationDriver($reader, (array)$paths);

    $config->setMetadataDriverImpl($driver);


## Scalar mappings can now be omitted from DQL result

You are now allowed to mark scalar SELECT expressions as HIDDEN an they are not hydrated anymore.
Example:

SELECT u, SUM(a.id) AS HIDDEN numArticles FROM User u LEFT JOIN u.Articles a ORDER BY numArticles DESC HAVING numArticles > 10

Your result will be a collection of Users, and not an array with key 0 as User object instance and "numArticles" as the number of articles per user


## Map entities as scalars in DQL result

When hydrating to array or even a mixed result in object hydrator, previously you had the 0 index holding you entity instance.
You are now allowed to alias this, providing more flexibility for you code.
Example:

SELECT u AS user FROM User u

Will now return a collection of arrays with index "user" pointing to the User object instance.


## Performance optimizations

Thousands of lines were completely reviewed and optimized for best performance.
Removed redundancy and improved code readability made now internal Doctrine code easier to understand.
Also, Doctrine 2.2 now is around 10-15% faster than 2.1.

## EntityManager#find(null)

Previously EntityManager#find(null) returned null. It now throws an exception.


# Upgrade to 2.1

## Interface for EntityRepository

The EntityRepository now has an interface Doctrine\Common\Persistence\ObjectRepository. This means that your classes that override EntityRepository and extend find(), findOneBy() or findBy() must be adjusted to follow this interface.

## AnnotationReader changes

The annotation reader was heavily refactored between 2.0 and 2.1-RC1. In theory the operation of the new reader should be backwards compatible, but it has to be setup differently to work that way:

    // new call to the AnnotationRegistry
    \Doctrine\Common\Annotations\AnnotationRegistry::registerFile('/doctrine-src/lib/Doctrine/ORM/Mapping/Driver/DoctrineAnnotations.php');

    $reader = new \Doctrine\Common\Annotations\AnnotationReader();
    $reader->setDefaultAnnotationNamespace('Doctrine\ORM\Mapping\\');
    // new code necessary starting here
    $reader->setIgnoreNotImportedAnnotations(true);
    $reader->setEnableParsePhpImports(false);
    $reader = new \Doctrine\Common\Annotations\CachedReader(
        new \Doctrine\Common\Annotations\IndexedReader($reader), new ArrayCache()
    );

This is already done inside the ``$config->newDefaultAnnotationDriver``, so everything should automatically work if you are using this method. You can verify if everything still works by executing a console command such as schema-validate that loads all metadata into memory.


# Update from 2.0-BETA3 to 2.0-BETA4

## XML Driver <change-tracking-policy /> element demoted to attribute

We changed how the XML Driver allows to define the change-tracking-policy. The working case is now:

    <entity change-tracking-policy="DEFERRED_IMPLICT" />


# Update from 2.0-BETA2 to 2.0-BETA3

## Serialization of Uninitialized Proxies

As of Beta3 you can now serialize uninitialized proxies, an exception will only be thrown when
trying to access methods on the unserialized proxy as long as it has not been re-attached to the
EntityManager using `EntityManager#merge()`. See this example:

    $proxy = $em->getReference('User', 1);

    $serializedProxy = serialize($proxy);
    $detachedProxy = unserialized($serializedProxy);

    echo $em->contains($detachedProxy); // FALSE

    try {
        $detachedProxy->getId(); // uninitialized detached proxy
    } catch(Exception $e) {

    }
    $attachedProxy = $em->merge($detachedProxy);
    echo $attackedProxy->getId(); // works!

## Changed SQL implementation of Postgres and Oracle DateTime types

The DBAL Type "datetime" included the Timezone Offset in both Postgres and Oracle. As of this version they are now
generated without Timezone (TIMESTAMP WITHOUT TIME ZONE instead of TIMESTAMP WITH TIME ZONE).
See [this comment to Ticket DBAL-22](http://www.doctrine-project.org/jira/browse/DBAL-22?focusedCommentId=13396&page=com.atlassian.jira.plugin.system.issuetabpanels%3Acomment-tabpanel#action_13396)
for more details as well as migration issues for PostgreSQL and Oracle.

Both Postgres and Oracle will throw Exceptions during hydration of Objects with "DateTime" fields unless migration steps are taken!

## Removed multi-dot/deep-path expressions in DQL

The support for implicit joins in DQL through the multi-dot/Deep Path Expressions
was dropped. For example:

    SELECT u FROM User u WHERE u.group.name = ?1

See the "u.group.id" here is using multi dots (deep expression) to walk
through the graph of objects and properties. Internally the DQL parser
would rewrite these queries to:

    SELECT u FROM User u JOIN u.group g WHERE g.name = ?1

This explicit notation will be the only supported notation as of now. The internal
handling of multi-dots in the DQL Parser was very complex, error prone in edge cases
and required special treatment for several features we added. Additionally
it had edge cases that could not be solved without making the DQL Parser
even much more complex. For this reason we will drop the support for the
deep path expressions to increase maintainability and overall performance
of the DQL parsing process. This will benefit any DQL query being parsed,
even those not using deep path expressions.

Note that the generated SQL of both notations is exactly the same! You
don't loose anything through this.

## Default Allocation Size for Sequences

The default allocation size for sequences has been changed from 10 to 1. This step was made
to not cause confusion with users and also because it is partly some kind of premature optimization.


# Update from 2.0-BETA1 to 2.0-BETA2

There are no backwards incompatible changes in this release.


# Upgrade from 2.0-ALPHA4 to 2.0-BETA1

## EntityRepository deprecates access to protected variables

Instead of accessing protected variables for the EntityManager in
a custom EntityRepository it is now required to use the getter methods
for all the three instance variables:

* `$this->_em` now accessible through `$this->getEntityManager()`
* `$this->_class` now accessible through `$this->getClassMetadata()`
* `$this->_entityName` now accessible through `$this->getEntityName()`

Important: For Beta 2 the protected visibility of these three properties will be
changed to private!

## Console migrated to Symfony Console

The Doctrine CLI has been replaced by Symfony Console Configuration

Instead of having to specify:

    [php]
    $cliConfig = new CliConfiguration();
    $cliConfig->setAttribute('em', $entityManager);

You now have to configure the script like:

    [php]
    $helperSet = new \Symfony\Components\Console\Helper\HelperSet(array(
        'db' => new \Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper($em->getConnection()),
        'em' => new \Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper($em)
    ));

## Console: No need for Mapping Paths anymore

In previous versions you had to specify the --from and --from-path options
to show where your mapping paths are from the console. However this information
is already known from the Mapping Driver configuration, so the requirement
for this options were dropped.

Instead for each console command all the entities are loaded and to
restrict the operation to one or more sub-groups you can use the --filter flag.

## AnnotationDriver is not a default mapping driver anymore

In conjunction with the recent changes to Console we realized that the
annotations driver being a default metadata driver lead to lots of glue
code in the console components to detect where entities lie and how to load
them for batch updates like SchemaTool and other commands. However the
annotations driver being a default driver does not really help that much
anyways.

Therefore we decided to break backwards compatibility in this issue and drop
the support for Annotations as Default Driver and require our users to
specify the driver explicitly (which allows us to ask for the path to all
entities).

If you are using the annotations metadata driver as default driver, you
have to add the following lines to your bootstrap code:

    $driverImpl = $config->newDefaultAnnotationDriver(array(__DIR__."/Entities"));
    $config->setMetadataDriverImpl($driverImpl);

You have to specify the path to your entities as either string of a single
path or array of multiple paths
to your entities. This information will be used by all console commands to
access all entities.

Xml and Yaml Drivers work as before!

## New inversedBy attribute

It is now *mandatory* that the owning side of a bidirectional association specifies the
'inversedBy' attribute that points to the name of the field on the inverse side that completes
the association. Example:

    [php]
    // BEFORE (ALPHA4 AND EARLIER)
    class User
    {
        //...
        /** @OneToOne(targetEntity="Address", mappedBy="user") */
        private $address;
        //...
    }
    class Address
    {
        //...
        /** @OneToOne(targetEntity="User") */
        private $user;
        //...
    }

    // SINCE BETA1
    // User class DOES NOT CHANGE
    class Address
    {
        //...
        /** @OneToOne(targetEntity="User", inversedBy="address") */
        private $user;
        //...
    }

Thus, the inversedBy attribute is the counterpart to the mappedBy attribute. This change
was necessary to enable some simplifications and further performance improvements. We
apologize for the inconvenience.

## Default Property for Field Mappings

The "default" option for database column defaults has been removed. If desired, database column defaults can
be implemented by using the columnDefinition attribute of the @Column annotation (or the appropriate XML and YAML equivalents).
Prefer PHP default values, if possible.

## Selecting Partial Objects

Querying for partial objects now has a new syntax. The old syntax to query for partial objects
now has a different meaning. This is best illustrated by an example. If you previously
had a DQL query like this:

    [sql]
    SELECT u.id, u.name FROM User u

Since BETA1, simple state field path expressions in the select clause are used to select
object fields as plain scalar values (something that was not possible before).
To achieve the same result as previously (that is, a partial object with only id and name populated)
you need to use the following, explicit syntax:

    [sql]
    SELECT PARTIAL u.{id,name} FROM User u

## XML Mapping Driver

The 'inheritance-type' attribute changed to take last bit of ClassMetadata constant names, i.e.
NONE, SINGLE_TABLE, JOINED

## YAML Mapping Driver

The way to specify lifecycle callbacks in YAML Mapping driver was changed to allow for multiple callbacks
per event. The Old syntax ways:

    [yaml]
    lifecycleCallbacks:
      doStuffOnPrePersist: prePersist
      doStuffOnPostPersist: postPersist

The new syntax is:

    [yaml]
    lifecycleCallbacks:
      prePersist: [ doStuffOnPrePersist, doOtherStuffOnPrePersistToo ]
      postPersist: [ doStuffOnPostPersist ]

## PreUpdate Event Listeners

Event Listeners listening to the 'preUpdate' event can only affect the primitive values of entity changesets
by using the API on the `PreUpdateEventArgs` instance passed to the preUpdate listener method. Any changes
to the state of the entitys properties won't affect the database UPDATE statement anymore. This gives drastic
performance benefits for the preUpdate event.

## Collection API

The Collection interface in the Common package has been updated with some missing methods
that were present only on the default implementation, ArrayCollection. Custom collection
implementations need to be updated to adhere to the updated interface.


# Upgrade from 2.0-ALPHA3 to 2.0-ALPHA4

## CLI Controller changes

CLI main object changed its name and namespace. Renamed from Doctrine\ORM\Tools\Cli to Doctrine\Common\Cli\CliController.
Doctrine\Common\Cli\CliController now only deals with namespaces. Ready to go, Core, Dbal and Orm are available and you can subscribe new tasks by retrieving the namespace and including new task. Example:

    [php]
    $cli->getNamespace('Core')->addTask('my-example', '\MyProject\Tools\Cli\Tasks\MyExampleTask');


## CLI Tasks documentation

Tasks have implemented a new way to build documentation. Although it is still possible to define the help manually by extending the basicHelp and extendedHelp, they are now optional.
With new required method AbstractTask::buildDocumentation, its implementation defines the TaskDocumentation instance (accessible through AbstractTask::getDocumentation()), basicHelp and extendedHelp are now not necessary to be implemented.

## Changes in Method Signatures

    * A bunch of Methods on both Doctrine\DBAL\Platforms\AbstractPlatform and Doctrine\DBAL\Schema\AbstractSchemaManager
      have changed quite significantly by adopting the new Schema instance objects.

## Renamed Methods

    * Doctrine\ORM\AbstractQuery::setExpireResultCache() -> expireResultCache()
    * Doctrine\ORM\Query::setExpireQueryCache() -> expireQueryCache()

## SchemaTool Changes

    * "doctrine schema-tool --drop" now always drops the complete database instead of
    only those tables defined by the current database model. The previous method had
    problems when foreign keys of orphaned tables pointed to tables that were scheduled
    for deletion.
    * Use "doctrine schema-tool --update" to get a save incremental update for your
    database schema without deleting any unused tables, sequences or foreign keys.
    * Use "doctrine schema-tool --complete-update" to do a full incremental update of
    your schema.


# Upgrade from 2.0-ALPHA2 to 2.0-ALPHA3

This section details the changes made to Doctrine 2.0-ALPHA3 to make it easier for you
to upgrade your projects to use this version.

## CLI Changes

The $args variable used in the cli-config.php for configuring the Doctrine CLI has been renamed to $globalArguments.

## Proxy class changes

You are now required to make supply some minimalist configuration with regards to proxy objects. That involves 2 new configuration options. First, the directory where generated proxy classes should be placed needs to be specified. Secondly, you need to configure the namespace used for proxy classes. The following snippet shows an example:

    [php]
    // step 1: configure directory for proxy classes
    // $config instanceof Doctrine\ORM\Configuration
    $config->setProxyDir('/path/to/myproject/lib/MyProject/Generated/Proxies');
    $config->setProxyNamespace('MyProject\Generated\Proxies');

Note that proxy classes behave exactly like any other classes when it comes to class loading. Therefore you need to make sure the proxy classes can be loaded by some class loader. If you place the generated proxy classes in a namespace and directory under your projects class files, like in the example above, it would be sufficient to register the MyProject namespace on a class loader. Since the proxy classes are contained in that namespace and adhere to the standards for class loading, no additional work is required.
Generating the proxy classes into a namespace within your class library is the recommended setup.

Entities with initialized proxy objects can now be serialized and unserialized properly from within the same application.

For more details refer to the Configuration section of the manual.

## Removed allowPartialObjects configuration option

The allowPartialObjects configuration option together with the `Configuration#getAllowPartialObjects` and `Configuration#setAllowPartialObjects` methods have been removed.
The new behavior is as if the option were set to FALSE all the time, basically disallowing partial objects globally. However, you can still use the `Query::HINT_FORCE_PARTIAL_LOAD` query hint to force a query to return partial objects for optimization purposes.

## Renamed Methods

* Doctrine\ORM\Configuration#getCacheDir() to getProxyDir()
* Doctrine\ORM\Configuration#setCacheDir($dir) to setProxyDir($dir)
