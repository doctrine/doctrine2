Working with Objects
====================

In this chapter we will help you understand the ``EntityManager``
and the ``UnitOfWork``. A Unit of Work is similar to an
object-level transaction. A new Unit of Work is implicitly started
when an EntityManager is initially created or after
``EntityManager#flush()`` has been invoked. A Unit of Work is
committed (and a new one started) by invoking
``EntityManager#flush()``.

A Unit of Work can be manually closed by calling
EntityManager#close(). Any changes to objects within this Unit of
Work that have not yet been persisted are lost.

.. note::

    It is very important to understand that only
    ``EntityManager#flush()`` ever causes write operations against the
    database to be executed. Any other methods such as
    ``EntityManager#persist($entity)`` or
    ``EntityManager#remove($entity)`` only notify the UnitOfWork to
    perform these operations during flush.

    Not calling ``EntityManager#flush()`` will lead to all changes
    during that request being lost.

.. note::

    Doctrine NEVER touches the public API of methods in your entity
    classes (like getters and setters) nor the constructor method.
    Instead, it uses reflection to get/set data from/to your entity objects.
    When Doctrine fetches data from DB and saves it back,
    any code put in your get/set methods won't be implicitly taken into account.

Entities and the Identity Map
-----------------------------

Entities are objects with identity. Their identity has a conceptual
meaning inside your domain. In a CMS application each article has a
unique id. You can uniquely identify each article by that id.

Take the following example, where you find an article with the
headline "Hello World" with the ID 1234:

.. code-block:: php

    <?php
    $article = $entityManager->find('CMS\Article', 1234);
    $article->setHeadline('Hello World dude!');

    $article2 = $entityManager->find('CMS\Article', 1234);
    echo $article2->getHeadline();

In this case the Article is accessed from the entity manager twice,
but modified in between. Doctrine ORM realizes this and will only
ever give you access to one instance of the Article with ID 1234,
no matter how often do you retrieve it from the EntityManager and
even no matter what kind of Query method you are using (find,
Repository Finder or DQL). This is called "Identity Map" pattern,
which means Doctrine keeps a map of each entity and ids that have
been retrieved per PHP request and keeps returning you the same
instances.

In the previous example the echo prints "Hello World dude!" to the
screen. You can even verify that ``$article`` and ``$article2`` are
indeed pointing to the same instance by running the following
code:

.. code-block:: php

    <?php
    if ($article === $article2) {
        echo "Yes we are the same!";
    }

The identity map is only consulted when an entity is looked up by its
identifier, with ``EntityManager#find()``, ``EntityManager#getReference()``
or ``EntityRepository#find()``. All other ways of retrieving entities, like
``findBy()``, ``findOneBy()``, ``count()``, ``matching()`` or DQL queries, are never
answered from the identity map alone. They send a query to the database, unless the
result is served from the :doc:`second-level cache <second-level-cache>` or a result
cache. The identity map is then only used to return the already known instance for
each row of the result.

This has consequences for entities whose state has not been flushed yet:

-  A new entity that was passed to ``persist()`` is only added to the identity
   map when its identifier is known before the flush, for example with an
   assigned identifier or a sequence based generator. Such an entity can be returned by ``find()`` before
   ``flush()`` is called, while ``findBy()`` and DQL queries will not find it,
   because it is not in the database yet.
-  Changes to managed entities that were not flushed yet are not taken into
   account by queries. ``findBy(['headline' => 'Hello World dude!'])`` in the
   example above does not return the modified article before ``flush()``, but
   if the query finds the article by its old headline, the returned instance
   contains the modified headline.
-  Entities that were passed to ``remove()`` can still be returned by queries
   until the removal is flushed.

Call ``EntityManager#flush()`` before running queries when they should take
pending changes into account.

Sometimes you want to clear the identity map of an EntityManager to
start over. We use this regularly in our unit-tests to enforce
loading objects from the database again instead of serving them
from the identity map. You can call ``EntityManager#clear()`` to
achieve this result.

Entity Object Graph Traversal
-----------------------------

Although Doctrine allows for a complete separation of your domain
model (Entity classes) there will never be a situation where
objects are "missing" when traversing associations. You can walk
all the associations inside your entity models as deep as you
want.

Take the following example of a single ``Article`` entity fetched
from newly opened EntityManager.

