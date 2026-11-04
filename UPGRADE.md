Note about upgrading: Doctrine uses static and runtime mechanisms to raise
awareness about deprecated code.

- Use of `@deprecated` docblock that is detected by IDEs (like PHPStorm) or
  Static Analysis tools (like Psalm, phpstan)
- Use of our low-overhead runtime deprecation API, details:
  https://github.com/doctrine/deprecations/

# Upgrade to 3.x General Notes

We recommend you upgrade to DBAL 3 first before upgrading to ORM 3. See
the DBAL upgrade docs: https://github.com/doctrine/dbal/blob/3.10.x/UPGRADE.md

Rather than doing several major upgrades at once, we recommend you do the following:

- upgrade to DBAL 3
- deploy and monitor
- upgrade to ORM 3
- deploy and monitor
- upgrade to DBAL 4
- deploy and monitor

If you are using Symfony, the recommended minimal Doctrine Bundle version is 2.15
to run with ORM 3.

At this point, we recommend upgrading to PHP 8.4 first and then directly from
ORM 2.19 to 3.5 and up so that you can skip the lazy ghost proxy generation
and directly start using native lazy objects.

# Upgrade to 3.8

## `OffsetPaginator` now preserves the result keys of DQL `INDEX BY`

`WindowPage::getItems()` and the iteration of a `WindowPage` used to apply
`array_values()`, so a DQL `INDEX BY` clause had no effect on the keys and the
items were always indexed from 0. They now keep the keys, as iterating the
deprecated `Paginator` did.

Queries without `INDEX BY` are unaffected: their keys remain `0..n-1`.
`CursorPage::getItems()` still returns a list.

If your code relied on a 0-based list, apply `array_values()` yourself:

```diff
-$items = $page->getItems();
-$first = $items[0];
+$items = array_values($page->getItems());
+$first = $items[0];
```

```diff
-foreach ($page as $index => $item) {
+foreach (array_values($page->getItems()) as $index => $item) {
     // ...
 }
```

The same applies when you destructure the result with `list()` or pass it to a
parameter typed as a list. When you use `iterator_to_array()`, pass `false` as
the second argument to reindex the keys.

# Upgrade to 3.7

## Deprecated `Doctrine\ORM\Mapping\JoinColumns` attribute

Using it has no effect, it should have been removed in 3.0.0. Instead, use the
`Doctrine\ORM\Mapping\JoinColumn` attribute multiple times.

## `LockMode::NONE` is now a no-op

`LockMode::NONE` means "no lock", but it used to be handled like a pessimistic
lock mode in two places:

- `EntityManager::find()` refreshed an entity that was already in the identity
  map, discarding any in-memory changes.
- `EntityManager::lock()` required an active transaction and ran a `SELECT`
  statement without any lock hint.

Both now do nothing, which is consistent with `EntityManager::refresh()` and
`Query::setLockMode()`. If you relied on `find()` reloading the entity, call
`EntityManager::refresh()` explicitly.

## Deprecated passing `null` as lock mode

Since `LockMode::NONE` is now a no-op, passing `null` as lock mode to
`EntityManager::find()`, `EntityManager::refresh()` or `EntityRepository::find()`
is deprecated as it is equivalent to passing `LockMode::NONE`. Omit the argument
or pass `LockMode::NONE` instead.

## Deprecated setting entity identifiers to `null` after `remove()`

Leaving `Configuration::isOnRemoveEntitySetIdentifierNull()` at its default of
`true` is deprecated. When a removed entity is flushed, `UnitOfWork` currently
sets that entity's identifier property back to `null` as a convention for
"this entity object no longer represents a persisted row."

This convention conflicts with PHP 8.4+ `readonly` properties and property
hooks, which often cannot be reassigned after construction. In ORM 4.0, the
identifier will never be nulled after removal.

To opt into the new behavior now:

    $configuration->setOnRemoveEntitySetIdentifierNull(false);

Entities with `readonly` identifier properties are already exempt from the
nulling behavior regardless of this flag.

## Deprecated `Doctrine\ORM\Tools\Pagination\Paginator`

Use `Doctrine\ORM\Tools\Pagination\OffsetPaginator` instead. The first result and
the maximum number of results are no longer read implicitly from the query: they
are passed together as a `Window` value object to `paginate()`, which returns an
immutable, iterable `WindowPage`.

```diff
-use Doctrine\ORM\Tools\Pagination\Paginator;
+use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
+use Doctrine\ORM\Tools\Pagination\Window;

-$query = $entityManager->createQuery($dql)
-    ->setFirstResult(0)
-    ->setMaxResults(25);
+$query = $entityManager->createQuery($dql);

-$paginator = new Paginator($query, fetchJoinCollection: true);
+$page = (new OffsetPaginator(fetchJoinCollection: true))
+    ->paginate($query, new Window(0, 25));

-$total = count($paginator);
+$total = $page->getTotalCount();

-foreach ($paginator as $post) {
+foreach ($page as $post) {
     // ...
 }
```

When only the total is needed, `OffsetPaginator::getTotalCount()` runs the count
query without fetching any row:

```diff
-$total = count(new Paginator($query, fetchJoinCollection: true));
+$total = (new OffsetPaginator(fetchJoinCollection: true))->getTotalCount($query);
```

`Paginator::setUseOutputWalkers()` becomes the `useOutputWalkers` constructor
argument of `OffsetPaginator`.

## Deprecated `Paginator::HINT_ENABLE_DISTINCT`

The query hint is now declared as
`Doctrine\ORM\Tools\Pagination\PaginatorInterface::HINT_ENABLE_DISTINCT`, and applies
to both `OffsetPaginator` and `CursorPaginator`. Its value is unchanged, so hints set
as a raw string keep working.

```diff
-use Doctrine\ORM\Tools\Pagination\Paginator;
+use Doctrine\ORM\Tools\Pagination\PaginatorInterface;

-$query->setHint(Paginator::HINT_ENABLE_DISTINCT, false);
+$query->setHint(PaginatorInterface::HINT_ENABLE_DISTINCT, false);
```

## Deprecated `Doctrine\ORM\Query\AST\TypedExpression`

Implement `Doctrine\ORM\Query\AST\ExpressionWithReturnType` instead, which exposes
the DBAL type name directly.

```diff
-use Doctrine\DBAL\Types\Type;
 use Doctrine\DBAL\Types\Types;
-use Doctrine\ORM\Query\AST\TypedExpression;
+use Doctrine\ORM\Query\AST\ExpressionWithReturnType;

-class MyFunction extends FunctionNode implements TypedExpression
+class MyFunction extends FunctionNode implements ExpressionWithReturnType
 {
-    public function getReturnType(): Type
+    public function getReturnTypeName(): string
     {
-        return Type::getType(Types::INTEGER);
+        return Types::INTEGER;
     }
 }
```

Libraries that need to support older ORM versions can safely implement both
interfaces at the same time: `SqlWalker` prefers `ExpressionWithReturnType`
when available and no deprecation is triggered.

## Deprecated using strings or null as sort directions

PHP 8.6 provides a native `\SortDirection` enum that should be used instead of
strings such as `'ASC'` and `'DESC'` or instead of `null` to indicate no sorting.

`\SortDirection` is polyfilled by the `symfony/polyfill-php86` package, that we
require.

This applies to the following methods:

- `Doctrine\ORM\QueryBuilder::addOrderBy()`
- `Doctrine\ORM\QueryBuilder::orderBy()`
- `Doctrine\ORM\Query\Expr\OrderBy::__construct()`
- `Doctrine\ORM\Query\Expr\OrderBy::add()`

This deprecation does NOT apply to `EntityRepository::findBy()` and
`findOneBy()`: since `doctrine/persistence` 3.x and 4.x document strings as
the only order values in `ObjectRepository`, these methods keep accepting
strings (`'ASC'` / `'DESC'`, case-insensitive) without deprecation. Both
strings and `\SortDirection` are accepted; static analysis only allows
`\SortDirection` when the repository is typed as `EntityRepository`, not as
`Doctrine\Persistence\ObjectRepository`. `doctrine/persistence` 5.0 will
also accept `\SortDirection` in `ObjectRepository`, at which point the enum
form will be statically valid regardless of how the repository is typed.
Sort directions given to `matching()` / `Criteria` are also not deprecated
(handled by `doctrine/collections`).

```diff
-$qb->orderBy('u.name', 'ASC')
-   ->addOrderBy('u.createdAt', 'DESC');
+$qb->orderBy('u.name', \SortDirection::Ascending)
+   ->addOrderBy('u.createdAt', \SortDirection::Descending);
-$qb->orderBy(new Expr\OrderBy('u.name', 'ASC'))
-   ->addOrderBy(new Expr\OrderBy('u.createdAt', 'DESC'));
+$qb->orderBy(new Expr\OrderBy('u.name', \SortDirection::Ascending))
+   ->addOrderBy(new Expr\OrderBy('u.createdAt', \SortDirection::Descending));
-$expr = new Expr\OrderBy('u.name', 'ASC');
-$expr->add('u.createdAt', 'DESC');
+$expr = new Expr\OrderBy('u.name', \SortDirection::Ascending)
+$expr->add('u.createdAt', \SortDirection::Descending);
```

This also applies to mapping when using either the attribute driver or the
static PHP driver:

```diff
 use Doctrine\ORM\Mapping as ORM;
 use SortDirection;

 #[ORM\Entity]
 class UserWithStringOrderBy
 {
     #[ORM\Id]
     #[ORM\Column]
     #[ORM\GeneratedValue(strategy: 'AUTO')]
     public int|null $id = null;

     /** @var Collection<int, Phonenumber> */
     #[ORM\OneToMany(targetEntity: Phonenumber::class, mappedBy: 'user')]
-    #[ORM\OrderBy(['number' => 'ASC'])]
+    #[ORM\OrderBy(['number' => SortDirection::Ascending])]
     public $phonenumbers;

     /** @var Collection<int, Group> */
     #[ORM\ManyToMany(targetEntity: Group::class)]
-    #[ORM\OrderBy(['name' => 'ASC', 'id' => 'DESC'])]
+    #[ORM\OrderBy(['name' => SortDirection::Ascending, 'id' => SortDirection::Descending])]
     public $groupsMixed;

     public static function loadMetadata(ClassMetadata $metadata): void
     {
         $metadata->mapField(
             [
                 'id'                 => true,
                 'fieldName'          => 'id',
                 'type'               => 'integer',
             ],
         );

         $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);

         $metadata->mapOneToMany(
             [
                 'fieldName' => 'phonenumbersAsc',
                 'mappedBy' => 'user',
                 'targetEntity' => Phonenumber::class,
-                'orderBy' => ['number' => 'ASC'],
+                'orderBy' => ['number' => SortDirection::Ascending],
             ],
         );

         $metadata->mapManyToMany(
             [
                 'fieldName' => 'groupsMixed',
                 'targetEntity' => Group::class,
-                'orderBy' => ['name' => 'ASC', 'id' => 'DESC'],
+                'orderBy' => ['name' => SortDirection::Ascending, 'id' => SortDirection::Descending],
             ],
         );
     }
 }
```

Likewise, using the class metadata builder API with string sort directions is deprecated:

