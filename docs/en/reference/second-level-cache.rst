The Second Level Cache
======================

The Second Level Cache is designed to reduces the amount of necessary database access.
It sits between your application and the database to avoid the number of database hits as many as possible.

When this is turned on, entities will be first searched in cache and if they are not found, 
a database query will be fired an then the entity result will be stored in a cache provider.

There are some flavors of caching available, but is better to cache read-only data.

Be aware that caches are not aware of changes made to the persistent store by another application.
They can, however, be configured to regularly expire cached data.


Caching Regions
---------------

Second level cache does not store instances of an entity, instead it caches only entity identifier and values.
Each entity class, collection association and query has its region, where values of each instance are stored.

Caching Regions are specific region into the cache provider that might store entities, collection or queries.
Each cache region resides in a specific cache namespace and has its own lifetime configuration.

Something like below for an entity region :

.. code-block:: php

    <?php
    [
      'region_name:entity_1_hash' => ['id'=> 1, 'name' => 'FooBar', 'associationName'=>null],
      'region_name:entity_2_hash' => ['id'=> 2, 'name' => 'Foo', 'associationName'=>['id'=>11]],
      'region_name:entity_3_hash' => ['id'=> 3, 'name' => 'Bar', 'associationName'=>['id'=>22]]
    ];


If the entity holds a collection that also needs to be cached.
An collection region could look something like :

.. code-block:: php

    <?php
    [
      'region_name:entity_1_coll_assoc_name_hash' => ['ownerId'=> 1, 'list' => [1, 2, 3]],
      'region_name:entity_2_coll_assoc_name_hash' => ['ownerId'=> 2, 'list' => [2, 3]],
      'region_name:entity_3_coll_assoc_name_hash' => ['ownerId'=> 3, 'list' => [2, 4]]
    ];

A query region might be something like :

.. code-block:: php

    <?php
    [
      'region_name:query_1_hash' => ['list' => [1, 2, 3]],
      'region_name:query_2_hash' => ['list' => [2, 3]],
      'region_name:query_3_hash' => ['list' => [2, 4]]
    ];


.. note::

    Notice that when caching collection and queries only identifiers are stored.
    The entity values will be stored in its own region


.. _reference-second-level-cache-regions:

Cache Regions
-------------

``Doctrine\ORM\Cache\Region\DefaultRegion`` Its the default implementation.
 A simplest cache region compatible with all doctrine-cache drivers but does not support locking.

``Doctrine\ORM\Cache\Region`` and ``Doctrine\ORM\Cache\ConcurrentRegion``
Defines contracts that should be implemented by a cache provider.

It allows you to provide your own cache implementation that might take advantage of specific cache driver.

If you want to support locking for ``READ_WRITE`` strategies you should implement ``ConcurrentRegion``; ``CacheRegion`` otherwise.


``Doctrine\ORM\Cache\Region``

Defines a contract for accessing a particular cache region.

.. code-block:: php

    <?php

    interface Region
    {
        /**
         * Retrieve the name of this region.
         *
         * @return string The region name
         */
        public function getName();

        /**
         * Determine whether this region contains data for the given key.
         *
         * @param \Doctrine\ORM\Cache\CacheKey $key The cache key
         *
         * @return boolean
         */
        public function contains(CacheKey $key);

        /**
         * Get an item from the cache.
         *
         * @param \Doctrine\ORM\Cache\CacheKey $key The key of the item to be retrieved.
         *
         * @return \Doctrine\ORM\Cache\CacheEntry The cached entry or NULL
         */
        public function get(CacheKey $key);

        /**
         * Put an item into the cache.
         *
         * @param \Doctrine\ORM\Cache\CacheKey   $key   The key under which to cache the item.
         * @param \Doctrine\ORM\Cache\CacheEntry $entry The entry to cache.
         * @param \Doctrine\ORM\Cache\Lock       $lock  The lock previously obtained.
         */
        public function put(CacheKey $key, CacheEntry $entry, Lock $lock = null);

        /**
         * Remove an item from the cache.
         *
         * @param \Doctrine\ORM\Cache\CacheKey $key The key under which to cache the item.
         */
        public function evict(CacheKey $key);

        /**
         * Remove all contents of this particular cache region.
         */
        public function evictAll();
    }


``Doctrine\ORM\Cache\ConcurrentRegion``

Defines contract for concurrently managed data region.