.. code-block:: php

    <?php
    #[Entity]
    class Article
    {
        #[Id, Column(type: 'integer'), GeneratedValue]
        private int|null $id = null;

        #[Column(type: 'string')]
        private string $headline;

        #[ManyToOne(targetEntity: User::class)]
        private User|null $author = null;

        /** @var Collection<int, Comment> */
        #[OneToMany(targetEntity: Comment::class, mappedBy: 'article')]
        private Collection $comments;

        public function __construct()
        {
            $this->comments = new ArrayCollection();
        }

        public function getAuthor(): User|null { return $this->author; }
        public function getComments(): Collection { return $this->comments; }
    }

    $article = $em->find('Article', 1);

This code only retrieves the ``Article`` instance with id 1 executing
a single SELECT statement against the articles table in the database.
You can still access the associated properties author and comments
and the associated objects they contain.

This works by utilizing the lazy loading pattern. Instead of
passing you back a real Author instance and a collection of
comments Doctrine will create proxy instances for you. Only if you
access these proxies for the first time they will go through the
EntityManager and load their state from the database.

This lazy-loading process happens behind the scenes, hidden from
your code. See the following code:

.. code-block:: php

    <?php
    $article = $em->find('Article', 1);

    // accessing a method of the user instance triggers the lazy-load
    echo "Author: " . $article->getAuthor()->getName() . "\n";

    // Lazy Loading Proxies pass instanceof tests:
    if ($article->getAuthor() instanceof User) {
        // a User Proxy is a generated "UserProxy" class
    }

    // accessing the comments as an iterator triggers the lazy-load
    // retrieving ALL the comments of this article from the database
    // using a single SELECT statement
    foreach ($article->getComments() as $comment) {
        echo $comment->getText() . "\n\n";
    }

    // Article::$comments passes instanceof tests for the Collection interface
    // But it will NOT pass for the ArrayCollection interface
    if ($article->getComments() instanceof \Doctrine\Common\Collections\Collection) {
        echo "This will always be true!";
    }

.. warning::

    Traversing the object graph for parts that are lazy-loaded will
    easily trigger lots of SQL queries and will perform badly if used
    too heavily. Make sure to use DQL to fetch-join all the parts of the
    object-graph that you need as efficiently as possible.


Persisting entities
-------------------

An entity can be made persistent by passing it to the
``EntityManager#persist($entity)`` method. By applying the persist
operation on some entity, that entity becomes MANAGED, which means
that its persistence is from now on managed by an EntityManager. As
a result the persistent state of such an entity will subsequently
be properly synchronized with the database when
``EntityManager#flush()`` is invoked.

.. note::

    Invoking the ``persist`` method on an entity does NOT
    cause an immediate SQL INSERT to be issued on the database.
    Doctrine applies a strategy called "transactional write-behind",
    which means that it will delay most SQL commands until
    ``EntityManager#flush()`` is invoked which will then issue all
    necessary SQL statements to synchronize your objects with the
    database in the most efficient way and a single, short transaction,
    taking care of maintaining referential integrity.

.. note::

    Do not make any assumptions in your code about the number of queries
    it takes to flush changes, about the ordering of ``INSERT``, ``UPDATE``
    and ``DELETE`` queries or the order in which entities will be processed.

Example:

.. code-block:: php

    <?php
    $user = new User;
    $user->setName('Mr.Right');
    $em->persist($user);
    $em->flush();

.. note::

    Generated entity identifiers / primary keys are
    guaranteed to be available after the next successful flush
    operation that involves the entity in question. You can not rely on
    a generated identifier to be available directly after invoking
    ``persist``. The inverse is also true. You can not rely on a
    generated identifier being not available after a failed flush
    operation.


The semantics of the persist operation, applied on an entity X, are
as follows:


-  If X is a new entity, it becomes managed. The entity X will be
   entered into the database as a result of the flush operation.
-  If X is a preexisting managed entity, it is ignored by the
   persist operation. However, the persist operation is cascaded to
   entities referenced by X, if the relationships from X to these
   other entities are mapped with cascade=PERSIST or cascade=ALL (see
   ":ref:`transitive-persistence`").
-  If X is a removed entity, it becomes managed.
-  If X is a detached entity, an exception will be thrown on
   flush.