```diff
 use CmsGroup;
 use SortDirection;

 $this->builder->createOneToMany('groups', CmsGroup::class)
             ->mappedBy('test')
-            ->setOrderBy(['test' => 'ASC'])
+            ->setOrderBy(['test' => SortDirection::Ascending])
             ->build();

 $this->builder->createManyToMany('other_groups', CmsGroup::class)
-            ->setOrderBy(['test' => 'ASC'])
+            ->setOrderBy(['test' => SortDirection::Ascending])
             ->build();
```

### Breaking changes

We could not find a way to allow the above without introducing a breaking change
for extending classes. If you extend the querybuilder and override any of the
above methods, you will need to update the method signature to add support for
`\SortDirection` as well. Same goes for `Expr\OrderBy::add()`.

`EntityRepository::findBy()` and `findOneBy()` keep their native signature
(`array|null $orderBy`), but their documented `$orderBy` type is widened to
also accept `\SortDirection`. If you extend `EntityRepository` and override
`findBy()` or `findOneBy()`, you will need to update the method docblock to
add support for `\SortDirection` as well. The `ObjectRepository` interface
is unchanged.

## Conditional breaking changes

3.7 adds support for `doctrine/collections` 3. If you upgrade to that version
of `doctrine/collections`, there are breaking changes in `doctrine/orm` as well,
because of cross-package inheritance and type declarations.

Most notably, `Doctrine\ORM\PersistentCollection::add` no longer returns a boolean:

```diff
- public function add(mixed $value): bool
+ public function add(mixed $value): void
```

That method always returned `true`, so you can safely stop using the return
value before upgrading.

Also, if you extend `Doctrine\ORM\Persisters\SqlValueVisitor`, you need to
ensure the following methods have a return type in your subclasses:

- `walkComparison()`
- `walkCompositeExpression()`
- `walkValue()`

## Deprecate `EventManager` return type in `EntityManager` methods

The return type of the following methods has been changed from
`Doctrine\Common\EventManager` to `Doctrine\Common\EventManagerInterface`:

- `Doctrine\ORM\Decorator\EntityManagerDecorator::getEventManager()`
- `Doctrine\ORM\EntityManager::getEventManager()`
- `Doctrine\ORM\EntityManagerInterface::getEventManager()`

All three methods continue to return an instance of `EventManager`, however
relying on that is deprecated and will no longer be the guaranteed in 4.0.

# Upgrade to 3.6

## Deprecate using string expression for default values in mappings

Using a string expression for default values in field mappings is deprecated.
Use `Doctrine\DBAL\Schema\DefaultExpression` instances instead.

Here is how to address this deprecation when mapping entities using PHP attributes:

```diff
 use DateTime;
+use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
+use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
+use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
 use Doctrine\ORM\Mapping as ORM;

 #[ORM\Entity]
 final class TimeEntity
 {
     #[ORM\Id]
     #[ORM\Column]
     public int $id;

-    #[ORM\Column(options: ['default' => 'CURRENT_TIMESTAMP'], insertable: false, updatable: false)]
+    #[ORM\Column(options: ['default' => new CurrentTimestamp()], insertable: false, updatable: false)]
     public DateTime $createdAt;

-    #[ORM\Column(options: ['default' => 'CURRENT_TIME'], insertable: false, updatable: false)]
+    #[ORM\Column(options: ['default' => new CurrentTime()], insertable: false, updatable: false)]
     public DateTime $createdTime;

-    #[ORM\Column(options: ['default' => 'CURRENT_DATE'], insertable: false, updatable: false)]
+    #[ORM\Column(options: ['default' => new CurrentDate()], insertable: false, updatable: false)]
     public DateTime $createdDate;
 }
```

Here is how to do the same when mapping entities using XML:

```diff
 <?xml version="1.0" encoding="UTF-8"?>

 <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                   xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                   xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                           https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

     <entity name="Doctrine\Tests\ORM\Functional\XmlTimeEntity">
         <id name="id" type="integer" column="id">
             <generator strategy="AUTO"/>
         </id>

         <field name="createdAt" type="datetime" insertable="false" updatable="false">
             <options>
-                <option name="default">CURRENT_TIMESTAMP</option>
+                <option name="default">
+                    <object class="Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp"/>
+                </option>
             </options>
         </field>

         <field name="createdAtImmutable" type="datetime_immutable" insertable="false" updatable="false">
             <options>
-                <option name="default">CURRENT_TIMESTAMP</option>
+                <option name="default">
+                    <object class="Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp"/>
+                </option>
             </options>
         </field>

         <field name="createdTime" type="time" insertable="false" updatable="false">
             <options>
-                <option name="default">CURRENT_TIME</option>
+                <option name="default">
+                    <object class="Doctrine\DBAL\Schema\DefaultExpression\CurrentTime"/>
+                </option>
             </options>
         </field>
         <field name="createdDate" type="date" insertable="false" updatable="false">
             <options>
-                <option name="default">CURRENT_DATE</option>
+                <option name="default">
+                    <object class="Doctrine\DBAL\Schema\DefaultExpression\CurrentDate"/>
+                </option>
             </options>
         </field>
     </entity>
 </doctrine-mapping>
```


## Deprecate `FieldMapping::$default`

The `default` property of `Doctrine\ORM\Mapping\FieldMapping` is deprecated and
will be removed in 4.0. Instead, use `FieldMapping::$options['default']`.

## Deprecate specifying `nullable` on columns that end up being used in a primary key

Specifying `nullable` on join columns that are part of a primary key is
deprecated and will be an error in 4.0.

This can happen when using a join column mapping together with an id mapping,
or when using a join column mapping or an inverse join column mapping on a
many-to-many relationship.

```diff
 class User
 {
     #[ORM\Id]
     #[ORM\Column(type: 'integer')]
     private int $id;

     #[ORM\Id]
     #[ORM\ManyToOne(targetEntity: Family::class, inversedBy: 'users')]
-    #[ORM\JoinColumn(name: 'family_id', referencedColumnName: 'id', nullable: true)]
+    #[ORM\JoinColumn(name: 'family_id', referencedColumnName: 'id')]
     private ?Family $family;

     #[ORM\ManyToMany(targetEntity: Group::class)]
     #[ORM\JoinTable(name: 'user_group')]
-    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
-    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: true)]
+    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
+    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id')]
     private Collection $groups;
 }
```

## Deprecate `Doctrine\ORM\QueryBuilder::add('join', ...)` with a list of join parts

Using `Doctrine\ORM\QueryBuilder::add('join', ...)` with a list of join parts
is deprecated in favor of using an associative array of join parts with the
root alias as key.

## Deprecate using the `WITH` keyword for arbitrary DQL joins

Using the `WITH` keyword to specify the condition for an arbitrary DQL join is
deprecated in favor of using the `ON` keyword (similar to the SQL syntax for
joins).
The `WITH` keyword is now meant to be used only for filtering conditions in
association joins.

# Upgrade to 3.5

See the General notes to upgrading to 3.x versions above.

## Deprecate not using native lazy objects on PHP 8.4+

Having native lazy objects disabled on PHP 8.4+ is deprecated and will not be
possible in 4.0.

You can enable them through configuration:

```php
$config->enableNativeLazyObjects(true);
```

As a consequence, methods, parameters and commands related to userland lazy
objects have been deprecated on PHP 8.4+:

- `Doctrine\ORM\Tools\Console\Command\GenerateProxiesCommand`
- `Doctrine\ORM\Configuration::getAutoGenerateProxyClasses()`
- `Doctrine\ORM\Configuration::getProxyDir()`
- `Doctrine\ORM\Configuration::getProxyNamespace()`
- `Doctrine\ORM\Configuration::setAutoGenerateProxyClasses()`
- `Doctrine\ORM\Configuration::setProxyDir()`
- `Doctrine\ORM\Configuration::setProxyNamespace()`
- Passing more than one argument to `Doctrine\ORM\Proxy\ProxyFactory::__construct()`

Additionally, some methods of ORMSetup have been deprecated in favor of a new
counterpart.

- `Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration()` is deprecated in favor of
  `Doctrine\ORM\ORMSetup::createAttributeMetadataConfig()`
- `Doctrine\ORM\ORMSetup::createXMLMetadataConfiguration()` is deprecated in favor of
  `Doctrine\ORM\ORMSetup::createXMLMetadataConfig()`
- `Doctrine\ORM\ORMSetup::createConfiguration()` is deprecated in favor of
  `Doctrine\ORM\ORMSetup::createConfig()`

## Deprecate methods for configuring no longer configurable features

Since 3.0, lazy ghosts are enabled unconditionally, and so is rejecting ID
collisions in the identity map.

As a consequence, the following methods are deprecated and will be removed in 4.0:
* `Doctrine\ORM\Configuration::setLazyGhostObjectEnabled()`
* `Doctrine\ORM\Configuration::isLazyGhostObjectEnabled()`
* `Doctrine\ORM\Configuration::setRejectIdCollisionInIdentityMap()`
* `Doctrine\ORM\Configuration::isRejectIdCollisionInIdentityMapEnabled()`

# Upgrade to 3.4.1

## BC BREAK: You can no longer use the `.*` notation to get all fields of an entity in a DTO

This feature was introduced in 3.4.0, and introduces several issues, so we
decide to remove it before it is used too widely.

# Upgrade to 3.4

See the General notes to upgrading to 3.x versions above.

## Discriminator Map class duplicates

Using the same class several times in a discriminator map is deprecated.
In 4.0, this will be an error.

## `Doctrine\ORM\Mapping\ClassMetadata::$reflFields` deprecated

To better support property hooks and lazy proxies in the future, `$reflFields` had to
be deprecated because we cannot use the PHP internal reflection API directly anymore.

The property was changed from an array to an object of type `LegacyReflectionFields`
that implements `ArrayAccess`.

Use the new `Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessor` API and access
through `Doctrine\ORM\Mapping\ClassMetadata::$propertyAccessors` instead.

Companion accessor methods are deprecated as well.

# Upgrade to 3.3

See the General notes to upgrading to 3.x versions above.

## Deprecate `DatabaseDriver`

The class `Doctrine\ORM\Mapping\Driver\DatabaseDriver` is deprecated without replacement.

## Add `Doctrine\ORM\Query\OutputWalker` interface, deprecate `Doctrine\ORM\Query\SqlWalker::getExecutor()`

Output walkers should implement the new `\Doctrine\ORM\Query\OutputWalker` interface and create
`Doctrine\ORM\Query\Exec\SqlFinalizer` instances instead of `Doctrine\ORM\Query\Exec\AbstractSqlExecutor`s.
The output walker must not base its workings on the query `firstResult`/`maxResult` values, so that the
`SqlFinalizer` can be kept in the query cache and used regardless of the actual `firstResult`/`maxResult` values.
Any operation dependent on `firstResult`/`maxResult` should take place within the `SqlFinalizer::createExecutor()`
method. Details can be found at https://github.com/doctrine/orm/pull/11188.


# Upgrade to 3.2

See the General notes to upgrading to 3.x versions above.

## Deprecate the `NotSupported` exception

The class `Doctrine\ORM\Exception\NotSupported` is deprecated without replacement.

## Deprecate remaining `Serializable` implementation