.. code-block:: php

    <?php

    interface ConcurrentRegion extends Region
    {
       /**
        * Attempts to read lock the mapping for the given key.
        *
        * @param \Doctrine\ORM\Cache\CacheKey $key The key of the item to lock.
        *
        * @return \Doctrine\ORM\Cache\Lock A lock instance or NULL if the lock already exists.
        */
       public function readLock(CacheKey $key);

       /**
        * Attempts to read unlock the mapping for the given key.
        *
        * @param \Doctrine\ORM\Cache\CacheKey  $key  The key of the item to unlock.
        * @param \Doctrine\ORM\Cache\Lock      $lock The lock previously obtained from readLock
        */
       public function readUnlock(CacheKey $key, Lock $lock);
    }

Caching mode
------------

* ``READ_ONLY`` (DEFAULT)

  * Can do reads, inserts and deletes, cannot perform updates or employ any locks.
  * Useful for data that is read frequently but never updated.
  * Best performer.
  * It is Simple.

* ``NONSTRICT_READ_WRITE``

  * Read Write Cache doesn’t employ any locks but can do reads, inserts , updates and deletes.
  * Good if the application needs to update data rarely.
    

* ``READ_WRITE``

  * Read Write cache employs locks before update/delete.
  * Use if data needs to be updated.
  * Slowest strategy.
  * To use it a the cache region implementation must support locking.


Built-in cached persisters
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Cached persisters are responsible to access cache regions.

    +-----------------------+-------------------------------------------------------------------------------+
    | Cache Usage           | Persister                                                                     |
    +=======================+===============================================================================+
    | READ_ONLY             | Doctrine\\ORM\\Cache\\Persister\\ReadOnlyCachedEntityPersister                |
    +-----------------------+-------------------------------------------------------------------------------+
    | READ_WRITE            | Doctrine\\ORM\\Cache\\Persister\\ReadWriteCachedEntityPersister               |
    +-----------------------+-------------------------------------------------------------------------------+
    | NONSTRICT_READ_WRITE  | Doctrine\\ORM\\Cache\\Persister\\NonStrictReadWriteCachedEntityPersister      |
    +-----------------------+-------------------------------------------------------------------------------+
    | READ_ONLY             | Doctrine\\ORM\\Cache\\Persister\\ReadOnlyCachedCollectionPersister            |
    +-----------------------+-------------------------------------------------------------------------------+
    | READ_WRITE            | Doctrine\\ORM\\Cache\\Persister\\ReadWriteCachedCollectionPersister           |
    +-----------------------+-------------------------------------------------------------------------------+
    | NONSTRICT_READ_WRITE  | Doctrine\\ORM\\Cache\\Persister\\NonStrictReadWriteCacheCollectionPersister   |
    +-----------------------+-------------------------------------------------------------------------------+

Configuration
-------------
Doctrine allow you to specify configurations and some points of extension for the second-level-cache


Enable Second Level Cache Enabled
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

To Enable the cache second-level-cache you should provide a cache factory
``\Doctrine\ORM\Cache\DefaultCacheFactory`` is the default implementation.

.. code-block:: php

    <?php

    /* var $config \Doctrine\ORM\Configuration */
    /* var $cache \Doctrine\Common\Cache */

    $factory = new \Doctrine\ORM\Cache\DefaultCacheFactory($config, $cache);

    //Enable second-level-cache
    $config->setSecondLevelCacheEnabled();

    //Cache factory
    $config->setSecondLevelCacheFactory($factory);


Cache Factory
~~~~~~~~~~~~~

Cache Factory is the main point of extension.

It allows you to provide a specific implementation of the following components :

* ``QueryCache`` Store and retrieve query cache results.
* ``CachedEntityPersister`` Store and retrieve entity results.
* ``CachedCollectionPersister`` Store and retrieve query results.
* ``EntityHydrator``  Transform an entity into a cache entry and cache entry into entities
* ``CollectionHydrator`` Transform a collection into a cache entry and cache entry into collection

