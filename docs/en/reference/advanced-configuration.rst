Advanced Configuration
======================

The configuration of the EntityManager requires a
``Doctrine\ORM\Configuration`` instance as well as some database
connection parameters. This example shows all the potential
steps of configuration.

.. code-block:: php

    <?php

    use Doctrine\ORM\Configuration;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\Mapping\Driver\AttributeDriver;
    use Doctrine\ORM\ORMSetup;
    use Symfony\Component\Cache\Adapter\ArrayAdapter;
    use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

    // ...

    if ($applicationMode === "development") {
        $queryCache = new ArrayAdapter();
        $metadataCache = new ArrayAdapter();
    } else {
        $queryCache = new PhpFilesAdapter('doctrine_queries');
        $metadataCache = new PhpFilesAdapter('doctrine_metadata');
    }

    $config = new Configuration;
    $config->setMetadataCache($metadataCache);
    $driverImpl = new AttributeDriver(['/path/to/lib/MyProject/Entities']);
    $config->setMetadataDriverImpl($driverImpl);
    $config->setQueryCache($queryCache);

    $config->enableNativeLazyObjects(true);

    $connection = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'path' => 'database.sqlite',
    ], $config);

    $em = new EntityManager($connection, $config);

Doctrine and Caching
--------------------

Doctrine is optimized for working with caches. The main parts in Doctrine
that are optimized for caching are the metadata mapping information with
the metadata cache and the DQL to SQL conversions with the query cache.
These 2 caches require only an absolute minimum of memory yet they heavily
improve the runtime performance of Doctrine.

Doctrine does not bundle its own cache implementation anymore. Instead,
the PSR-6 standard interfaces are used to access the cache. In the examples
in this documentation, Symfony Cache is used as a reference implementation.

.. note::

    Do not use Doctrine without a metadata and query cache!

Configuration Options
---------------------

The following sections describe all the configuration options
available on a ``Doctrine\ORM\Configuration`` instance.

Metadata Driver (**REQUIRED**)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    <?php
    $config->setMetadataDriverImpl($driver);
    $config->getMetadataDriverImpl();

Gets or sets the metadata driver implementation that is used by
Doctrine to acquire the object-relational metadata for your
classes.

There are currently 3 available implementations:


-  ``Doctrine\ORM\Mapping\Driver\AttributeDriver``
-  ``Doctrine\ORM\Mapping\Driver\XmlDriver``
-  ``Doctrine\ORM\Mapping\Driver\DriverChain``

Throughout the most part of this manual the AttributeDriver is
used in the examples. For information on the usage of the
XmlDriver please refer to the dedicated chapter ``XML Mapping``.

The attribute driver can be injected in the ``Doctrine\ORM\Configuration``:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Driver\AttributeDriver;

    $driverImpl = new AttributeDriver(['/path/to/lib/MyProject/Entities']);
    $config->setMetadataDriverImpl($driverImpl);

The path information to the entities is required for the attribute
driver, because otherwise mass-operations on all entities through
the console could not work correctly. Metadata drivers can accept either
a single directory as a string or an array of directories.

AttributeDriver also accepts ``Doctrine\Persistence\Mapping\Driver\ClassLocator``,
allowing one to customize file discovery logic. You may choose to use Symfony Finder, or
utilize directory scan with ``FileClassLocator::createFromDirectories()``:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Driver\AttributeDriver;
	use Doctrine\Persistence\Mapping\Driver\FileClassLocator;

	$paths = ['/path/to/lib/MyProject/Entities'];
	$classLocator = FileClassLocator::createFromDirectories($paths);

    $driverImpl = new AttributeDriver($classLocator);
    $config->setMetadataDriverImpl($driverImpl);

With this feature, you're empowered to provide a fine-grained iterator of only necessary
files to the Driver. For example, if you are using Vertical Slice architecture, you can
exclude ``*Test.php``, ``*Controller.php``, ``*Service.php``, etc.:

.. code-block:: php

    <?php
	use Symfony\Component\Finder\Finder;

	$finder = new Finder()->files()->in($paths)
		->name('*.php')
		->notName(['*Test.php', '*Controller.php', '*Service.php']);

	$classLocator = new FileClassLocator($finder);