Relying on `SequenceGenerator` implementing the `Serializable` is deprecated
because that interface won't be implemented in ORM 4 anymore.

The following methods are deprecated:

* `SequenceGenerator::serialize()`
* `SequenceGenerator::unserialize()`

## `orm:schema-tool:update` option `--complete` is deprecated

That option behaves as a no-op, and is deprecated. It will be removed in 4.0.

## Deprecate properties `$indexes` and `$uniqueConstraints` of `Doctrine\ORM\Mapping\Table`

The properties `$indexes` and `$uniqueConstraints` have been deprecated since they had no effect at all.
The preferred way of defining indices and unique constraints is by
using the `\Doctrine\ORM\Mapping\UniqueConstraint` and `\Doctrine\ORM\Mapping\Index` attributes.

# Upgrade to 3.1

See the General notes to upgrading to 3.x versions above.

## Deprecate `Doctrine\ORM\Mapping\ReflectionEnumProperty`

This class is deprecated and will be removed in 4.0.
Instead, use `Doctrine\Persistence\Reflection\EnumReflectionProperty` from
`doctrine/persistence`.

## Deprecate passing null to `ClassMetadata::fullyQualifiedClassName()`

Passing `null` to `Doctrine\ORM\ClassMetadata::fullyQualifiedClassName()` is
deprecated and will no longer be possible in 4.0.

## Deprecate array access

Using array access on instances of the following classes is deprecated:

- `Doctrine\ORM\Mapping\DiscriminatorColumnMapping`
- `Doctrine\ORM\Mapping\EmbedClassMapping`
- `Doctrine\ORM\Mapping\FieldMapping`
- `Doctrine\ORM\Mapping\JoinColumnMapping`
- `Doctrine\ORM\Mapping\JoinTableMapping`

# Upgrade to 3.0

See the General notes to upgrading to 3.x versions above.

## BC BREAK: Calling `ClassMetadata::getAssociationMappedByTargetField()` with the owning side of an association now throws an exception

Previously, calling
`Doctrine\ORM\Mapping\ClassMetadata::getAssociationMappedByTargetField()` with
the owning side of an association returned `null`, which was undocumented, and
wrong according to the phpdoc of the parent method.

If you do not know whether you are on the owning or inverse side of an association,
you can use  `Doctrine\ORM\Mapping\ClassMetadata::isAssociationInverseSide()`
to find out.

## BC BREAK: `Doctrine\ORM\Proxy\Autoloader` no longer extends `Doctrine\Common\Proxy\Autoloader`

Make sure to use the former when writing a type declaration or an `instanceof` check.

## Minor BC BREAK: Changed order of arguments passed to `OneToOne`, `ManyToOne` and `Index` mapping PHP attributes

To keep PHP mapping attributes consistent, order of arguments passed to above attributes has been changed
so `$targetEntity` is a first argument now. This change affects only non-named arguments usage.

## BC BREAK: AUTO keyword for identity generation defaults to IDENTITY for PostgreSQL when using `doctrine/dbal` 4

When using the `AUTO` strategy to let Doctrine determine the identity generation mechanism for
an entity, and when using `doctrine/dbal` 4, PostgreSQL now uses `IDENTITY`
instead of `SEQUENCE` or `SERIAL`.

There are three ways to handle this change.

* If you want to upgrade your existing tables to identity columns, you will need to follow [migration to identity columns on PostgreSQL](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.0/how-to/postgresql-identity-migration.html)
* If you want to keep using SQL sequences, you need to configure the ORM this way:
```php
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadata;

assert($configuration instanceof Configuration);
$configuration->setIdentityGenerationPreferences([
    PostgreSQLPlatform::CLASS => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
]);
```
* You can change individual entities to use the `SEQUENCE` strategy instead of `AUTO`:
```php

diff --git a/src/Entity/Example.php b/src/Entity/Example.php
index 28be8df378..3b7d61bda6 100644
--- a/src/Entity/Example.php
+++ b/src/Entity/Example.php
@@ -38,7 +38,7 @@ class Example

     #[ORM\Id]
     #[ORM\Column(type: 'integer')]
-    #[ORM\GeneratedValue(strategy: 'AUTO')]
+    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
     private int $id;

     #[Assert\Length(max: 255)]
```
The later two options require a small database migration that will remove the default
expression fetching the next value from the sequence. It's not strictly necessary to
do this migration because the code will work anyway. A benefit of this approach is
that you can just make and roll out the code changes first and then migrate the database later.

## BC BREAK: Throw exceptions when using illegal attributes on Embeddable

There are only a few attributes allowed on an embeddable such as `#[Column]` or
`#[Embedded]`. Previously all others that target entity classes where ignored,
now they throw an exception.

## BC BREAK: Partial objects are removed

WARNING: This was relaxed in ORM 3.2 when partial was re-allowed for array-hydration.

- The `PARTIAL` keyword in DQL no longer exists (reintroduced in ORM 3.2)
- `Doctrine\ORM\Query\AST\PartialObjectExpression` is removed. (reintroduced in ORM 3.2)
- `Doctrine\ORM\Query\SqlWalker::HINT_PARTIAL` (reintroduced in ORM 3.2) and
  `Doctrine\ORM\Query::HINT_FORCE_PARTIAL_LOAD` are removed.
- `Doctrine\ORM\EntityManager*::getPartialReference()` is removed.

## BC BREAK: Enforce ArrayCollection Type on `\Doctrine\ORM\QueryBuilder::setParameters(ArrayCollection $parameters)`

The argument $parameters can no longer be a key=>value array. Only ArrayCollection types are allowed.

### Before

```php
$qb = $em->createQueryBuilder()
    ->select('u')
    ->from('User', 'u')
    ->where('u.id = :user_id1 OR u.id = :user_id2')
    ->setParameters(array(
        'user_id1' => 1,
        'user_id2' => 2
    ));
```

### After

```php
$qb = $em->createQueryBuilder()
    ->select('u')
    ->from('User', 'u')
    ->where('u.id = :user_id1 OR u.id = :user_id2')
    ->setParameters(new ArrayCollection(array(
        new Parameter('user_id1', 1),
        new Parameter('user_id2', 2)
    )));
```

## BC BREAK: `Doctrine\ORM\Persister\Entity\EntityPersister::executeInserts()` return type changed to `void`

Implementors should adapt to the new signature, and should call
`UnitOfWork::assignPostInsertId()` for each entry in the previously returned
array.

## BC BREAK: `Doctrine\ORM\Proxy\ProxyFactory` no longer extends abstract factory from `doctrine/common`

It is no longer possible to call methods, constants or properties inherited
from that class on a `ProxyFactory` instance.

`Doctrine\ORM\Proxy\ProxyFactory::createProxyDefinition()` and
`Doctrine\ORM\Proxy\ProxyFactory::resetUninitializedProxy()` are removed as well.

## BC BREAK: lazy ghosts are enabled unconditionally

`Doctrine\ORM\Configuration::setLazyGhostObjectEnabled()` and
`Doctrine\ORM\Configuration::isLazyGhostObjectEnabled()` are now no-ops and
will be deprecated in 3.1.0

## BC BREAK: collisions in identity map are unconditionally rejected

`Doctrine\ORM\Configuration::setRejectIdCollisionInIdentityMap()` and
`Doctrine\ORM\Configuration::isRejectIdCollisionInIdentityMapEnabled()` are now
no-ops and will be deprecated in 3.1.0.

## BC BREAK: Lifecycle callback mapping on embedded classes is now explicitly forbidden

Lifecycle callback mapping on embedded classes produced no effect, and is now
explicitly forbidden to point out mistakes.

## BC BREAK: The `NOTIFY` change tracking policy is removed

You should use `DEFERRED_EXPLICIT` instead.

## BC BREAK: `Mapping\Driver\XmlDriver::__construct()` third argument is now enabled by default

The third argument to
`Doctrine\ORM\Mapping\Driver\XmlDriver::__construct()` was introduced to
let users opt-in to XML validation, that is now always enabled by default.

As a consequence, the same goes for
`Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver`, and for
`Doctrine\ORM\ORMSetup::createXMLMetadataConfiguration()`.

## BC BREAK: `Mapping\Driver\AttributeDriver::__construct()` second argument is now a no-op

The second argument to
`Doctrine\ORM\Mapping\Driver\AttributeDriver::__construct()` was introduced to
let users opt-in to a new behavior, that is now always enforced, regardless of
the value of that argument.

## BC BREAK: `Query::setDQL()` and `Query::setFirstResult()` no longer accept `null`

The `$dqlQuery` argument of `Doctrine\ORM\Query::setDQL()` must always be a
string.

The `$firstResult` argument of `Doctrine\ORM\Query::setFirstResult()` must
always be an integer.

## BC BREAK: `orm:schema-tool:update` option `--complete` is now a no-op

`orm:schema-tool:update` now behaves as if `--complete` was provided,
regardless of whether it is provided or not.

## BC BREAK: Removed `Doctrine\ORM\Proxy\Proxy` interface.

Use `Doctrine\Persistence\Proxy` instead to check whether proxies are initialized.

## BC BREAK: Overriding fields or associations declared in other than mapped superclasses

As stated in the documentation, fields and associations may only be overridden when being inherited
from mapped superclasses. Overriding them for parent entity classes now throws a `MappingException`.

## BC BREAK: Undeclared entity inheritance now throws a `MappingException`

As soon as an entity class inherits from another entity class, inheritance has to
be declared by adding the appropriate configuration for the root entity.

## Removed `getEntityManager()` in `Doctrine\ORM\Event\OnClearEventArgs` and `Doctrine\ORM\Event\*FlushEventArgs`

Use `getObjectManager()` instead.

## BC BREAK: Removed `Doctrine\ORM\Mapping\ClassMetadataInfo` class

Use `Doctrine\ORM\Mapping\ClassMetadata` instead.

## BC BREAK: Removed `Doctrine\ORM\Event\LifecycleEventArgs` class.

Use one of the dedicated event classes instead:

* `Doctrine\ORM\Event\PrePersistEventArgs`
* `Doctrine\ORM\Event\PreUpdateEventArgs`
* `Doctrine\ORM\Event\PreRemoveEventArgs`
* `Doctrine\ORM\Event\PostPersistEventArgs`
* `Doctrine\ORM\Event\PostUpdateEventArgs`
* `Doctrine\ORM\Event\PostRemoveEventArgs`
* `Doctrine\ORM\Event\PostLoadEventArgs`

## BC BREAK: Removed `AttributeDriver::$entityAnnotationClasses` and `AttributeDriver::getReader()`

* If you need to change the behavior of `AttributeDriver::isTransient()`,
  override that method instead.
* The attribute reader is internal to the driver and should not be accessed from outside.

## BC BREAK: Removed `Doctrine\ORM\Query\AST\InExpression`

The AST parser will create a `InListExpression` or a `InSubselectExpression` when
encountering an `IN ()` DQL expression instead of a generic `InExpression`.

As a consequence, `SqlWalker::walkInExpression()` has been replaced by
`SqlWalker::walkInListExpression()` and `SqlWalker::walkInSubselectExpression()`.

## BC BREAK: Changed `EntityManagerInterface#refresh($entity)`, `EntityManagerDecorator#refresh($entity)` and `UnitOfWork#refresh($entity)` signatures