.. code-block:: php

    <?php

    interface CacheFactory
    {
        /**
        * Build an entity persister for the given entity metadata.
        *
        * @param \Doctrine\ORM\EntityManagerInterface     $em        The entity manager
        * @param \Doctrine\ORM\Persisters\EntityPersister $persister The entity persister
        * @param \Doctrine\ORM\Mapping\ClassMetadata      $metadata  The entity metadata
        *
        * @return \Doctrine\ORM\Cache\Persister\CachedEntityPersister
        */
       public function buildCachedEntityPersister(EntityManagerInterface $em, EntityPersister $persister, ClassMetadata $metadata);

       /**
        * Build a collection persister for the given relation mapping.
        *
        * @param \Doctrine\ORM\EntityManagerInterface         $em        The entity manager
        * @param \Doctrine\ORM\Persisters\CollectionPersister $persister The collection persister
        * @param array                                        $mapping   The association mapping
        *
        * @return \Doctrine\ORM\Cache\Persister\CachedCollectionPersister
        */
       public function buildCachedCollectionPersister(EntityManagerInterface $em, CollectionPersister $persister, $mapping);

       /**
        * Build a query cache based on the given region name
        *
        * @param \Doctrine\ORM\EntityManagerInterface $em         The Entity manager
        * @param string                               $regionName The region name
        *
        * @return \Doctrine\ORM\Cache\QueryCache The built query cache.
        */
       public function buildQueryCache(EntityManagerInterface $em, $regionName = null);

       /**
        * Build an entity hidrator
        *
        * @param \Doctrine\ORM\EntityManagerInterface $em       The Entity manager.
        * @param \Doctrine\ORM\Mapping\ClassMetadata  $metadata The entity metadata.
        *
        * @return \Doctrine\ORM\Cache\EntityHydrator The built entity hidrator.
        */
       public function buildEntityHydrator(EntityManagerInterface $em, ClassMetadata $metadata);

       /**
        * Build a collection hidrator
        *
        * @param \Doctrine\ORM\EntityManagerInterface $em      The Entity manager.
        * @param array                                $mapping The association mapping.
        *
        * @return \Doctrine\ORM\Cache\CollectionHydrator The built collection hidrator.
        */
       public function buildCollectionHydrator(EntityManagerInterface $em, array $mapping);

       /**
        * Gets a cache region based on its name.
        *
        * @param array $cache The cache configuration.
        *
        * @return \Doctrine\ORM\Cache\Region The cache region.
        */
       public function getRegion(array $cache);
    }

Region Lifetime
~~~~~~~~~~~~~~~

To specify a default lifetime for all regions or specify a different lifetime for a specific region.

.. code-block:: php

    <?php

    /* var $config \Doctrine\ORM\Configuration /*

    //Cache Region lifetime
    $config->setSecondLevelCacheRegionLifetime('my_entity_region', 3600);
    $config->setSecondLevelCacheDefaultRegionLifetime(7200);


Cache Log
~~~~~~~~~
By providing a cache logger you should be able to get information about all cache operations such as hits, miss put.

``\Doctrine\ORM\Cache\Logging\StatisticsCacheLogger`` is a built-in implementation that provides basic statistics.

 .. code-block:: php

    <?php

    /* var $config \Doctrine\ORM\Configuration /*
    $logger = \Doctrine\ORM\Cache\Logging\StatisticsCacheLogger();

    //Cache logger
    $config->setSecondLevelCacheLogger($logger);


    // Collect cache statistics

    // Get the number of entries successfully retrieved from a specific region.
    $logger->getRegionHitCount('my_entity_region');

    // Get the number of cached entries *not* found in a specific region.
    $logger->getRegionMissCount('my_entity_region');

    // Get the number of cacheable entries put in cache.
    $logger->getRegionPutCount('my_entity_region');

    // Get the total number of put in all regions.
    $logger->getPutCount();

    //  Get the total number of entries successfully retrieved from all regions.
    $logger->getHitCount();

    //  Get the total number of cached entries *not* found in all regions.
    $logger->getMissCount();

If you want to get more information you should implement ``\Doctrine\ORM\Cache\Logging\CacheLogger``.
and collect all information you want.

 .. code-block:: php

    <?php

    /**
     * Log an entity put into second level cache.
     *
     * @param string            $regionName The name of the cache region.
     * @param EntityCacheKey    $key        The cache key of the entity.
     */
    public function entityCachePut($regionName, EntityCacheKey $key);

    /**
     * Log an entity get from second level cache resulted in a hit.
     *
     * @param string            $regionName The name of the cache region.
     * @param EntityCacheKey    $key        The cache key of the entity.
     */
    public function entityCacheHit($regionName, EntityCacheKey $key);

    /**
     * Log an entity get from second level cache resulted in a miss.
     *
     * @param string             $regionName The name of the cache region.
     * @param \EntityCacheKey    $key        The cache key of the entity.
     */
    public function entityCacheMiss($regionName, EntityCacheKey $key);

     /**
     * Log an entity put into second level cache.
     *
     * @param string                $regionName The name of the cache region.
     * @param CollectionCacheKey    $key        The cache key of the collection.
     */
    public function collectionCachePut($regionName, CollectionCacheKey $key);

    /**
     * Log an entity get from second level cache resulted in a hit.
     *
     * @param string                $regionName The name of the cache region.
     * @param CollectionCacheKey    $key        The cache key of the collection.
     */
    public function collectionCacheHit($regionName, CollectionCacheKey $key);

    /**
     * Log an entity get from second level cache resulted in a miss.
     *
     * @param string                 $regionName The name of the cache region.
     * @param \CollectionCacheKey    $key        The cache key of the collection.
     */
    public function collectionCacheMiss($regionName, CollectionCacheKey $key);

    /**
     * Log a query put into the query cache.
     *
     * @param string                 $regionName The name of the cache region.
     * @param QueryCacheKey          $key        The cache key of the query.
     */
    public function queryCachePut($regionName, QueryCacheKey $key);

    /**
     * Log a query get from the query cache resulted in a hit.
     *
     * @param string                 $regionName The name of the cache region.
     * @param \QueryCacheKey         $key        The cache key of the query.
     */
    public function queryCacheHit($regionName, QueryCacheKey $key);

    /**
     * Log a query get from the query cache resulted in a miss.
     *
     * @param string                 $regionName The name of the cache region.
     * @param QueryCacheKey          $key        The cache key of the query.
     */
    public function queryCacheMiss($regionName, QueryCacheKey $key);