.. caution::

    Do not pass detached entities to the persist operation. The persist operation always
    considers entities that are not yet known to the ``EntityManager`` as new entities
    (refer to the ``STATE_NEW`` constant inside the ``UnitOfWork``).

Removing entities
-----------------

An entity can be removed from persistent storage by passing it to
the ``EntityManager#remove($entity)`` method. By applying the
``remove`` operation on some entity, that entity becomes REMOVED,
which means that its persistent state will be deleted once
``EntityManager#flush()`` is invoked.

.. note::

    Just like ``persist``, invoking ``remove`` on an entity
    does NOT cause an immediate SQL DELETE to be issued on the
    database. The entity will be deleted on the next invocation of
    ``EntityManager#flush()`` that involves that entity. This
    means that entities scheduled for removal can still be queried
    for and appear in query and collection results. See
    the section on :ref:`Database and UnitOfWork Out-Of-Sync <workingobjects_database_uow_outofsync>`
    for more information.


Example:

.. code-block:: php

    <?php
    $em->remove($user);
    $em->flush();

The semantics of the remove operation, applied to an entity X are
as follows:


-  If X is a new entity, it is ignored by the remove operation.
   However, the remove operation is cascaded to entities referenced by
   X, if the relationship from X to these other entities is mapped
   with cascade=REMOVE or cascade=ALL (see ":ref:`transitive-persistence`").
-  If X is a managed entity, the remove operation causes it to
   become removed. The remove operation is cascaded to entities
   referenced by X, if the relationships from X to these other
   entities is mapped with cascade=REMOVE or cascade=ALL (see
   ":ref:`transitive-persistence`").
-  If X is a detached entity, an InvalidArgumentException will be
   thrown.
-  If X is a removed entity, it is ignored by the remove operation.
-  A removed entity X will be removed from the database as a result
   of the flush operation.

After an entity has been removed, its in-memory state is the same as
before the removal, except for generated identifiers.

During the ``EntityManager#flush()`` operation, the removed entity
will also be removed from all collections in entities currently
loaded into memory.

.. _remove_object_many_to_many_join_tables:

Join-table management when removing from many-to-many collections
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Regarding existing rows in many-to-many join tables that refer to
an entity being removed, the following applies.

When the entity being removed does not declare the many-to-many association
itself (that is, the many-to-many association is unidirectional and
the entity is on the inverse side), the ORM has no reasonable way to
detect associations targeting the entity's class. Thus, no ORM-level handling
of join-table rows is attempted and database-level constraints apply.
In case of database-level ``ON DELETE RESTRICT`` constraints, the
``EntityManager#flush()`` operation may abort and a ``ConstraintViolationException``
may be thrown. No in-memory collections will be modified in this case.
With ``ON DELETE CASCADE``, the RDBMS will take care of removing rows
from join tables.

When the entity being removed is part of bi-directional many-to-many
association, either as the owning or inverse side, the ORM will
delete rows from join tables before removing the entity itself. That means
database-level ``ON DELETE RESTRICT`` constraints on join tables are not
effective, since the join table rows are removed first. Removal of join table
rows happens through specialized methods in entity and collection persister
classes and take one query per entity and join table. In case the association
uses a ``@JoinColumn`` configuration with ``onDelete="CASCADE"``, instead
of using a dedicated ``DELETE`` query the database-level operation will be
relied upon.

.. note::

    In case you rely on database-level ``ON DELETE RESTRICT`` constraints,
    be aware that by making many-to-many associations bidirectional the
    assumed protection may be lost.


Performance of different deletion strategies
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Deleting an object with all its associated objects can be achieved
in multiple ways with very different performance impacts.

1. If an association is marked as ``CASCADE=REMOVE`` Doctrine ORM will
   fetch this association. If it's a Single association it will pass
   this entity to ``EntityManager#remove()``. If the association is a
   collection, Doctrine will loop over all its elements and pass them to
   ``EntityManager#remove()``.
   In both cases the cascade remove semantics are applied recursively.
   For large object graphs this removal strategy can be very costly.
2. Using a DQL ``DELETE`` statement allows you to delete multiple
   entities of a type with a single command and without hydrating
   these entities. This can be very efficient to delete large object
   graphs from the database.
