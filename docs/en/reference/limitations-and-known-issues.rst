Limitations and Known Issues
============================

We try to make using Doctrine ORM a very pleasant experience.
Therefore we think it is very important to be honest about the
current limitations to our users. Much like every other piece of
software the ORM is not perfect and far from feature complete.
This section should give you an overview of current limitations of
Doctrine ORM as well as critical known issues that you should know
about.

Current Limitations
-------------------

There is a set of limitations that exist currently which might be
solved in the future. Any of this limitations now stated has at
least one ticket in the Tracker and is discussed for future
releases.

Join-Columns with non-primary keys
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

It is not possible to use join columns pointing to non-primary keys. Doctrine will think these are the primary
keys and create lazy-loading proxies with the data, which can lead to unexpected results. Doctrine can for performance
reasons not validate the correctness of this settings at runtime but only through the Validate Schema command.

Mapping Arrays to a Join Table
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Related to the previous limitation with "Foreign Keys as
Identifier" you might be interested in mapping the same table
structure as given above to an array. However this is not yet
possible either. See the following example:

.. code-block:: sql

    CREATE TABLE product (
        id INTEGER,
        name VARCHAR,
        PRIMARY KEY(id)
    );
    
    CREATE TABLE product_attributes (
        product_id INTEGER,
        attribute_name VARCHAR,
        attribute_value VARCHAR,
        PRIMARY KEY (product_id, attribute_name)
    );

This schema should be mapped to a Product Entity as follows:

.. code-block:: php

    class Product
    {
        private $id;
        private $name;
        private $attributes = array();
    }

Where the ``attribute_name`` column contains the key and
``attribute_value`` contains the value of each array element in
``$attributes``.

The feature request for persistence of primitive value arrays
`is described in the DDC-298 ticket <https://github.com/doctrine/orm/issues/3743>`_.

Custom Persisters
~~~~~~~~~~~~~~~~~

A Persister in Doctrine is an object that is responsible for the
hydration and write operations of an entity against the database.
Currently there is no way to overwrite the persister implementation
for a given entity, however there are several use-cases that can
benefit from custom persister implementations:

-  `Add Upsert Support <https://github.com/doctrine/orm/issues/5178>`_
-  `Evaluate possible ways in which stored-procedures can be used <https://github.com/doctrine/orm/issues/4946>`_

Persist Keys of Collections
~~~~~~~~~~~~~~~~~~~~~~~~~~~

PHP Arrays are ordered hash-maps and so should be the
``Doctrine\Common\Collections\Collection`` interface. We plan to
evaluate a feature that optionally persists and hydrates the keys
of a Collection instance.

`Ticket DDC-213 <https://github.com/doctrine/orm/issues/2817>`_

Mapping many tables to one entity
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

It is not possible to map several equally looking tables onto one
entity. For example if you have a production and an archive table
of a certain business concept then you cannot have both tables map
to the same entity.

Behaviors
~~~~~~~~~

Doctrine ORM will **never** include a behavior system like Doctrine 1
in the core library. We don't think behaviors add more value than
they cost pain and debugging hell. Please see the many different
blog posts we have written on this topics:

-  `Doctrine2 "Behaviors" in a Nutshell <https://www.doctrine-project.org/2010/02/17/doctrine2-behaviours-nutshell.html>`_
-  `A re-usable Versionable behavior for Doctrine2 <https://www.doctrine-project.org/2010/02/24/doctrine2-versionable.html>`_
-  `Write your own ORM on top of Doctrine2 <https://www.doctrine-project.org/2010/07/19/your-own-orm-doctrine2.html>`_
-  `Doctrine ORM Behavioral Extensions <https://www.doctrine-project.org/2010/11/18/doctrine2-behavioral-extensions.html>`_

Doctrine ORM has enough hooks and extension points so that **you** can
add whatever you want on top of it. None of this will ever become
core functionality of Doctrine2 however, you will have to rely on
third party extensions for magical behaviors.

Nested Set
~~~~~~~~~~

NestedSet was offered as a behavior in Doctrine 1 and will not be
included in the core of Doctrine ORM. However there are already two
extensions out there that offer support for Nested Set with
ORM:

-  `Doctrine2 Hierarchical-Structural Behavior <https://github.com/guilhermeblanco/Doctrine2-Hierarchical-Structural-Behavior>`_
-  `Doctrine2 NestedSet <https://github.com/blt04/doctrine2-nestedset>`_

Using Traits in Entity Classes
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The use of traits in entity or mapped superclasses, at least when they
include mapping configuration or mapped fields, is currently not
endorsed by the Doctrine project. The reasons for this are as follows.