Entity cache definition
-----------------------
* Entity cache configuration allows you to define the caching strategy and region for an entity.

  * ``usage`` Specifies the caching strategy: ``READ_ONLY``, ``NONSTRICT_READ_WRITE``, ``READ_WRITE``
  * ``region`` Specifies the name of the second level cache region.


.. configuration-block::

    .. code-block:: php

        <?php
        /**
         * @Entity
         * @Cache(usage="READ_ONLY", region="my_entity_region")
         */
        class Country
        {
            /**
             * @Id
             * @GeneratedValue
             * @Column(type="integer")
             */
            protected $id;

            /**
             * @Column(unique=true)
             */
            protected $name;

            // other properties and methods
        }

    .. code-block:: xml

        <?xml version="1.0" encoding="utf-8"?>
        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping http://doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
          <entity name="Country">
            <cache usage="READ_ONLY" region="my_entity_region" />
            <id name="id" type="integer" column="id">
              <generator strategy="IDENTITY"/>
            </id>
            <field name="name" type="string" column="name"/>
          </entity>
        </doctrine-mapping>

    .. code-block:: yaml

        Country:
          type: entity
          cache:
            usage : READ_ONLY
            region : my_entity_region
          id:
            id:
              type: integer
              id: true
              generator:
                strategy: IDENTITY
          fields:
            name:
              type: string


Association cache definition
----------------------------
The most common use case is to cache entities. But we can also cache relationships.
It caches the primary keys of association and cache each element will be cached into its region.


.. configuration-block::

    .. code-block:: php

        <?php
        /**
         * @Entity
         * @Cache("NONSTRICT_READ_WRITE")
         */
        class State
        {
            /**
             * @Id
             * @GeneratedValue
             * @Column(type="integer")
             */
            protected $id;

            /**
             * @Column(unique=true)
             */
            protected $name;

            /**
             * @Cache("NONSTRICT_READ_WRITE")
             * @ManyToOne(targetEntity="Country")
             * @JoinColumn(name="country_id", referencedColumnName="id")
             */
            protected $country;

            /**
             * @Cache("NONSTRICT_READ_WRITE")
             * @OneToMany(targetEntity="City", mappedBy="state")
             */
            protected $cities;

            // other properties and methods
        }

    .. code-block:: xml

        <?xml version="1.0" encoding="utf-8"?>
        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping http://doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
          <entity name="State">

            <cache usage="NONSTRICT_READ_WRITE" />

            <id name="id" type="integer" column="id">
              <generator strategy="IDENTITY"/>
            </id>

            <field name="name" type="string" column="name"/>
            
            <many-to-one field="country" target-entity="Country">
              <cache usage="NONSTRICT_READ_WRITE" />

              <join-columns>
                <join-column name="country_id" referenced-column-name="id"/>
              </join-columns>
            </many-to-one>

            <one-to-many field="cities" target-entity="City" mapped-by="state">
              <cache usage="NONSTRICT_READ_WRITE"/>
            </one-to-many>
          </entity>
        </doctrine-mapping>

    .. code-block:: yaml

        State:
          type: entity
          cache:
            usage : NONSTRICT_READ_WRITE
          id:
            id:
              type: integer
              id: true
              generator:
                strategy: IDENTITY
          fields:
            name:
              type: string

          manyToOne:
            state:
              targetEntity: Country
              joinColumns:
                country_id:
                  referencedColumnName: id
              cache:
                usage : NONSTRICT_READ_WRITE

          oneToMany:
            cities:
              targetEntity:City
              mappedBy: state
              cache:
                usage : NONSTRICT_READ_WRITE