3. Using foreign key semantics ``onDelete="CASCADE"`` can force the
   database to remove all associated objects internally. This strategy
   is a bit tricky to get right but can be very powerful and fast. You
   should be aware however that using strategy 1 (``CASCADE=REMOVE``)
   completely by-passes any foreign key ``onDelete=CASCADE`` option,
   because Doctrine will fetch and remove all associated entities
   explicitly nevertheless.

.. note::

    Calling ``remove`` on an entity will remove the object from the identity
    map and therefore detach it. Querying the same entity again, for example
    via a lazy loaded relation, will return a new object.


Detaching entities
------------------

An entity is detached from an EntityManager and thus no longer
managed by invoking the ``EntityManager#detach($entity)`` method on
it or by cascading the detach operation to it. Changes made to the
detached entity, if any (including removal of the entity), will not
be synchronized to the database after the entity has been
detached.

Doctrine will not hold on to any references to a detached entity.

Example:

.. code-block:: php

    <?php
    $em->detach($entity);

The semantics of the detach operation, applied to an entity X are
as follows:


-  If X is a managed entity, the detach operation causes it to
   become detached. The detach operation is cascaded to entities
   referenced by X, if the relationships from X to these other
   entities is mapped with cascade=DETACH or cascade=ALL (see
   ":ref:`transitive-persistence`"). Entities which previously referenced X
   will continue to reference X.
-  If X is a new or detached entity, it is ignored by the detach
   operation.
-  If X is a removed entity, the detach operation is cascaded to
   entities referenced by X, if the relationships from X to these
   other entities is mapped with cascade=DETACH or cascade=ALL (see
   ":ref:`transitive-persistence`"). Entities which previously referenced X
   will continue to reference X.

There are several situations in which an entity is detached
automatically without invoking the ``detach`` method:


-  When ``EntityManager#clear()`` is invoked, all entities that are
   currently managed by the EntityManager instance become detached.
-  When serializing an entity. The entity retrieved upon subsequent
   unserialization will be detached (This is the case for all entities
   that are serialized and stored in some cache).

The ``detach`` operation is usually not as frequently needed and
used as ``persist`` and ``remove``.


Synchronization with the Database
---------------------------------

The state of persistent entities is synchronized with the database
on flush of an ``EntityManager`` which commits the underlying
``UnitOfWork``. The synchronization involves writing any updates to
persistent entities and their relationships to the database.
Thereby bidirectional relationships are persisted based on the
references held by the owning side of the relationship as explained
in the Association Mapping chapter.

When ``EntityManager#flush()`` is called, Doctrine inspects all
managed, new and removed entities and will perform the following
operations.

.. _workingobjects_database_uow_outofsync:

Effects of Database and UnitOfWork being Out-Of-Sync
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

As soon as you begin to change the state of entities, call persist or remove the
contents of the UnitOfWork and the database will drive out of sync. They can
only be synchronized by calling ``EntityManager#flush()``. This section
describes the effects of database and UnitOfWork being out of sync.

-  Entities that are scheduled for removal can still be queried from the database.
   They are returned from DQL and Repository queries and are visible in collections.
-  Entities that are passed to ``EntityManager#persist`` do not turn up in query
   results.
-  Entities that have changed will not be overwritten with the state from the database.
   This is because the identity map will detect the construction of an already existing
   entity and assumes its the most up to date version.

``EntityManager#flush()`` is never called implicitly by Doctrine. You always have to trigger it manually.

Synchronizing New and Managed Entities
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The flush operation applies to a managed entity with the following
semantics:


-  The entity itself is synchronized to the database using a SQL
   UPDATE statement, only if at least one persistent field has
   changed.
-  No SQL updates are executed if the entity did not change.

The flush operation applies to a new entity with the following
semantics:


-  The entity itself is synchronized to the database using a SQL
   INSERT statement.

For all (initialized) relationships of the new or managed entity
the following semantics apply to each associated entity X:


-  If X is new and persist operations are configured to cascade on
   the relationship, X will be persisted.
-  If X is new and no persist operations are configured to cascade
   on the relationship, an exception will be thrown as this indicates
   a programming error.
-  If X is removed and persist operations are configured to cascade
   on the relationship, an exception will be thrown as this indicates
   a programming error (X would be re-persisted by the cascade).
-  If X is detached and persist operations are configured to
   cascade on the relationship, an exception will be thrown (This is
   semantically the same as passing X to persist()).