The new signatures of these methods add an optional `LockMode|int|null $lockMode`
param with default `null` value (no lock).

## BC Break: Removed AnnotationDriver

The annotation driver and anything related to annotation has been removed.
Please migrate to another mapping driver.

The `Doctrine\ORM\Mapping\Annotation` maker interface has been removed in favor of the new
`Doctrine\ORM\Mapping\MappingAttribute` interface.

## BC BREAK: Removed `EntityManager::create()`

The constructor of `EntityManager` is now public and must be used instead of the `create()` method.
However, the constructor expects a `Connection` while `create()` accepted an array with connection parameters.
You can pass that array to DBAL's `Doctrine\DBAL\DriverManager::getConnection()` method to bootstrap the
connection.

## BC BREAK: Removed `QueryBuilder` methods and constants.

The following `QueryBuilder` constants and methods have been removed:

1. `SELECT`,
2. `DELETE`,
3. `UPDATE`,
4. `STATE_DIRTY`,
5. `STATE_CLEAN`,
6. `getState()`,
7. `getType()`.

## BC BREAK: Omitting only the alias argument for `QueryBuilder::update` and `QueryBuilder::delete` is not supported anymore

When building an UPDATE or DELETE query and when passing a class/type to the function, the alias argument must not be omitted.

### Before

```php
$qb = $em->createQueryBuilder()
    ->delete('User u')
    ->where('u.id = :user_id')
    ->setParameter('user_id', 1);
```

### After

```php
$qb = $em->createQueryBuilder()
    ->delete('User', 'u')
    ->where('u.id = :user_id')
    ->setParameter('user_id', 1);
```

## BC BREAK: Split output walkers and tree walkers

`SqlWalker` and its child classes don't implement the `TreeWalker` interface
anymore.

The following methods have been removed from the `TreeWalker` interface and
from the `TreeWalkerAdapter` and `TreeWalkerChain` classes:

* `setQueryComponent()`
* `walkSelectClause()`
* `walkFromClause()`
* `walkFunction()`
* `walkOrderByClause()`
* `walkOrderByItem()`
* `walkHavingClause()`
* `walkJoin()`
* `walkSelectExpression()`
* `walkQuantifiedExpression()`
* `walkSubselect()`
* `walkSubselectFromClause()`
* `walkSimpleSelectClause()`
* `walkSimpleSelectExpression()`
* `walkAggregateExpression()`
* `walkGroupByClause()`
* `walkGroupByItem()`
* `walkDeleteClause()`
* `walkUpdateClause()`
* `walkUpdateItem()`
* `walkWhereClause()`
* `walkConditionalExpression()`
* `walkConditionalTerm()`
* `walkConditionalFactor()`
* `walkConditionalPrimary()`
* `walkExistsExpression()`
* `walkCollectionMemberExpression()`
* `walkEmptyCollectionComparisonExpression()`
* `walkNullComparisonExpression()`
* `walkInExpression()`
* `walkInstanceOfExpression()`
* `walkLiteral()`
* `walkBetweenExpression()`
* `walkLikeExpression()`
* `walkStateFieldPathExpression()`
* `walkComparisonExpression()`
* `walkInputParameter()`
* `walkArithmeticExpression()`
* `walkArithmeticTerm()`
* `walkStringPrimary()`
* `walkArithmeticFactor()`
* `walkSimpleArithmeticExpression()`
* `walkPathExpression()`
* `walkResultVariable()`
* `getExecutor()`

The following changes have been made to the abstract `TreeWalkerAdapter` class:

* The method `setQueryComponent()` is now protected.
* The method `_getQueryComponents()` has been removed in favor of
  `getQueryComponents()`.

## BC BREAK: Removed identity columns emulation through sequences

If the platform you are using does not support identity columns, you should
switch to the `SEQUENCE` strategy.

## BC BREAK: Made setters parameters mandatory

The following methods require an argument when being called. Pass `null`
instead of omitting the argument.

* `Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs::setFoundMetadata()`
* `Doctrine\ORM\AbstractQuery::setHydrationCacheProfile()`
* `Doctrine\ORM\AbstractQuery::setResultCache()`
* `Doctrine\ORM\AbstractQuery::setResultCacheProfile()`

## BC BREAK: New argument to `NamingStrategy::joinColumnName()`

### Before

```php
<?php
class MyStrategy implements NamingStrategy
{
    /**
     * @param string $propertyName A property name.
     */
    public function joinColumnName($propertyName): string
    {
        // …
    }
}
```

### After

The `class-string` type for `$className` can be inherited from the signature of
the interface.

```php
<?php
class MyStrategy implements NamingStrategy
{
    /**
     * {@inheritdoc}
     */
    public function joinColumnName(string $propertyName, string $className): string
    {
        // …
    }
}
```

## BC BREAK: Remove `StaticPHPDriver` and `DriverChain`

Use `Doctrine\Persistence\Mapping\Driver\StaticPHPDriver` and
`Doctrine\Persistence\Mapping\Driver\MappingDriverChain` from
`doctrine/persistence` instead.

## BC BREAK: `UnderscoreNamingStrategy` is number aware only

The second argument to `UnderscoreNamingStrategy::__construct()` was dropped,
the strategy can no longer be unaware of numbers.

## BC BREAK: Remove `Doctrine\ORM\Tools\DisconnectedClassMetadataFactory`

No replacement is provided.

## BC BREAK: Remove support for `Type::canRequireSQLConversion()`

This feature was deprecated in DBAL 3.3.0 and will be removed in DBAL 4.0.
The value conversion methods are now called regardless of the type.

The `MappingException::sqlConversionNotAllowedForIdentifiers()` method has been removed
as no longer relevant.

## BC Break: Removed the `doctrine` binary.

The documentation explains how the console tools can be bootstrapped for
standalone usage:

https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/tools.html

The method `ConsoleRunner::printCliConfigTemplate()` has been removed as well
because it was only useful in the context of the `doctrine` binary.

## BC Break: Removed `EntityManagerHelper` and related logic

All console commands require a `$entityManagerProvider` to be passed via the
constructor. Commands won't try to get the entity manager from a previously
registered `em` console helper.

The following classes have been removed:

* `Doctrine\ORM\Tools\Console\EntityManagerProvider\HelperSetManagerProvider`
* `Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper`

The following breaking changes have been applied to `Doctrine\ORM\Tools\Console\ConsoleRunner`:

* The method `createHelperSet()` has been removed.
* The methods `run()` and `createApplication()` don't accept an instance of
  `HelperSet` as first argument anymore.
* The method `addCommands()` requires an instance of `EntityManagerProvider`
  as second argument now.

## BC Break: `Exception\ORMException` is no longer a class, but an interface

All methods in `Doctrine\ORM\ORMException` have been extracted to dedicated exceptions.

 * `missingMappingDriverImpl()` => `Exception\MissingMappingDriverImplementation::create()`
 * `unrecognizedField()` => `Persisters\Exception\UnrecognizedField::byName()`
 * `unexpectedAssociationValue()` => `Exception\UnexpectedAssociationValue::create()`
 * `invalidOrientation()` => `Persisters\Exception\InvalidOrientation::fromClassNameAndField()`
 * `entityManagerClosed()` => `Exception\EntityManagerClosed::create()`
 * `invalidHydrationMode()` => `Exception\InvalidHydrationMode::fromMode()`
 * `mismatchedEventManager()` => `Exception\MismatchedEventManager::create()`
 * `findByRequiresParameter()` => `Repository\Exception\InvalidMagicMethodCall::onMissingParameter()`
 * `invalidMagicCall()` => `Repository\Exception\InvalidMagicMethodCall::becauseFieldNotFoundIn()`
 * `invalidFindByInverseAssociation()` => `Repository\Exception\InvalidFindByCall::fromInverseSideUsage()`
 * `invalidResultCacheDriver()` => `Cache\Exception\InvalidResultCacheDriver::create()`
 * `notSupported()` => `Exception\NotSupported::create()`
 * `queryCacheNotConfigured()` => `QueryCacheNotConfigured::create()`
 * `metadataCacheNotConfigured()` => `Cache\Exception\MetadataCacheNotConfigured::create()`
 * `queryCacheUsesNonPersistentCache()` => `Cache\Exception\QueryCacheUsesNonPersistentCache::fromDriver()`
 * `metadataCacheUsesNonPersistentCache()` => `Cache\Exception\MetadataCacheUsesNonPersistentCache::fromDriver()`
 * `proxyClassesAlwaysRegenerating()` => `Exception\ProxyClassesAlwaysRegenerating::create()`
 * `invalidEntityRepository()` => `Exception\InvalidEntityRepository::fromClassName()`
 * `missingIdentifierField()` => `Exception\MissingIdentifierField::fromFieldAndClass()`
 * `unrecognizedIdentifierFields()` => `Exception\UnrecognizedIdentifierFields::fromClassAndFieldNames()`
 * `cantUseInOperatorOnCompositeKeys()` => `Persisters\Exception\CantUseInOperatorOnCompositeKeys::create()`

## BC Break: `CacheException` is no longer a class, but an interface

All methods in `Doctrine\ORM\Cache\CacheException` have been extracted to dedicated exceptions.

 * `updateReadOnlyCollection()` => `Cache\Exception\CannotUpdateReadOnlyCollection::fromEntityAndField()`
 * `updateReadOnlyEntity()` => `Cache\Exception\CannotUpdateReadOnlyEntity::fromEntity()`
 * `nonCacheableEntity()` => `Cache\Exception\NonCacheableEntity::fromEntity()`
 * `nonCacheableEntityAssociation()` => `Cache\Exception\NonCacheableEntityAssociation::fromEntityAndField()`


## BC Break: Missing type declaration added for identifier generators

Although undocumented, it was possible to configure a custom repository
class that implements `ObjectRepository` but does not extend the
`EntityRepository` base class. Repository classes have to extend
`EntityRepository` now.

## BC BREAK: Removed support for entity namespace alias

- `EntityManager::getRepository()` no longer accepts the entity namespace alias
  notation.
- `Configuration::addEntityNamespace()` and
  `Configuration::getEntityNamespace()` have been removed.

## BC BREAK: Remove helper methods from `AbstractCollectionPersister`

The following protected methods of
`Doctrine\ORM\Cache\Persister\Collection\AbstractCollectionPersister`
have been removed.

* `evictCollectionCache()`
* `evictElementCache()`

## BC BREAK: `Doctrine\ORM\Query\TreeWalkerChainIterator`

This class has been removed without replacement.

## BC BREAK: Remove quoting methods from `ClassMetadata`

The following methods have been removed from the class metadata because
quoting is handled by implementations of `Doctrine\ORM\Mapping\QuoteStrategy`:

* `getQuotedIdentifierColumnNames()`
* `getQuotedColumnName()`
* `getQuotedTableName()`
* `getQuotedJoinTableName()`

## BC BREAK: Remove ability to merge detached entities

Merge semantics was a poor fit for the PHP "share-nothing" architecture.
In addition to that, merging caused multiple issues with data integrity
in the managed entity graph, which was constantly spawning more edge-case
bugs/scenarios.

The method `UnitOfWork::merge()` has been removed. The method
`EntityManager::merge()` will throw an exception on each call.

