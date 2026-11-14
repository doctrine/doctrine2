Architecture
============

This chapter gives an overview of the overall architecture,
terminology and constraints of Doctrine ORM. It is recommended to
read this chapter carefully.

Using an Object-Relational Mapper
---------------------------------

As the term ORM already hints at, Doctrine ORM aims to simplify the
translation between database rows and the PHP object model. The
primary use case for Doctrine are therefore applications that
utilize the Object-Oriented Programming Paradigm. For applications
that do not primarily work with objects Doctrine ORM is not suited very
well.

Requirements
------------

Doctrine ORM requires a minimum of PHP 8.4. For greatly improved
performance it is also recommended that you use APC with PHP.

Doctrine ORM Packages
-------------------

Doctrine ORM is divided into four main packages.

-  `Collections <https://www.doctrine-project.org/projects/doctrine-collections/en/stable/index.html>`_
-  `Event Manager <https://www.doctrine-project.org/projects/doctrine-event-manager/en/stable/index.html>`_
-  `Persistence <https://www.doctrine-project.org/projects/doctrine-persistence/en/stable/index.html>`_
-  `DBAL <https://www.doctrine-project.org/projects/doctrine-dbal/en/stable/index.html>`_
-  ORM (depends on DBAL+Persistence+Collections)

This manual mainly covers the ORM package, sometimes touching parts
of the underlying DBAL and Persistence packages. The Doctrine codebase
is split into these packages for a few reasons:


-  to make things more maintainable and decoupled
-  to allow you to use the code in Doctrine Persistence and Collections without the ORM or DBAL
-  to allow you to use the DBAL without the ORM

Collection, Event Manager and Persistence
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The Collection, Event Manager and Persistence packages contain highly
reusable components that have no dependencies beyond the packages
themselves (and PHP, of course). The root namespace of the Persistence
package is ``Doctrine\Persistence``. The root namespace of the
Collection package is ``Doctrine\Common\Collections``, for historical
reasons. The root namespace of the Event Manager package is just
``Doctrine\Common``, also for historical reasons.

The DBAL Package
~~~~~~~~~~~~~~~~

The DBAL package contains an enhanced database abstraction layer on
top of PDO but is not strongly bound to PDO. The purpose of this
layer is to provide a single API that bridges most of the
differences between the different RDBMS vendors. The root namespace
of the DBAL package is ``Doctrine\DBAL``.

The ORM Package
~~~~~~~~~~~~~~~

The ORM package contains the object-relational mapping toolkit that
provides transparent relational persistence for plain PHP objects.
The root namespace of the ORM package is ``Doctrine\ORM``.

Terminology
-----------

.. _terminology_entities:

Entities
~~~~~~~~

An entity is a lightweight, persistent domain object. An entity can
be any regular PHP class observing the following restrictions:

-  An entity class can be final or read-only. It may contain final
   methods or read-only properties too.
-  Any two entity classes in a class hierarchy that inherit
   directly or indirectly from one another must not have a mapped
   property with the same name. That is, if B inherits from A then B
   must not have a mapped field with the same name as an already
   mapped field that is inherited from A.

Entities support inheritance, polymorphic associations, and
polymorphic queries. Both abstract and concrete classes can be
entities. Entities may extend non-entity classes as well as entity
classes, and non-entity classes may extend entity classes.

.. note::

    The constructor of an entity is only ever invoked when
    *you* construct a new instance with the *new* keyword. Doctrine
    never calls entity constructors, thus you are free to use them as
    you wish and even have it require arguments of any type.

Mapped Superclasses
~~~~~~~~~~~~~~~~~~~

A mapped superclass is an abstract or concrete class that provides
persistent entity state and mapping information for its subclasses,
but which is not itself an entity.

Mapped superclasses are explained in greater detail in the chapter
on :doc:`inheritance mapping </reference/inheritance-mapping>`.

Transient Classes
~~~~~~~~~~~~~~~~~

The term "transient class" appears in some places in the mapping
drivers as well as the code dealing with metadata handling.

A transient class is a class that is neither an entity nor a mapped
superclass. From the ORM's point of view, these classes can be
completely ignored, and no class metadata is loaded for them at all.

Entity states
~~~~~~~~~~~~~

An entity instance can be characterized as being NEW, MANAGED,
DETACHED or REMOVED.


-  A NEW entity instance has no persistent identity, and is not yet
   associated with an EntityManager and a UnitOfWork (i.e. those just
   created with the "new" operator).
-  A MANAGED entity instance is an instance with a persistent
   identity that is associated with an EntityManager and whose
   persistence is thus managed.
-  A DETACHED entity instance is an instance with a persistent
   identity that is not (or no longer) associated with an
   EntityManager and a UnitOfWork.
-  A REMOVED entity instance is an instance with a persistent
   identity, associated with an EntityManager, that will be removed
   from the database upon transaction commit.

.. _architecture_persistent_fields:

Persistent fields
~~~~~~~~~~~~~~~~~

The persistent state of an entity is represented by instance
variables. An instance variable must be directly accessed only from
within the methods of the entity by the entity instance itself.
Instance variables must not be accessed by clients of the entity.
The state of the entity is available to clients only through the
entity’s methods, i.e. accessor methods (getter/setter methods) or
other business methods.

Collection-valued persistent fields and properties must be defined
in terms of the ``Doctrine\Common\Collections\Collection``
interface. The collection implementation type may be used by the
application to initialize fields or properties before the entity is
made persistent. Once the entity becomes managed (or detached),
subsequent access must be through the interface type.

Serializing entities
~~~~~~~~~~~~~~~~~~~~

Serializing entities can be problematic and is not really
recommended, at least not as long as an entity instance still holds
references to proxy objects or is still managed by an EntityManager.
By default, serializing proxy objects does not initialize them. On
unserialization, resulting objects are detached from the entity
manager and cannot be initialized anymore. You can implement the
``__serialize()`` method if you want to change that behavior, but
then you need to ensure that you won't generate large serialized
object graphs and take care of circular associations.

The EntityManager
~~~~~~~~~~~~~~~~~

The ``EntityManager`` class is a central access point to the
functionality provided by Doctrine ORM. The ``EntityManager`` API is
used to manage the persistence of your objects and to query for
persistent objects.

Transactional write-behind
~~~~~~~~~~~~~~~~~~~~~~~~~~

An ``EntityManager`` and the underlying ``UnitOfWork`` employ a
strategy called "transactional write-behind" that delays the
execution of SQL statements in order to execute them in the most
efficient way and to execute them at the end of a transaction so
that all write locks are quickly released. You should see Doctrine
as a tool to synchronize your in-memory objects with the database
in well defined units of work. Work with your objects and modify
them as usual and when you're done call ``EntityManager#flush()``
to make your changes persistent.

.. _unit-of-work:

The Unit of Work
~~~~~~~~~~~~~~~~

Internally an ``EntityManager`` uses a ``UnitOfWork``, which is a
typical implementation of the
`Unit of Work pattern <https://martinfowler.com/eaaCatalog/unitOfWork.html>`_,
to keep track of all the things that need to be done the next time
``flush`` is invoked. You usually do not directly interact with a
``UnitOfWork`` but with the ``EntityManager`` instead.