Synchronizing Removed Entities
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The flush operation applies to a removed entity by deleting its
persistent state from the database. No cascade options are relevant
for removed entities on flush, the cascade remove option is already
executed during ``EntityManager#remove($entity)``.

The size of a Unit of Work
~~~~~~~~~~~~~~~~~~~~~~~~~~

The size of a Unit of Work mainly refers to the number of managed
entities at a particular point in time.

The cost of flushing
~~~~~~~~~~~~~~~~~~~~

How costly a flush operation is, mainly depends on two factors:


-  The size of the EntityManager's current UnitOfWork.
-  The configured change tracking policies

You can get the size of a UnitOfWork as follows:

.. code-block:: php

    <?php
    $uowSize = $em->getUnitOfWork()->size();

The size represents the number of managed entities in the Unit of
Work. This size affects the performance of flush() operations due
to change tracking (see "Change Tracking Policies") and, of course,
memory consumption, so you may want to check it from time to time
during development.

.. note::

    Do not invoke ``flush`` after every change to an entity
    or every single invocation of persist/remove/... This is an
    anti-pattern and unnecessarily reduces the performance of your
    application. Instead, form units of work that operate on your
    objects and call ``flush`` when you are done. While serving a
    single HTTP request there should be usually no need for invoking
    ``flush`` more than 0-2 times.


Direct access to a Unit of Work
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

You can get direct access to the Unit of Work by calling
``EntityManager#getUnitOfWork()``. This will return the UnitOfWork
instance the EntityManager is currently using.

.. code-block:: php

    <?php
    $uow = $em->getUnitOfWork();

.. note::

    Directly manipulating a UnitOfWork is not recommended.
    When working directly with the UnitOfWork API, respect methods
    marked as INTERNAL by not using them and carefully read the API
    documentation.


Entity State
~~~~~~~~~~~~

As outlined in the architecture overview an entity can be in one of
four possible states: NEW, MANAGED, REMOVED, DETACHED. If you
explicitly need to find out what the current state of an entity is
in the context of a certain ``EntityManager`` you can ask the
underlying ``UnitOfWork``:

.. code-block:: php

    <?php
    switch ($em->getUnitOfWork()->getEntityState($entity)) {
        case UnitOfWork::STATE_MANAGED:
            ...
        case UnitOfWork::STATE_REMOVED:
            ...
        case UnitOfWork::STATE_DETACHED:
            ...
        case UnitOfWork::STATE_NEW:
            ...
    }

An entity is in MANAGED state if it is associated with an
``EntityManager`` and it is not REMOVED.

An entity is in REMOVED state after it has been passed to
``EntityManager#remove()`` until the next flush operation of the
same EntityManager. A REMOVED entity is still associated with an
``EntityManager`` until the next flush operation.

An entity is in DETACHED state if it has persistent state and
identity but is currently not associated with an
``EntityManager``.

An entity is in NEW state if has no persistent state and identity
and is not associated with an ``EntityManager`` (for example those
just created via the "new" operator).

Querying
--------

Doctrine ORM provides the following ways, in increasing level of
power and flexibility, to query for persistent objects. You should
always start with the simplest one that suits your needs.

By Primary Key
~~~~~~~~~~~~~~

The most basic way to query for a persistent object is by its
identifier / primary key using the
``EntityManager#find($entityName, $id)`` method. Here is an
example:

.. code-block:: php

    <?php
    // $em instanceof EntityManager
    $user = $em->find('MyProject\Domain\User', $id);

The return value is either the found entity instance or null if no
instance could be found with the given identifier.

Essentially, ``EntityManager#find()`` is just a shortcut for the
following:

.. code-block:: php

    <?php
    // $em instanceof EntityManager
    $user = $em->getRepository('MyProject\Domain\User')->find($id);

``EntityManager#getRepository($entityName)`` returns a repository
object which provides many ways to retrieve entities of the
specified type. By default, the repository instance is of type
``Doctrine\ORM\EntityRepository``. You can also use custom
repository classes as shown later.

By Simple Conditions
~~~~~~~~~~~~~~~~~~~~

To query for one or more entities based on several conditions that
form a logical conjunction, use the ``findBy`` and ``findOneBy``
methods on a repository as follows:

.. code-block:: php

    <?php
    // $em instanceof EntityManager

    // All users that are 20 years old
    $users = $em->getRepository('MyProject\Domain\User')->findBy(array('age' => 20));

    // All users that are 20 years old and have a surname of 'Miller'
    $users = $em->getRepository('MyProject\Domain\User')->findBy(array('age' => 20, 'surname' => 'Miller'));

    // A single user by its nickname
    $user = $em->getRepository('MyProject\Domain\User')->findOneBy(array('nickname' => 'romanb'));

You can also load by owning side associations through the repository:

.. code-block:: php

    <?php
    $number = $em->find('MyProject\Domain\Phonenumber', 1234);
    $user = $em->getRepository('MyProject\Domain\User')->findOneBy(array('phone' => $number->getId()));

The ``EntityRepository#findBy()`` method additionally accepts orderings, limit and offset as second to fourth parameters:

.. code-block:: php

    <?php
    $tenUsers = $em->getRepository('MyProject\Domain\User')->findBy(array('age' => 20), array('name' => 'ASC'), 10, 0);

The ``findBy()`` and ``findOneBy()`` methods also accept ``\SortDirection``
values as sort directions, which can be mixed with ``'ASC'`` / ``'DESC'``
strings:

.. code-block:: php

    <?php
    $tenUsers = $em->getRepository('MyProject\Domain\User')
        ->findBy(array('age' => 20), array('name' => \SortDirection::Descending), 10, 0);

Unlike ``QueryBuilder#orderBy()`` and the mapped ``OrderBy`` attribute,
repository methods do not deprecate string sort directions: they must keep
accepting strings while ``doctrine/persistence`` 3.x and 4.x document them as
the only statically valid values. Both forms are therefore supported.

If you pass an array of values Doctrine will convert the query into a WHERE field IN (..) query automatically:

.. code-block:: php

    <?php
    $users = $em->getRepository('MyProject\Domain\User')->findBy(array('age' => array(20, 30, 40)));
    // translates roughly to: SELECT * FROM users WHERE age IN (20, 30, 40)

Additionally, you can just count the result of the provided conditions when you don't really need the data:

.. code-block:: php

    <?php
    // Check there is no user with nickname
    $availableNickname = 0 === $em->getRepository('MyProject\Domain\User')->count(['nickname' => 'nonexistent']);

.. _reference-magic-finders:

Magic Finders
^^^^^^^^^^^^^

An ``EntityRepository`` also provides "magic finders" through its ``__call()``
method: calls to methods that start with ``findBy``, ``findOneBy`` or ``countBy``
followed by the name of a field or association are translated to calls of
``findBy()``, ``findOneBy()`` and ``count()`` with a single criterion on that field.
The part after the prefix is converted to camel case to determine the field name,
so ``findOneByNickname`` and ``findOneByUserName`` look for the fields ``nickname``
and ``userName``.

.. code-block:: php

    <?php
    $repository = $em->getRepository('MyProject\Domain\User');

    // Equivalent to $repository->findOneBy(['nickname' => 'romanb'])
    $user = $repository->findOneByNickname('romanb');

    // Equivalent to $repository->findBy(['age' => 20], ['name' => 'ASC'], 10, 0)
    $users = $repository->findByAge(20, ['name' => 'ASC'], 10, 0);

    // Equivalent to $repository->count(['status' => 'active'])
    $activeUsers = $repository->countByStatus('active');

The first argument is the value to search for. Any further arguments are passed on
to the underlying method, for example orderings, limit and offset for ``findBy()``.

A magic finder throws a ``Doctrine\ORM\Repository\Exception\InvalidMagicMethodCall``
exception when it is called without a value or when the entity has no field or
association with that name, and a ``BadMethodCallException`` when the method name
does not start with one of the supported prefixes.

Magic finders can only search on a single field. Since they are resolved at runtime,
IDEs and static analysis tools do not know about them unless you declare them with
``@method`` annotations on a custom repository class. For full IDE and static
analysis support, call ``findBy()``, ``findOneBy()`` or ``count()`` directly, or
add an explicit method to a
:ref:`custom repository <working-with-objects-custom-repositories>`.

By Criteria
~~~~~~~~~~~

The Repository implement the ``Doctrine\Common\Collections\Selectable``
interface. That means you can build ``Doctrine\Common\Collections\Criteria``
and pass them to the ``matching($criteria)`` method.