## BC BREAK: Removed ability to partially flush/commit entity manager and unit of work

The following methods don't accept a single entity or an array of entities anymore:

* `Doctrine\ORM\EntityManager::flush()`
* `Doctrine\ORM\Decorator\EntityManagerDecorator::flush()`
* `Doctrine\ORM\UnitOfWork::commit()`

The semantics of `flush()` and `commit()` will remain the same, but the change
tracking will be performed on all entities managed by the unit of work, and not
just on the provided entities, as the parameter is now completely ignored.

## BC BREAK: Removed ability to partially clear entity manager and unit of work

* Passing an argument other than `null` to `EntityManager::clear()` will raise
  an exception.
* The unit of work cannot be cleared partially anymore. Passing an argument to
  `UnitOfWork::clear()` does not have any effect anymore; the unit of work is
  cleared completely.
* The method `EntityRepository::clear()` has been removed.
* The methods `getEntityClass()` and `clearsAllEntities()` have been removed
  from `OnClearEventArgs`.

## BC BREAK: Remove support for Doctrine Cache

The Doctrine Cache library is not supported anymore. The following methods
have been removed from `Doctrine\ORM\Configuration`:

* `getQueryCacheImpl()`
* `setQueryCacheImpl()`
* `getHydrationCacheImpl()`
* `setHydrationCacheImpl()`
* `getMetadataCacheImpl()`
* `setMetadataCacheImpl()`

The methods have been replaced by PSR-6 compatible counterparts
(just strip the `Impl` suffix from the old name to get the new one).

## BC BREAK: Remove `Doctrine\ORM\Configuration::newDefaultAnnotationDriver`

This functionality has been moved to the new `ORMSetup` class. Call
`Doctrine\ORM\ORMSetup::createDefaultAnnotationDriver()` to create
a new annotation driver.

## BC BREAK: Remove `Doctrine\ORM\Tools\Setup`

In our effort to migrate from Doctrine Cache to PSR-6, the `Setup` class which
accepted a Doctrine Cache instance in each method has been removed.

The replacement is `Doctrine\ORM\ORMSetup` which accepts a PSR-6
cache instead.

## BC BREAK: Removed named queries

All APIs related to named queries have been removed.

## BC BREAK: Remove old cache accessors and mutators from query classes

The following methods have been removed from `AbstractQuery`:

* `setResultCacheDriver()`
* `getResultCacheDriver()`
* `useResultCache()`
* `getResultCacheLifetime()`
* `getResultCacheId()`

The following methods have been removed from `Query`:

* `setQueryCacheDriver()`
* `getQueryCacheDriver()`

## BC BREAK: Remove `Doctrine\ORM\Cache\MultiGetRegion`

The interface has been merged into `Doctrine\ORM\Cache\Region`.

## BC BREAK: Rename `AbstractIdGenerator::generate()` to `generateId()`

* Implementations of `AbstractIdGenerator` have to implement the method
  `generateId()`.
* The method `generate()` has been removed from `AbstractIdGenerator`.

## BC BREAK: Remove cache settings inspection

Doctrine does not provide its own cache implementation anymore and relies on
the PSR-6 standard instead. As a consequence, we cannot determine anymore
whether a given cache adapter is suitable for a production environment.
Because of that, functionality that aims to do so has been removed:

* `Configuration::ensureProductionSettings()`
* the `orm:ensure-production-settings` console command

## BC BREAK: PSR-6-based second level cache

The second level cache has been reworked to consume a PSR-6 cache. Using a
Doctrine Cache instance is not supported anymore.

* `DefaultCacheFactory`: The constructor expects a PSR-6 cache item pool as
  second argument now.
* `DefaultMultiGetRegion`: This class has been removed.
* `DefaultRegion`:
    * The constructor expects a PSR-6 cache item pool as second argument now.
    * The protected `$cache` property is removed.
    * The properties `$name` and `$lifetime` as well as the constant
      `REGION_KEY_SEPARATOR` and the method `getCacheEntryKey()` are
      `private` now.
    * The method `getCache()` has been removed.


## BC Break: Remove `Doctrine\ORM\Mapping\Driver\PHPDriver`

Use `StaticPHPDriver` instead when you want to programmatically configure
entity metadata.

## BC BREAK: Remove `Doctrine\ORM\EntityManagerInterface#transactional()`

This method has been replaced by `Doctrine\ORM\EntityManagerInterface#wrapInTransaction()`.

## BC BREAK: Removed support for schema emulation.

The ORM no longer attempts to emulate schemas on SQLite.

## BC BREAK: Remove `Setup::registerAutoloadDirectory()`

Use Composer's autoloader instead.

## BC BREAK: Remove YAML mapping drivers.

If your code relies on `YamlDriver` or `SimpleYamlDriver`, you **MUST** migrate to
attribute, annotation or XML drivers instead.

You can use the `orm:convert-mapping` command to convert your metadata mapping to XML
_before_ upgrading to 3.0:

```sh
php doctrine orm:convert-mapping xml /path/to/mapping-path-converted-to-xml
```

## BC BREAK: Remove code generators and related console commands

These console commands have been removed:

* `orm:convert-d1-schema`
* `orm:convert-mapping`
* `orm:generate:entities`
* `orm:generate-repositories`

These classes have been deprecated:

* `Doctrine\ORM\Tools\ConvertDoctrine1Schema`
* `Doctrine\ORM\Tools\EntityGenerator`
* `Doctrine\ORM\Tools\EntityRepositoryGenerator`

The entire `Doctrine\ORM\Tools\Export` namespace has been removed as well.

## BC BREAK: Removed `Doctrine\ORM\Version`

Use Composer's runtime API if you _really_ need to check the version of the ORM package at runtime.

## BC BREAK: EntityRepository::count() signature change

The argument `$criteria` of `Doctrine\ORM\EntityRepository::count()` is now
optional. Overrides in child classes should be made compatible.

## BC BREAK: changes in exception hierarchy

- `Doctrine\ORM\ORMException` has been removed
- `Doctrine\ORM\Exception\ORMException` is now an interface

## Variadic methods now use native variadics
The following methods were using `func_get_args()` to simulate a variadic argument:
- `Doctrine\ORM\Query\Expr#andX()`
- `Doctrine\ORM\Query\Expr#orX()`
- `Doctrine\ORM\QueryBuilder#select()`
- `Doctrine\ORM\QueryBuilder#addSelect()`
- `Doctrine\ORM\QueryBuilder#where()`
- `Doctrine\ORM\QueryBuilder#andWhere()`
- `Doctrine\ORM\QueryBuilder#orWhere()`
- `Doctrine\ORM\QueryBuilder#groupBy()`
- `Doctrine\ORM\QueryBuilder#andGroupBy()`
- `Doctrine\ORM\QueryBuilder#having()`
- `Doctrine\ORM\QueryBuilder#andHaving()`
- `Doctrine\ORM\QueryBuilder#orHaving()`
A variadic argument is now actually used in their signatures signature (`...$x`).
Signatures of overridden methods should be changed accordingly

## Minor BC BREAK: removed `Doctrine\ORM\EntityManagerInterface#copy()`

Method `Doctrine\ORM\EntityManagerInterface#copy()` never got its implementation and is removed in 3.0.

## BC BREAK: Removed classes related to UUID and TABLE generator strategies

The following classes have been removed:
- `Doctrine\ORM\Id\TableGenerator`
- `Doctrine\ORM\Id\UuidGenerator`

Using the `UUID` strategy for generating identifiers is not supported anymore.

## BC BREAK: Removed `Query::iterate()`

The deprecated method `Query::iterate()` has been removed along with the
following classes and methods:

- `AbstractHydrator::iterate()`
- `AbstractHydrator::hydrateRow()`
- `IterableResult`

Use `toIterable()` instead.

# Upgrade to 2.20

## Add `Doctrine\ORM\Query\OutputWalker` interface, deprecate `Doctrine\ORM\Query\SqlWalker::getExecutor()`

Output walkers should implement the new `\Doctrine\ORM\Query\OutputWalker` interface and create
`Doctrine\ORM\Query\Exec\SqlFinalizer` instances instead of `Doctrine\ORM\Query\Exec\AbstractSqlExecutor`s.
The output walker must not base its workings on the query `firstResult`/`maxResult` values, so that the
`SqlFinalizer` can be kept in the query cache and used regardless of the actual `firstResult`/`maxResult` values.
Any operation dependent on `firstResult`/`maxResult` should take place within the `SqlFinalizer::createExecutor()`
method. Details can be found at https://github.com/doctrine/orm/pull/11188.

## Explictly forbid property hooks

Property hooks are not supported yet by Doctrine ORM. Until support is added,
they are explicitly forbidden because the support would result in a breaking
change in behavior.

Progress on this is tracked at https://github.com/doctrine/orm/issues/11624 .

## PARTIAL DQL syntax is undeprecated

Use of the PARTIAL keyword is not deprecated anymore in DQL, because we will be
able to support PARTIAL objects with PHP 8.4 Lazy Objects and
Symfony/VarExporter in a better way. When we decided to remove this feature
these two abstractions did not exist yet.

WARNING: If you want to upgrade to 3.x and still use PARTIAL keyword in DQL
with array or object hydrators, then you have to directly migrate to ORM 3.3.x or higher.
PARTIAL keyword in DQL is not available in 3.0, 3.1 and 3.2 of ORM.

## Deprecate `\Doctrine\ORM\Query\Parser::setCustomOutputTreeWalker()`

Use the `\Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER` query hint to set the output walker
class instead of setting it through the `\Doctrine\ORM\Query\Parser::setCustomOutputTreeWalker()` method
on the parser instance.

# Upgrade to 2.19

## Deprecate calling `ClassMetadata::getAssociationMappedByTargetField()` with the owning side of an association

Calling
`Doctrine\ORM\Mapping\ClassMetadata::getAssociationMappedByTargetField()` with
the owning side of an association returns `null`, which is undocumented, and
wrong according to the phpdoc of the parent method.

If you do not know whether you are on the owning or inverse side of an association,
you can use  `Doctrine\ORM\Mapping\ClassMetadata::isAssociationInverseSide()`
to find out.

## Deprecate `Doctrine\ORM\Query\Lexer::T_*` constants

Use `Doctrine\ORM\Query\TokenType::T_*` instead.

# Upgrade to 2.17

## Deprecate annotations classes for named queries

The following classes have been deprecated:

* `Doctrine\ORM\Mapping\NamedNativeQueries`
* `Doctrine\ORM\Mapping\NamedNativeQuery`
* `Doctrine\ORM\Mapping\NamedQueries`
* `Doctrine\ORM\Mapping\NamedQuery`

## Deprecate `Doctrine\ORM\Query\Exec\AbstractSqlExecutor::_sqlStatements`

Use `Doctrine\ORM\Query\Exec\AbstractSqlExecutor::sqlStatements` instead.

## Undeprecate `Doctrine\ORM\Proxy\Autoloader`

It will be a full-fledged class, no longer extending
`Doctrine\Common\Proxy\Autoloader` in 3.0.x.

## Deprecated: reliance on the non-optimal defaults that come with the `AUTO` identifier generation strategy