Traits were added in PHP 5.4 more than 10 years ago, but at the same time
more than two years after the initial Doctrine 2 release and the time where
core components were designed.

In fact, this documentation mentions traits only in the context of
:doc:`overriding field association mappings in subclasses </tutorials/override-field-association-mappings-in-subclasses>`.
Coverage of traits in test cases is practically nonexistent.

Thus, you should at least be aware that when using traits in your entity and
mapped superclasses, you will be in uncharted terrain.

.. warning::

    There be dragons.

From a more technical point of view, traits basically work at the language level
as if the code contained in them had been copied into the class where the trait
is used, and even private fields are accessible by the using class. In addition to
that, some precedence and conflict resolution rules apply.

When it comes to loading mapping configuration, the annotation and attribute drivers
rely on PHP reflection to inspect class properties including their docblocks.
As long as the results are consistent with what a solution *without* traits would
have produced, this is probably fine.

However, to mention known limitations, it is currently not possible to use "class"
level `annotations <https://github.com/doctrine/orm/pull/1517>`_ or
`attributes <https://github.com/doctrine/orm/issues/8868>`_ on traits, and attempts to
improve parser support for traits as `here <https://github.com/doctrine/annotations/pull/102>`_
or `there <https://github.com/doctrine/annotations/pull/63>`_ have been abandoned
due to complexity.

XML mapping configuration probably needs to completely re-configure or otherwise
copy-and-paste configuration for fields used from traits.

Mapping multiple private fields of the same name
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

When two classes, say a mapped superclass and an entity inheriting from it,
both contain a ``private`` field of the same name, this will lead to a ``MappingException``.

Since the fields are ``private``, both are technically separate and can contain
different values at the same time. However, the ``ClassMetadata`` configuration used
internally by the ORM currently refers to fields by their name only, without taking the
class containing the field into consideration. This makes it impossible to keep separate
mapping configuration for both fields.

Apart from that, in the case of having multiple ``private`` fields of the same name within
the class hierarchy an entity or mapped superclass, the Collection filtering API cannot determine
the right field to look at. Even if only one of these fields is actually mapped, the ``ArrayCollection``
will not be able to tell, since it does not have access to any metadata.

Thus, to avoid problems in this regard, it is best to avoid having multiple ``private`` fields of the
same name in class hierarchies containing entity and mapped superclasses.

Objects as identifier values
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The ``UnitOfWork`` builds the key of its identity map by casting the identifier
values to strings. Identifier values that are objects therefore only work when they
can be converted to a string, either because they implement ``Stringable`` or because
they are backed enums. Using a ``DateTime`` or ``DateTimeImmutable`` value as the
identifier, or as part of a composite identifier, fails with an
``Object of class DateTime could not be converted to string`` error.

Use a scalar column (for example the timestamp or a formatted date string) as the
identifier instead, or wrap the date in a custom value object that implements
``Stringable`` together with a custom DBAL type.

Known Issues
------------

The Known Issues section describes critical/blocker bugs and other
issues that are either complicated to fix, not fixable due to
backwards compatibility issues or where no simple fix exists (yet).
We don't plan to add every bug in the tracker there, just those
issues that can potentially cause nightmares or pain of any sort.

See bugs, improvement and feature requests on `Github issues <https://github.com/doctrine/orm/issues>`_.

Identifier Quoting and Legacy Databases
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

For compatibility reasons between all the supported vendors and
edge case problems Doctrine ORM does **NOT** do automatic identifier
quoting. This can lead to problems when trying to get
legacy-databases to work with Doctrine ORM.


-  You can quote column-names as described in the
   :doc:`Basic-Mapping <basic-mapping>` section.
-  You cannot quote join column names.
-  You cannot use non [a-zA-Z0-9\_]+ characters, they will break
   several SQL statements.

Having problems with these kind of column names? Many databases
support all CRUD operations on views that semantically map to
certain tables. You can create views for all your problematic
tables and column names to avoid the legacy quoting nightmare.

Microsoft SQL Server and Doctrine "datetime"
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Doctrine assumes that you use ``DateTime2`` data-types. If your legacy database contains DateTime
datatypes then you have to add your own data-type (see Basic Mapping for an example).

MySQL with MyISAM tables
~~~~~~~~~~~~~~~~~~~~~~~~

Doctrine cannot provide atomic operations when calling ``EntityManager#flush()`` if one
of the tables involved uses the storage engine MyISAM. You must use InnoDB or
other storage engines that support transactions if you need integrity.