See section `Filtering collections` of chapter :doc:`Working with Associations <working-with-associations>`

By Eager Loading
~~~~~~~~~~~~~~~~

Whenever you query for an entity that has persistent associations
and these associations are mapped as EAGER, they will automatically
be loaded together with the entity being queried and is thus
immediately available to your application.

Eager Loading can also be configured at runtime through
``AbstractQuery::setFetchMode`` in DQL or Native Queries.

Eager loading for many-to-one and one-to-one associations is using either a
LEFT JOIN or a second query for fetching the related entity eagerly.

Eager loading for many-to-one associations uses a second query to load
the collections for several entities at the same time.

When many-to-many, one-to-one or one-to-many associations are eagerly loaded,
then the global batch size configuration is used to avoid IN(?) queries with
too many arguments. The default batch size is 100 and can be changed with
``Configuration::setEagerFetchBatchSize()``.

For eagerly loaded Many-To-Many associations one query has to be made for each
collection.

By Lazy Loading
~~~~~~~~~~~~~~~

Whenever you have a managed entity instance at hand, you can
traverse and use any associations of that entity that are
configured LAZY as if they were in-memory already. Doctrine will
automatically load the associated objects on demand through the
concept of lazy-loading.

By DQL
~~~~~~

The most powerful and flexible method to query for persistent
objects is the Doctrine Query Language, an object query language.
DQL enables you to query for persistent objects in the language of
objects. DQL understands classes, fields, inheritance and
associations. DQL is syntactically very similar to the familiar SQL
but *it is not SQL*.

A DQL query is represented by an instance of the
``Doctrine\ORM\Query`` class. You create a query using
``EntityManager#createQuery($dql)``. Here is a simple example:

.. code-block:: php

    <?php
    // $em instanceof EntityManager

    // All users with an age between 20 and 30 (inclusive).
    $q = $em->createQuery("select u from MyDomain\Model\User u where u.age >= 20 and u.age <= 30");
    $users = $q->getResult();

Note that this query contains no knowledge about the relational
schema, only about the object model. DQL supports positional as
well as named parameters, many functions, (fetch) joins,
aggregates, subqueries and much more. Detailed information about
DQL and its syntax as well as the Doctrine class can be found in
:doc:`the dedicated chapter <dql-doctrine-query-language>`.
For programmatically building up queries based on conditions that
are only known at runtime, Doctrine provides the special
``Doctrine\ORM\QueryBuilder`` class. While this a powerful tool,
it also brings more complexity to your code compared to plain DQL,
so you should only use it when you need it. More information on
constructing queries with a QueryBuilder can be found
:doc:`in Query Builder chapter <query-builder>`.

By Native Queries
~~~~~~~~~~~~~~~~~

As an alternative to DQL or as a fallback for special SQL
statements native queries can be used. Native queries are built by
using a hand-crafted SQL query and a ResultSetMapping that
describes how the SQL result set should be transformed by Doctrine.
More information about native queries can be found in
:doc:`the dedicated chapter <native-sql>`.

.. _working-with-objects-custom-repositories:

Custom Repositories
~~~~~~~~~~~~~~~~~~~

By default the EntityManager returns a default implementation of
``Doctrine\ORM\EntityRepository`` when you call
``EntityManager#getRepository($entityClass)``. You can overwrite
this behaviour by specifying the class name of your own Entity
Repository in the Attribute or XML metadata. In large
applications that require lots of specialized DQL queries using a
custom repository is one recommended way of grouping these queries
in a central location.

.. code-block:: php

    <?php
    namespace MyDomain\Model;

    use MyDomain\Model\UserRepository;
    use Doctrine\ORM\EntityRepository;
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity(repositoryClass: UserRepository::class)]
    class User
    {

    }

    class UserRepository extends EntityRepository
    {
        /** @return Collection<User> */
        public function getAllAdminUsers(): Collection
        {
            return $this->_em->createQuery('SELECT u FROM MyDomain\Model\User u WHERE u.status = "admin"')
                             ->getResult();
        }
    }

You can access your repository now by calling:

.. code-block:: php

    <?php
    // $em instanceof EntityManager

    $admins = $em->getRepository('MyDomain\Model\User')->getAllAdminUsers();