When the `AUTO` identifier generation strategy was introduced, the best
strategy at the time was selected for each database platform.
A lot of time has passed since then, and with ORM 3.0.0 and DBAL 4.0.0, support
for better strategies will be added.

Because of that, it is now deprecated to rely on the historical defaults when
they differ from what we will be recommended in the future.

Instead, you should pick a strategy for each database platform you use, and it
will be used when using `AUTO`. As of now, only PostgreSQL is affected by this.

It is recommended that PostgreSQL users configure their existing and new
applications to use `SEQUENCE` until `doctrine/dbal` 4.0.0 is released:

```php
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Configuration;

assert($configuration instanceof Configuration);
$configuration->setIdentityGenerationPreferences([
    PostgreSQLPlatform::CLASS => ClassMetadata::GENERATOR_TYPE_SEQUENCE,
]);
```

When DBAL 4 is released, `AUTO` will result in `IDENTITY`, and the above
configuration should be removed to migrate to it.

## Deprecate `EntityManagerInterface::getPartialReference()`

This method does not have a replacement and will be removed in 3.0.

## Deprecate not-enabling lazy-ghosts

Not enabling lazy ghost objects is deprecated. In ORM 3.0, they will be always enabled.
Ensure `Doctrine\ORM\Configuration::setLazyGhostObjectEnabled(true)` is called to enable them.

# Upgrade to 2.16

## Deprecated accepting duplicate IDs in the identity map

For any given entity class and ID value, there should be only one object instance
representing the entity.

In https://github.com/doctrine/orm/pull/10785, a check was added that will guard this
in the identity map. The most probable cause for violations of this rule are collisions
of application-provided IDs.

In ORM 2.16.0, the check was added by throwing an exception. In ORM 2.16.1, this will be
changed to a deprecation notice. ORM 3.0 will make it an exception again. Use
`\Doctrine\ORM\Configuration::setRejectIdCollisionInIdentityMap()` if you want to opt-in
to the new mode.

## Potential changes to the order in which `INSERT`s are executed

In https://github.com/doctrine/orm/pull/10547, the commit order computation was improved
to fix a series of bugs where a correct (working) commit order was previously not found.
Also, the new computation may get away with fewer queries being executed: By inserting
referred-to entities first and using their ID values for foreign key fields in subsequent
`INSERT` statements, additional `UPDATE` statements that were previously necessary can be
avoided.

When using database-provided, auto-incrementing IDs, this may lead to IDs being assigned
to entities in a different order than it was previously the case.

## Deprecated returning post insert IDs from `EntityPersister::executeInserts()`

Persisters implementing `\Doctrine\ORM\Persisters\Entity\EntityPersister` should no longer
return an array of post insert IDs from their `::executeInserts()` method. Make the
persister call `Doctrine\ORM\UnitOfWork::assignPostInsertId()` instead.

## Changing the way how reflection-based mapping drivers report fields, deprecated the "old" mode

In ORM 3.0, a change will be made regarding how the `AttributeDriver` reports field mappings.
This change is necessary to be able to detect (and reject) some invalid mapping configurations.

To avoid surprises during 2.x upgrades, the new mode is opt-in. It can be activated on the
`AttributeDriver` and `AnnotationDriver` by setting the `$reportFieldsWhereDeclared`
constructor parameter to `true`. It will cause `MappingException`s to be thrown when invalid
configurations are detected.

Not enabling the new mode will cause a deprecation notice to be raised. In ORM 3.0,
only the new mode will be available.

# Upgrade to 2.15

## Deprecated configuring `JoinColumn` on the inverse side of one-to-one associations

For one-to-one associations, the side using the `mappedBy` attribute is the inverse side.
The owning side is the entity with the table containing the foreign key. Using `JoinColumn`
configuration on the _inverse_ side now triggers a deprecation notice and will be an error
in 3.0.

## Deprecated overriding fields or associations not declared in mapped superclasses

As stated in the documentation, fields and associations may only be overridden when being inherited
from mapped superclasses. Overriding them for parent entity classes now triggers a deprecation notice
and will be an error in 3.0.

## Deprecated undeclared entity inheritance

As soon as an entity class inherits from another entity class, inheritance has to
be declared by adding the appropriate configuration for the root entity.

## Deprecated stubs for "concrete table inheritance"

This third way of mapping class inheritance was never implemented. Code stubs are
now deprecated and will be removed in 3.0.

* `\Doctrine\ORM\Mapping\ClassMetadataInfo::INHERITANCE_TYPE_TABLE_PER_CLASS` constant
* `\Doctrine\ORM\Mapping\ClassMetadataInfo::isInheritanceTypeTablePerClass()` method
* Using `TABLE_PER_CLASS` as the value for the `InheritanceType` attribute or annotation
  or in XML configuration files.

# Upgrade to 2.14

## Deprecated `Doctrine\ORM\Persisters\Exception\UnrecognizedField::byName($field)` method.

Use `Doctrine\ORM\Persisters\Exception\UnrecognizedField::byFullyQualifiedName($className, $field)` instead.

## Deprecated constants of `Doctrine\ORM\Internal\CommitOrderCalculator`

The following public constants have been deprecated:

* `CommitOrderCalculator::NOT_VISITED`
* `CommitOrderCalculator::IN_PROGRESS`
* `CommitOrderCalculator::VISITED`

These constants were used for internal purposes. Relying on them is discouraged.

## Deprecated `Doctrine\ORM\Query\AST\InExpression`

The AST parser will create a `InListExpression` or a `InSubselectExpression` when
encountering an `IN ()` DQL expression instead of a generic `InExpression`.

As a consequence, `SqlWalker::walkInExpression()` has been deprecated in favor of
`SqlWalker::walkInListExpression()` and `SqlWalker::walkInSubselectExpression()`.

## Deprecated constructing a `CacheKey` without `$hash`

The `Doctrine\ORM\Cache\CacheKey` class has an explicit constructor now with
an optional parameter `$hash`. That parameter will become mandatory in 3.0.

## Deprecated `AttributeDriver::$entityAnnotationClasses`

If you need to change the behavior of `AttributeDriver::isTransient()`,
override that method instead.

## Deprecated incomplete schema updates

Using `orm:schema-tool:update` without passing the `--complete` flag is
deprecated. Use schema asset filtering if you need to preserve assets not
managed by DBAL.

Likewise, calling `SchemaTool::updateSchema()` or
`SchemaTool::getUpdateSchemaSql()` with a second argument is deprecated.

## Deprecated annotation mapping driver.

Please switch to one of the other mapping drivers. Native attributes which PHP
supports since version 8.0 are probably your best option.

As a consequence, the following methods are deprecated:
- `ORMSetup::createAnnotationMetadataConfiguration`
- `ORMSetup::createDefaultAnnotationDriver`

The marker interface `Doctrine\ORM\Mapping\Annotation` is deprecated as well.
All annotation/attribute classes implement
`Doctrine\ORM\Mapping\MappingAttribute` now.

## Deprecated `Doctrine\ORM\Proxy\Proxy` interface.

Use `Doctrine\Persistence\Proxy` instead to check whether proxies are initialized.

## Deprecated `Doctrine\ORM\Event\LifecycleEventArgs` class.

It will be removed in 3.0. Use one of the dedicated event classes instead:

* `Doctrine\ORM\Event\PrePersistEventArgs`
* `Doctrine\ORM\Event\PreUpdateEventArgs`
* `Doctrine\ORM\Event\PreRemoveEventArgs`
* `Doctrine\ORM\Event\PostPersistEventArgs`
* `Doctrine\ORM\Event\PostUpdateEventArgs`
* `Doctrine\ORM\Event\PostRemoveEventArgs`
* `Doctrine\ORM\Event\PostLoadEventArgs`

# Upgrade to 2.13

## Deprecated `EntityManager::create()`

The constructor of `EntityManager` is now public and should be used instead of the `create()` method.
However, the constructor expects a `Connection` while `create()` accepted an array with connection parameters.
You can pass that array to DBAL's `Doctrine\DBAL\DriverManager::getConnection()` method to bootstrap the
connection.

## Deprecated `QueryBuilder` methods and constants.

1. The `QueryBuilder::getState()` method has been deprecated as the builder state is an internal concern.
2. Relying on the type of the query being built by using `QueryBuilder::getType()` has been deprecated.
   If necessary, track the type of the query being built outside of the builder.

The following `QueryBuilder` constants related to the above methods have been deprecated:

1. `SELECT`,
2. `DELETE`,
3. `UPDATE`,
4. `STATE_DIRTY`,
5. `STATE_CLEAN`.

## Deprecated omitting only the alias argument for `QueryBuilder::update` and `QueryBuilder::delete`

When building an UPDATE or DELETE query and when passing a class/type to the function, the alias argument must not be omitted.

### Before

```php
$qb = $em->createQueryBuilder()
    ->delete('User u')
    ->where('u.id = :user_id')
    ->setParameter('user_id', 1);
```

### After

```php
$qb = $em->createQueryBuilder()
    ->delete('User', 'u')
    ->where('u.id = :user_id')
    ->setParameter('user_id', 1);
```

## Deprecated using the `IDENTITY` identifier strategy on platform that do not support identity columns

If identity columns are emulated with sequences on the platform you are using,
you should switch to the `SEQUENCE` strategy.

## Deprecated passing `null` to `Doctrine\ORM\Query::setFirstResult()`

`$query->setFirstResult(null);` is equivalent to `$query->setFirstResult(0)`.

## Deprecated calling setters without arguments

The following methods will require an argument in 3.0. Pass `null` instead of
omitting the argument.

* `Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs::setFoundMetadata()`
* `Doctrine\ORM\AbstractQuery::setHydrationCacheProfile()`
* `Doctrine\ORM\AbstractQuery::setResultCache()`
* `Doctrine\ORM\AbstractQuery::setResultCacheProfile()`

## Deprecated passing invalid fetch modes to `AbstractQuery::setFetchMode()`

Calling `AbstractQuery::setFetchMode()` with anything else than
`Doctrine\ORM\Mapping::FETCH_EAGER` results in
`Doctrine\ORM\Mapping::FETCH_LAZY` being used. Relying on that behavior is
deprecated and will result in an exception in 3.0.

## Deprecated `getEntityManager()` in `Doctrine\ORM\Event\OnClearEventArgs` and `Doctrine\ORM\Event\*FlushEventArgs`

This method has been deprecated in:

* `Doctrine\ORM\Event\OnClearEventArgs`
* `Doctrine\ORM\Event\OnFlushEventArgs`
* `Doctrine\ORM\Event\PostFlushEventArgs`
* `Doctrine\ORM\Event\PreFlushEventArgs`

It will be removed in 3.0. Use `getObjectManager()` instead.

## Prepare split of output walkers and tree walkers

In 3.0, `SqlWalker` and its child classes won't implement the `TreeWalker`
interface anymore. Relying on that inheritance is deprecated.

The following methods of the `TreeWalker` interface have been deprecated:

* `setQueryComponent()`
* `walkSelectClause()`
* `walkFromClause()`
* `walkFunction()`
* `walkOrderByClause()`
* `walkOrderByItem()`
* `walkHavingClause()`
* `walkJoin()`
* `walkSelectExpression()`
* `walkQuantifiedExpression()`
* `walkSubselect()`
* `walkSubselectFromClause()`
* `walkSimpleSelectClause()`
* `walkSimpleSelectExpression()`
* `walkAggregateExpression()`
* `walkGroupByClause()`
* `walkGroupByItem()`
* `walkDeleteClause()`
* `walkUpdateClause()`
* `walkUpdateItem()`
* `walkWhereClause()`
* `walkConditionalExpression()`
* `walkConditionalTerm()`
* `walkConditionalFactor()`
* `walkConditionalPrimary()`
* `walkExistsExpression()`
* `walkCollectionMemberExpression()`
* `walkEmptyCollectionComparisonExpression()`
* `walkNullComparisonExpression()`
* `walkInExpression()`
* `walkInstanceOfExpression()`
* `walkLiteral()`
* `walkBetweenExpression()`
* `walkLikeExpression()`
* `walkStateFieldPathExpression()`
* `walkComparisonExpression()`
* `walkInputParameter()`
* `walkArithmeticExpression()`
* `walkArithmeticTerm()`
* `walkStringPrimary()`
* `walkArithmeticFactor()`
* `walkSimpleArithmeticExpression()`
* `walkPathExpression()`
* `walkResultVariable()`
* `getExecutor()`

The following changes have been made to the abstract `TreeWalkerAdapter` class:

* All implementations of now-deprecated `TreeWalker` methods have been
  deprecated as well.
* The method `setQueryComponent()` will become protected in 3.0. Calling it
  publicly is deprecated.
* The method `_getQueryComponents()` is deprecated, call `getQueryComponents()`
  instead.

On the `TreeWalkerChain` class, all implementations of now-deprecated
`TreeWalker` methods have been deprecated as well.  However, `SqlWalker` is
unaffected by those deprecations and will continue to implement all of those
methods.

## Deprecated passing `null` to `Doctrine\ORM\Query::setDQL()`

Doing `$query->setDQL(null);` achieves nothing.

## Deprecated omitting second argument to `NamingStrategy::joinColumnName`

When implementing `NamingStrategy`, it is deprecated to implement
`joinColumnName()` with only one argument.

### Before

```php
<?php
class MyStrategy implements NamingStrategy
{
    /**
     * @param string $propertyName A property name.
     */
    public function joinColumnName($propertyName): string
    {
        // …
    }
}
```

### After

For backward-compatibility reasons, the parameter has to be optional, but can
be documented as guaranteed to be a `class-string`.

```php
<?php
class MyStrategy implements NamingStrategy
{
    /**
     * @param string       $propertyName A property name.
     * @param class-string $className
     */
    public function joinColumnName($propertyName, $className = null): string
    {
        // …
    }
}
```

## Deprecated methods related to named queries

The following methods have been deprecated:

- `Doctrine\ORM\Query\ResultSetMappingBuilder::addNamedNativeQueryMapping()`
- `Doctrine\ORM\Query\ResultSetMappingBuilder::addNamedNativeQueryResultClassMapping()`
- `Doctrine\ORM\Query\ResultSetMappingBuilder::addNamedNativeQueryResultSetMapping()`
- `Doctrine\ORM\Query\ResultSetMappingBuilder::addNamedNativeQueryEntityResultMapping()`

## Deprecated classes related to Doctrine 1 and reverse engineering

The following classes have been deprecated:

- `Doctrine\ORM\Tools\ConvertDoctrine1Schema`
- `Doctrine\ORM\Tools\DisconnectedClassMetadataFactory`

## Deprecate `ClassMetadataInfo` usage

It is deprecated to pass `Doctrine\ORM\Mapping\ClassMetadataInfo` instances
that are not also instances of `Doctrine\ORM\ClassMetadata` to the following
methods:

- `Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder::__construct()`
- `Doctrine\ORM\Mapping\Driver\DatabaseDriver::loadMetadataForClass()`
- `Doctrine\ORM\Tools\SchemaValidator::validateClass()`

# Upgrade to 2.12

## Deprecated the `doctrine` binary.

The documentation explains how the console tools can be bootstrapped for
standalone usage.

The method `ConsoleRunner::printCliConfigTemplate()` is deprecated because it
was only useful in the context of the `doctrine` binary.

## Deprecate omitting `$class` argument to `ORMInvalidArgumentException::invalidIdentifierBindingEntity()`

To make it easier to identify understand the cause for that exception, it is
deprecated to omit the class name when calling
`ORMInvalidArgumentException::invalidIdentifierBindingEntity()`.

## Deprecate `Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper`

Using a console helper to provide the ORM's console commands with one or
multiple entity managers had been deprecated with 2.9 already. This leaves
The `EntityManagerHelper` class with no purpose which is why it is now
deprecated too. Applications that still rely on the `em` console helper, can
easily recreate that class in their own codebase.

## Deprecate custom repository classes that don't extend `EntityRepository`

Although undocumented, it is currently possible to configure a custom repository
class that implements `ObjectRepository` but does not extend the
`EntityRepository` base class.

This is now deprecated. Please extend `EntityRepository` instead.

## Deprecated more APIs related to entity namespace aliases

```diff
-$config = $entityManager->getConfiguration();
-$config->addEntityNamespace('CMS', 'My\App\Cms');
+use My\App\Cms\CmsUser;

-$entityManager->getRepository('CMS:CmsUser');
+$entityManager->getRepository(CmsUser::class);
```

## Deprecate `AttributeDriver::getReader()` and `AnnotationDriver::getReader()`

That method was inherited from the abstract `AnnotationDriver` class of
`doctrine/persistence`, and does not seem to serve any purpose.

## Un-deprecate `Doctrine\ORM\Proxy\Proxy`

Because no forward-compatible new proxy solution had been implemented yet, the
current proxy mechanism is not considered deprecated anymore for the time
being. This applies to the following interfaces/classes:

* `Doctrine\ORM\Proxy\Proxy`
* `Doctrine\ORM\Proxy\ProxyFactory`

These methods have been un-deprecated:

* `Doctrine\ORM\Configuration::getAutoGenerateProxyClasses()`
* `Doctrine\ORM\Configuration::getProxyDir()`
* `Doctrine\ORM\Configuration::getProxyNamespace()`

Note that the `Doctrine\ORM\Proxy\Autoloader` remains deprecated and will be removed in 3.0.

## Deprecate helper methods from `AbstractCollectionPersister`

The following protected methods of
`Doctrine\ORM\Cache\Persister\Collection\AbstractCollectionPersister`
are not in use anymore and will be removed.

* `evictCollectionCache()`
* `evictElementCache()`

## Deprecate `Doctrine\ORM\Query\TreeWalkerChainIterator`

This class won't have a replacement.

## Deprecate `OnClearEventArgs::getEntityClass()` and `OnClearEventArgs::clearsAllEntities()`

These methods will be removed in 3.0 along with the ability to partially clear
the entity manager.

## Deprecate `Doctrine\ORM\Configuration::newDefaultAnnotationDriver`

This functionality has been moved to the new `ORMSetup` class. Call
`Doctrine\ORM\ORMSetup::createDefaultAnnotationDriver()` to create
a new annotation driver.

## Deprecate `Doctrine\ORM\Tools\Setup`

In our effort to migrate from Doctrine Cache to PSR-6, the `Setup` class which
accepted a Doctrine Cache instance in each method has been deprecated.

The replacement is `Doctrine\ORM\ORMSetup` which accepts a PSR-6
cache instead.

## Deprecate `Doctrine\ORM\Cache\MultiGetRegion`

The interface will be merged with `Doctrine\ORM\Cache\Region` in 3.0.

# Upgrade to 2.11

## Rename `AbstractIdGenerator::generate()` to `generateId()`

Implementations of `AbstractIdGenerator` have to override the method
`generateId()` without calling the parent implementation. Not doing so is
deprecated. Calling `generate()` on any `AbstractIdGenerator` implementation
is deprecated.

## PSR-6-based second level cache

The second level cache has been reworked to consume a PSR-6 cache. Using a
Doctrine Cache instance is deprecated.

* `DefaultCacheFactory`: The constructor expects a PSR-6 cache item pool as
  second argument now.
* `DefaultMultiGetRegion`: This class is deprecated in favor of `DefaultRegion`.
* `DefaultRegion`:
  * The constructor expects a PSR-6 cache item pool as second argument now.
  * The protected `$cache` property is deprecated.
  * The properties `$name` and `$lifetime` as well as the constant
   `REGION_KEY_SEPARATOR` and the method `getCacheEntryKey()` are flagged as
   `@internal` now. They all will become `private` in 3.0.
  * The method `getCache()` is deprecated without replacement.

## Deprecated: `Doctrine\ORM\Mapping\Driver\PHPDriver`

Use `StaticPHPDriver` instead when you want to programmatically configure
entity metadata.

You can convert mappings with the `orm:convert-mapping` command or more simply
in this case, `include` the metadata file from the `loadMetadata` static method
used by the `StaticPHPDriver`.

## Deprecated: `Setup::registerAutoloadDirectory()`

Use Composer's autoloader instead.

## Deprecated: `AbstractHydrator::hydrateRow()`

Following the deprecation of the method `AbstractHydrator::iterate()`, the
method `hydrateRow()` has been deprecated as well.

## Deprecate cache settings inspection

Doctrine does not provide its own cache implementation anymore and relies on
the PSR-6 standard instead. As a consequence, we cannot determine anymore
whether a given cache adapter is suitable for a production environment.
Because of that, functionality that aims to do so has been deprecated:

* `Configuration::ensureProductionSettings()`
* the `orm:ensure-production-settings` console command

# Upgrade to 2.10

## BC Break: `UnitOfWork` now relies on SPL object IDs, not hashes

When calling the following methods, you are now supposed to use the result of
`spl_object_id()`, and not `spl_object_hash()`:
- `UnitOfWork::clearEntityChangeSet()`
- `UnitOfWork::setOriginalEntityProperty()`

## BC Break: Removed `TABLE` id generator strategy

The implementation was unfinished for 14 years.
It is now deprecated to rely on:
- `Doctrine\ORM\Id\TableGenerator`;
- `Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_TABLE`;
- `Doctrine\ORM\Mapping\ClassMetadata::$tableGeneratorDefinition`;
- or `Doctrine\ORM\Mapping\ClassMetadata::isIdGeneratorTable()`.

## New method `Doctrine\ORM\EntityManagerInterface#wrapInTransaction($func)`

Works the same as `Doctrine\ORM\EntityManagerInterface#transactional()` but returns any value returned from `$func` closure rather than just _non-empty value returned from the closure or true_.

Because of BC policy, the method does not exist on the interface yet. This is the example of safe usage:

```php
function foo(EntityManagerInterface $entityManager, callable $func) {
    if (method_exists($entityManager, 'wrapInTransaction')) {
        return $entityManager->wrapInTransaction($func);
    }

    return $entityManager->transactional($func);
}
```

`Doctrine\ORM\EntityManagerInterface#transactional()` has been deprecated.

## Minor BC BREAK: some exception methods have been removed

The following methods were not in use and are very unlikely to be used by
downstream packages or applications, and were consequently removed:

- `ORMException::entityMissingForeignAssignedId`
- `ORMException::entityMissingAssignedIdForField`
- `ORMException::invalidFlushMode`