If you know the list of class names you want to track, use
``Doctrine\Persistence\Mapping\Driver\ClassNames``:

.. code-block:: php

    <?php
	use Doctrine\Persistence\Mapping\Driver\ClassNames;
	use App\Entity\{Article, Book};

	$entityClasses = [Article::class, Book::class];
	$classLocator = new ClassNames($entityClasses);

	$driverImpl = new AttributeDriver($classLocator);
	$config->setMetadataDriverImpl($driverImpl);

Metadata Cache (**RECOMMENDED**)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    <?php
    $config->setMetadataCache($cache);
    $config->getMetadataCache();

Gets or sets the cache adapter to use for caching metadata
information, that is, all the information you supply via attributes,
xml, so that they do not need to be parsed and loaded from scratch on
every single request which is a waste of resources. The cache
implementation must implement the PSR-6
``Psr\Cache\CacheItemPoolInterface`` interface.

Usage of a metadata cache is highly recommended.

For development you should use an array cache like
``Symfony\Component\Cache\Adapter\ArrayAdapter``
which only caches data on a per-request basis.

Default String Type Schema Length (**OPTIONAL**)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    <?php
    $config->setDefaultStringTypeSchemaLength(191);
    $config->getDefaultStringTypeSchemaLength();

Gets or sets the default length used by the SchemaTool for string columns that
have no explicit ``length`` value in the mapping. This is used when the schema is
created from metadata and when discriminator columns are generated for single-table
inheritance.

The default value is ``255``. Setting it explicitly is useful for platforms or
storage layouts that require shorter default string lengths, for example MySQL
``utf8mb4`` schemas that often use ``191`` for indexed varchar columns.

Query Cache (**RECOMMENDED**)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    <?php
    $config->setQueryCache($cache);
    $config->getQueryCache();

Gets or sets the cache implementation to use for caching DQL
queries, that is, the result of a DQL parsing process that includes
the final SQL as well as meta information about how to process the
SQL result set of a query. Note that the query cache does not
affect query results. You do not get stale data. This is a pure
optimization cache without any negative side-effects (except some
minimal memory usage in your cache).

Usage of a query cache is highly recommended.

For development you should use an array cache like
``Symfony\Component\Cache\Adapter\ArrayAdapter``
which only caches data on a per-request basis.

SQL Logger (**Optional**)
~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    <?php
    $config->setSQLLogger($logger);
    $config->getSQLLogger();

Gets or sets the logger to use for logging all SQL statements
executed by Doctrine. The logger class must implement the
deprecated ``Doctrine\DBAL\Logging\SQLLogger`` interface.

Development vs Production Configuration
---------------------------------------

You should code your Doctrine2 bootstrapping with two different
runtime models in mind. There are some serious benefits of using
APCu or Memcache in production. In development however this will
frequently give you fatal errors, when you change your entities and
the cache still keeps the outdated metadata. That is why we
recommend an array cache for development.

Furthermore you should have the Auto-generating Proxy Classes
option to true in development and to false in production. If this
option is set to ``TRUE`` it can seriously hurt your script
performance if several proxy classes are re-generated during script
execution. Filesystem calls of that magnitude can even slower than
all the database queries Doctrine issues. Additionally writing a
proxy sets an exclusive file lock which can cause serious
performance bottlenecks in systems with regular concurrent
requests.

Connection
----------

The ``$connection`` passed as the first argument to the constructor of
``EntityManager`` has to be an instance of ``Doctrine\DBAL\Connection``.
You can use the factory ``Doctrine\DBAL\DriverManager::getConnection()``
to create such a connection. The DBAL configuration is explained in the
`DBAL section <https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/configuration.html>`_.

Proxy Objects
-------------

A proxy object is an object that is put in place or used instead of
the "real" object. A proxy object can add behavior to the object
being proxied without that object being aware of it. In ORM,
proxy objects are used to realize several features but mainly for
transparent lazy-loading.

Proxy objects with their lazy-loading facilities help to keep the
subset of objects that are already in memory connected to the rest
of the objects. This is an essential property as without it there
would always be fragile partial objects at the outer edges of your
object graph.