Cache usage
~~~~~~~~~~~

Basic entity cache

.. code-block:: php

    <?php

    $em->persist(new Country($name));
    $em->flush();                         // Hit database to insert the row and put into cache

    $em->clear();                         // Clear entity manager

    $country   = $em->find('Country', 1); // Retrieve item from cache

    $country->setName("New Name");
    $em->persist($state);
    $em->flush();                         // Hit database to update the row and update cache

    $em->clear();                         // Clear entity manager

    $country   = $em->find('Country', 1); // Retrieve item from cache


Association cache

.. code-block:: php

    <?php

    // Hit database to insert the row and put into cache
    $em->persist(new State($name, $country));
    $em->flush();

    // Clear entity manager
    $em->clear();

    // Retrieve item from cache
    $state = $em->find('State', 1);

    // Hit database to update the row and update cache entry
    $state->setName("New Name");
    $em->persist($state);
    $em->flush();

    // Create a new collection item
    $city = new City($name, $state);
    $state->addCity($city);

    // Hit database to insert new collection item,
    // put entity and collection cache into cache.
    $em->persist($city);
    $em->persist($state);
    $em->flush();

    // Clear entity manager
    $em->clear();

    // Retrieve item from cache
    $state = $em->find('State', 1);

    // Retrieve association from cache
    $country = $state->getCountry();

    // Retrieve collection from cache
    $cities = $state->getCities();

    echo $country->getName();
    echo $state->getName();

    // Retrieve each collection item from cache
    foreach ($cities as $city) {
        echo $city->getName();
    }

.. note::

    Notice that all entities should be marked as cacheable.

Using the query cache
---------------------

The second level cache stores the entities, associations and collections.
The query cache stores the results of the query but as identifiers,
The entity values are actually stored in the 2nd level cache.
So, query cache is useless without a 2nd level cache.

.. code-block:: php

    <?php

        /* var $em \Doctrine\ORM\EntityManager */

        // Execute database query, store query cache and entity cache
        $result1 = $em->createQuery('SELECT c FROM Country c ORDER BY c.name')
            ->setCacheable(true)
            ->getResult();

        // Check if query result is valid and load entities from cache
        $result2 = $em->createQuery('SELECT c FROM Country c ORDER BY c.name')
            ->setCacheable(true)
            ->getResult();


Cache API
---------

Caches are not aware of changes made by another application.
however, you can use the cache API to check / invalidate cache entries.

.. code-block:: php

    <?php

    /* var $cache \Doctrine\ORM\Cache */
    $cache = $em->getCache();

    $cache->containsEntity('State', 1)      // Check if the cache exists
    $cache)->evictEntity('State', 1);       // Remove an entity from cache
    $cache->evictEntityRegion('State');     // Remove all entities from cache

    $cache->containsCollection('State', 'cities', 1);   // Check if the cache exists
    $cache->evictCollection('State', 'cities', 1);      // Remove an entity collection from cache
    $cache->evictCollectionRegion('State', 'cities');   // Remove all collections from cache

Limitations
-----------

Composite primary key
~~~~~~~~~~~~~~~~~~~~~

.. note::
    Composite primary key are supported by second level cache,
    However when one of the keys is an association
    the cached entity should always be retrieved using the association identifier.

.. code-block:: php

    <?php
    /**
     * @Entity
     */
    class Reference
    {
        /**
         * @Id
         * @ManyToOne(targetEntity="Article", inversedBy="references")
         * @JoinColumn(name="source_id", referencedColumnName="article_id")
         */
        private $source;

        /**
         * @Id
         * @ManyToOne(targetEntity="Article")
         * @JoinColumn(name="target_id", referencedColumnName="article_id")
         */
        private $target;
    }

    // Supported
    $id        = array('source' => 1, 'target' => 2);
    $reference = $this->_em->find("Reference", $id);

    // NOT Supported
    $id        = array('source' => new Article(1), 'target' => new Article(2));
    $reference = $this->_em->find("Reference", $id);


Concurrent cache region
~~~~~~~~~~~~~~~~~~~~~~~

A ``Doctrine\\ORM\\Cache\\ConcurrentRegion`` is designed to store concurrently managed data region.
However doctrine provide an very simple implementation based on file locks ``Doctrine\\ORM\\Cache\\Region\\FileLockRegion``.

If you want to use an ``READ_WRITE`` cache, you should consider providing your own cache region.
for more details about how to implement a cache region please see :ref:`reference-second-level-cache-regions`