## Deprecated: database-side UUID generation

[DB-generated UUIDs are deprecated as of `doctrine/dbal` 2.8][DBAL deprecation].
As a consequence, using the `UUID` strategy for generating identifiers is deprecated as well.
Furthermore, relying on the following classes and methods is deprecated:

- `Doctrine\ORM\Id\UuidGenerator`
- `Doctrine\ORM\Mapping\ClassMetadataInfo::isIdentifierUuid()`

[DBAL deprecation]: https://github.com/doctrine/dbal/pull/3212

## Minor BC BREAK: Custom hydrators and `toIterable()`

The type declaration of the `$stmt` parameter of `AbstractHydrator::toIterable()` has been removed. This change might
break custom hydrator implementations that override this very method.

Overriding this method is not recommended, which is why the method is documented as `@final` now.

```diff
- public function toIterable(ResultStatement $stmt, ResultSetMapping $resultSetMapping, array $hints = []): iterable
+ public function toIterable($stmt, ResultSetMapping $resultSetMapping, array $hints = []): iterable
```

## Deprecated: Entity Namespace Aliases

Entity namespace aliases are deprecated, use the magic ::class constant to abbreviate full class names
in EntityManager, EntityRepository and DQL.

```diff
-  $entityManager->find('MyBundle:User', $id);
+  $entityManager->find(User::class, $id);
```

# Upgrade to 2.9

## Minor BC BREAK: Setup tool needs cache implementation

With the deprecation of doctrine/cache, the setup tool might no longer work as expected without a different cache
implementation. To work around this:
* Install symfony/cache: `composer require symfony/cache`. This will keep previous behaviour without any changes
* Instantiate caches yourself: to use a different cache implementation, pass a cache instance when calling any
  configuration factory in the setup tool:
  ```diff
  - $config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode, $proxyDir);
  + $cache = \Doctrine\Common\Cache\Psr6\DoctrineProvider::wrap($anyPsr6Implementation);
  + $config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode, $proxyDir, $cache);
  ```
* As a quick workaround, you can lock the doctrine/cache dependency to work around this: `composer require doctrine/cache ^1.11`.
  Note that this is only recommended as a bandaid fix, as future versions of ORM will no longer work with doctrine/cache
  1.11.

## Deprecated: doctrine/cache for metadata caching

The `Doctrine\ORM\Configuration#setMetadataCacheImpl()` method is deprecated and should no longer be used. Please use
`Doctrine\ORM\Configuration#setMetadataCache()` with any PSR-6 cache adapter instead.

## Removed: flushing metadata cache

To support PSR-6 caches, the `--flush` option for the `orm:clear-cache:metadata` command is ignored. Metadata cache is
now always cleared regardless of the cache adapter being used.

# Upgrade to 2.8

## Minor BC BREAK: Failed commit now throw OptimisticLockException

Method `Doctrine\ORM\UnitOfWork#commit()` can throw an OptimisticLockException when a commit silently fails and returns false
since `Doctrine\DBAL\Connection#commit()` signature changed from returning void to boolean

## Deprecated: `Doctrine\ORM\AbstractQuery#iterate()`

The method `Doctrine\ORM\AbstractQuery#iterate()` is deprecated in favor of `Doctrine\ORM\AbstractQuery#toIterable()`.
Note that `toIterable()` yields results of the query, unlike `iterate()` which yielded each result wrapped into an array.

# Upgrade to 2.7

## Added `Doctrine\ORM\AbstractQuery#enableResultCache()` and `Doctrine\ORM\AbstractQuery#disableResultCache()` methods

Method `Doctrine\ORM\AbstractQuery#useResultCache()` which could be used for both enabling and disabling the cache
(depending on passed flag) was split into two.

## Minor BC BREAK: paginator output walkers aren't be called anymore on sub-queries for queries without max results

To optimize DB interaction, `Doctrine\ORM\Tools\Pagination\Paginator` no longer fetches identifiers to be able to
perform the pagination with join collections when max results isn't set in the query.

## Minor BC BREAK: tables filtered with `schema_filter` are no longer created

When generating schema diffs, if a source table is filtered out by a `schema_filter` expression, then a `CREATE TABLE` was
always generated, even if the table already existed. This has been changed in this release and the table will no longer
be created.

## Deprecated number unaware `Doctrine\ORM\Mapping\UnderscoreNamingStrategy`

In the last patch of the `v2.6.x` series, we fixed a bug that was not converting names properly when they had numbers
(e.g.: `base64Encoded` was wrongly converted to `base64encoded` instead of `base64_encoded`).

In order to not break BC we've introduced a way to enable the fixed behavior using a boolean constructor argument. This
argument will be removed in 3.0 and the default behavior will be the fixed one.

## Deprecated: `Doctrine\ORM\AbstractQuery#useResultCache()`

Method `Doctrine\ORM\AbstractQuery#useResultCache()` is deprecated because it is split into `enableResultCache()`
and `disableResultCache()`. It will be removed in 3.0.

## Deprecated code generators and related console commands

These console commands have been deprecated:

 * `orm:convert-mapping`
 * `orm:generate:entities`
 * `orm:generate-repositories`

These classes have been deprecated:

 * `Doctrine\ORM\Tools\EntityGenerator`
 * `Doctrine\ORM\Tools\EntityRepositoryGenerator`

Whole Doctrine\ORM\Tools\Export namespace with all its members have been deprecated as well.

## Deprecated `Doctrine\ORM\Proxy\Proxy` marker interface

Proxy objects in Doctrine ORM 3.0 will no longer implement `Doctrine\ORM\Proxy\Proxy` nor
`Doctrine\Persistence\Proxy`: instead, they implement
`ProxyManager\Proxy\GhostObjectInterface`.

These related classes have been deprecated:

 * `Doctrine\ORM\Proxy\ProxyFactory`
 * `Doctrine\ORM\Proxy\Autoloader` - we suggest using the composer autoloader instead

These methods have been deprecated:

 * `Doctrine\ORM\Configuration#getAutoGenerateProxyClasses()`
 * `Doctrine\ORM\Configuration#getProxyDir()`
 * `Doctrine\ORM\Configuration#getProxyNamespace()`

## Deprecated `Doctrine\ORM\Version`

The `Doctrine\ORM\Version` class is now deprecated and will be removed in Doctrine ORM 3.0:
please refrain from checking the ORM version at runtime or use Composer's [runtime API](https://getcomposer.org/doc/07-runtime.md#knowing-whether-package-x-is-installed-in-version-y).

## Deprecated `EntityManager#merge()` method

Merge semantics was a poor fit for the PHP "share-nothing" architecture.
In addition to that, merging caused multiple issues with data integrity
in the managed entity graph, which was constantly spawning more edge-case bugs/scenarios.

The following API methods were therefore deprecated:

* `EntityManager#merge()`
* `UnitOfWork#merge()`

An alternative to `EntityManager#merge()` will not be provided by ORM 3.0, since the merging
semantics should be part of the business domain rather than the persistence domain of an
application. If your application relies heavily on CRUD-alike interactions and/or `PATCH`
restful operations, you should look at alternatives such as [JMSSerializer](https://github.com/schmittjoh/serializer).

## Extending `EntityManager` is deprecated

Final keyword will be added to the `EntityManager::class` in Doctrine ORM 3.0 in order to ensure that EntityManager
 is not used as valid extension point. Valid extension point should be EntityManagerInterface.

## Deprecated `EntityManager#clear($entityName)`

If your code relies on clearing a single entity type via `EntityManager#clear($entityName)`,
the signature has been changed to `EntityManager#clear()`.

The main reason is that partial clears caused multiple issues with data integrity
in the managed entity graph, which was constantly spawning more edge-case bugs/scenarios.

## Deprecated `EntityManager#flush($entity)` and `EntityManager#flush($entities)`

If your code relies on single entity flushing optimisations via
`EntityManager#flush($entity)`, the signature has been changed to
`EntityManager#flush()`.

Said API was affected by multiple data integrity bugs due to the fact
that change tracking was being restricted upon a subset of the managed
entities. The ORM cannot support committing subsets of the managed
entities while also guaranteeing data integrity, therefore this
utility was removed.

The `flush()` semantics will remain the same, but the change tracking will be performed
on all entities managed by the unit of work, and not just on the provided
`$entity` or `$entities`, as the parameter is now completely ignored.

The same applies to `UnitOfWork#commit($entity)`, which will simply be
`UnitOfWork#commit()`.

If you would still like to perform batching operations over small `UnitOfWork`
instances, it is suggested to follow these paths instead:

 * eagerly use `EntityManager#clear()` in conjunction with a specific second level
   cache configuration (see http://docs.doctrine-project.org/projects/doctrine-orm/en/stable/reference/second-level-cache.html)
 * use an explicit change tracking policy (see http://docs.doctrine-project.org/projects/doctrine-orm/en/stable/reference/change-tracking-policies.html)

## Deprecated `YAML` mapping drivers.

If your code relies on `YamlDriver`  or `SimpleYamlDriver`, you **MUST** change to
annotation or XML drivers instead.

## Deprecated: `Doctrine\ORM\EntityManagerInterface#copy()`

Method `Doctrine\ORM\EntityManagerInterface#copy()` never got its implementation and is deprecated.
It will be removed in 3.0.

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

## Minor BC BREAK: removed $className parameter on `AbstractEntityInheritancePersister#getSelectJoinColumnSQL()`

As `$className` parameter was not used in the method, it was safely removed.

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

Metadata drivers have been rewritten to reuse code from `Doctrine\Persistence`. Anyone who is using the
`Doctrine\ORM\Mapping\Driver\Driver` interface should instead refer to
`Doctrine\Persistence\Mapping\Driver\MappingDriver`. Same applies to
`Doctrine\ORM\Mapping\Driver\AbstractFileDriver`: you should now refer to
`Doctrine\Persistence\Mapping\Driver\FileDriver`.

Also, following mapping drivers have been deprecated, please use their replacements in Doctrine\Common as listed:

 *  `Doctrine\ORM\Mapping\Driver\DriverChain`       => `Doctrine\Persistence\Mapping\Driver\MappingDriverChain`
 *  `Doctrine\ORM\Mapping\Driver\PHPDriver`         => `Doctrine\Persistence\Mapping\Driver\PHPDriver`
 *  `Doctrine\ORM\Mapping\Driver\StaticPHPDriver`   => `Doctrine\Persistence\Mapping\Driver\StaticPHPDriver`

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

You are now allowed to mark scalar SELECT expressions as HIDDEN and they are not hydrated anymore.
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

The EntityRepository now has an interface Doctrine\Persistence\ObjectRepository. This means that your classes that override EntityRepository and extend find(), findOneBy() or findBy() must be adjusted to follow this interface.

## AnnotationReader changes

The annotation reader was heavily refactored between 2.0 and 2.1-RC1. In theory the operation of the new reader should be backwards compatible, but it has to be setup differently to work that way:

    // new call to the AnnotationRegistry
    \Doctrine\Common\Annotations\AnnotationRegistry::registerFile('/doctrine-src/src/Mapping/Driver/DoctrineAnnotations.php');

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
NONE, SINGLE_TABLE, INHERITANCE_TYPE_JOINED

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