Doctrine ORM implements a variant of the proxy pattern where it
generates classes that extend your entity classes and adds
lazy-loading capabilities to them. Doctrine can then give you an
instance of such a proxy class whenever you request an object of
the class being proxied. This happens in two situations:

Reference Proxies
~~~~~~~~~~~~~~~~~

The method ``EntityManager#getReference($entityName, $identifier)``
lets you obtain a reference to an entity for which the identifier
is known, without necessarily loading that entity from the database.
This is useful, for example, as a performance enhancement, when you
want to establish an association to an entity for which you have the
identifier.

Consider the following example:

.. code-block:: php

    <?php
    // $em instanceof EntityManager, $cart instanceof MyProject\Model\Cart
    // $itemId comes from somewhere, probably a request parameter
    $item = $em->getReference('MyProject\Model\Item', $itemId);
    $cart->addItem($item);

Whether the object being returned from ``EntityManager#getReference()``
is a proxy or a direct instance of the entity class may depend on different
factors, including whether the entity has already been loaded into memory
or entity inheritance being used. But your code does not need to care
and in fact it **should not care**. Proxy objects should be transparent to your
code.

When using the ``EntityManager#getReference()`` method, you need to be aware
of a few peculiarities.

At the best case, the ORM can avoid querying the database at all. But, that
also means that this method will not throw an exception when an invalid value
for the ``$identifier`` parameter is passed. ``$identifier`` values are
not checked and there is no guarantee that the requested entity instance even
exists – the method will still return a proxy object.

Its only when the proxy has to be fully initialized or associations cannot
be written to the database that invalid ``$identifier`` values may lead to
exceptions.

The ``EntityManager#getReference()`` is mostly useful when you only
need a reference to some entity to make an association, like in the example
above. In that case, it can save you from loading data from the database
that you don't need. But remember – as soon as you read any property values
besides those making up the ID, a database request will be made to initialize
all fields.

Association proxies
~~~~~~~~~~~~~~~~~~~

The second most important situation where Doctrine uses proxy
objects is when querying for objects. Whenever you query for an
object that has a single-valued association to another object that
is configured LAZY, without joining that association in the same
query, Doctrine puts proxy objects in place where normally the
associated object would be. Just like other proxies it will
transparently initialize itself on first access.

.. note::

    Joining an association in a DQL or native query
    essentially means eager loading of that association in that query.
    This will override the 'fetch' option specified in the mapping for
    that association, but only for that query.

Multiple Metadata Sources
-------------------------

When using different components using Doctrine ORM you may end up
with them using two different metadata drivers, for example XML and
PHP. You can use the MappingDriverChain Metadata implementations to
aggregate these drivers based on namespaces:

.. code-block:: php

    <?php
    use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;

    $chain = new MappingDriverChain();
    $chain->addDriver($xmlDriver, 'Doctrine\Tests\Models\Company');
    $chain->addDriver($phpDriver, 'Doctrine\Tests\ORM\Mapping');

Based on the namespace of the entity the loading of entities is
delegated to the appropriate driver. The chain semantics come from
the fact that the driver loops through all namespaces and matches
the entity class name against the namespace using a
``strpos() === 0`` call. This means you need to order the drivers
correctly if sub-namespaces use different metadata driver
implementations.


Default Repository (**OPTIONAL**)
-----------------------------------

Specifies the FQCN of a subclass of the EntityRepository.
That will be available for all entities without a custom repository class.

.. code-block:: php

    <?php
    $config->setDefaultRepositoryClassName($fqcn);
    $config->getDefaultRepositoryClassName();

The default value is ``Doctrine\ORM\EntityRepository``.
Any repository class must be a subclass of EntityRepository otherwise you got an ORMException

Ignoring entities (**OPTIONAL**)
-----------------------------------

Specifies the Entity FQCNs to ignore.
SchemaTool will then skip these (e.g. when comparing schemas).

.. code-block:: php

    <?php
    $config->setSchemaIgnoreClasses([$fqcn]);
    $config->getSchemaIgnoreClasses();


Setting up the Console
----------------------

Doctrine uses the Symfony Console component for generating the command
line interface. You can take a look at the
:doc:`tools chapter <../reference/tools>` for inspiration how to setup the cli.
