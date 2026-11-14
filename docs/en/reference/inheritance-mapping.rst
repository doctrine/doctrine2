Inheritance Mapping
===================

This chapter explains the available options for mapping class
hierarchies.

Mapped Superclasses
-------------------

A mapped superclass is an abstract or concrete class that provides
persistent entity state and mapping information for its subclasses,
but which is not itself an entity. Typically, the purpose of such a
mapped superclass is to define state and mapping information that
is common to multiple entity classes.

Mapped superclasses, just as regular, non-mapped classes, can
appear in the middle of an otherwise mapped inheritance hierarchy
(through Single Table Inheritance or Class Table Inheritance). They
are not query-able, and do not require an ``#[Id]`` property.

No database table will be created for a mapped superclass itself,
only for entity classes inheriting from it. That  implies that a
mapped superclass cannot be the ``targetEntity`` in associations.

In other words, a mapped superclass can use unidirectional One-To-One
and Many-To-One associations where it is the owning side.
Many-To-Many associations are only possible if the mapped
superclass is only used in exactly one entity at the moment. For further
support of inheritance, the single or joined table inheritance features
have to be used.

.. note::

    One-To-Many associations are not generally possible on a mapped
    superclass, since they require the "many" side to hold the foreign
    key.

    It is, however, possible to use the :doc:`ResolveTargetEntityListener </cookbook/resolve-target-entity-listener>`
    to replace references to a mapped superclass with an entity class at runtime.
    As long as there is only one entity subclass inheriting from the mapped
    superclass and all references to the mapped superclass are resolved to that
    entity class at runtime, the mapped superclass *can* use One-To-Many associations
    and be named as the ``targetEntity`` on the owning sides.

.. warning::

    At least when using attributes or annotations to specify your mapping,
    it *seems* as if you could inherit from a base class that is neither
    an entity nor a mapped superclass, but has properties with mapping configuration
    on them that would also be used in the inheriting class.

    This, however, is due to how the corresponding mapping
    drivers work and what the PHP reflection API reports for inherited fields.

    Such a configuration is explicitly not supported. To give just one example,
    it will break for ``private`` properties.

.. note::

    You may be tempted to use traits to mix mapped fields or relationships
    into your entity classes to circumvent some of the limitations of
    mapped superclasses. Before doing that, please read the section on traits
    in the :doc:`Limitations and Known Issues </reference/limitations-and-known-issues>` chapter.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Column;
    use Doctrine\ORM\Mapping\JoinColumn;
    use Doctrine\ORM\Mapping\OneToOne;
    use Doctrine\ORM\Mapping\Id;
    use Doctrine\ORM\Mapping\MappedSuperclass;
    use Doctrine\ORM\Mapping\Entity;

    #[MappedSuperclass]
    class Person
    {
        #[Column(type: 'integer')]
        protected int $mapped1;
        #[Column(type: 'string')]
        protected string $mapped2;
        #[OneToOne(targetEntity: Toothbrush::class)]
        #[JoinColumn(name: 'toothbrush_id', referencedColumnName: 'id')]
        protected Toothbrush|null $toothbrush = null;

        // ... more fields and methods
    }

    #[Entity]
    class Employee extends Person
    {
        #[Id, Column(type: 'integer')]
        private int|null $id = null;
        #[Column(type: 'string')]
        private string $name;

        // ... more fields and methods
    }

    #[Entity]
    class Toothbrush
    {
        #[Id, Column(type: 'integer')]
        private int|null $id = null;

        // ... more fields and methods
    }

The DDL for the corresponding database schema would look something
like this (this is for SQLite):

.. code-block:: sql

    CREATE TABLE Employee (mapped1 INTEGER NOT NULL, mapped2 TEXT NOT NULL, id INTEGER NOT NULL, name TEXT NOT NULL, toothbrush_id INTEGER DEFAULT NULL, PRIMARY KEY(id))

As you can see from this DDL snippet, there is only a single table
for the entity subclass. All the mappings from the mapped
superclass were inherited to the subclass as if they had been
defined on that class directly.

Entity Inheritance
------------------

As soon as one entity class inherits from another entity class, either
directly, with a mapped superclass or other unmapped (also called
"transient") classes in between, these entities form an inheritance
hierarchy. The topmost entity class in this hierarchy is called the
root entity, and the hierarchy includes all entities that are
descendants of this root entity.

On the root entity class, ``#[InheritanceType]``,
``#[DiscriminatorColumn]`` and ``#[DiscriminatorMap]`` must be specified.

``#[InheritanceType]`` specifies one of the two available inheritance
mapping strategies that are explained in the following sections.

``#[DiscriminatorColumn]`` designates the so-called discriminator column.
This is an extra column in the table that keeps information about which
type from the hierarchy applies for a particular database row.

``#[DiscriminatorMap]`` declares the possible values for the discriminator
column and maps them to class names in the hierarchy. This discriminator map
has to declare all non-abstract entity classes that exist in that particular
inheritance hierarchy. That includes the root as well as any intermediate
entity classes, given they are not abstract.

The names of the classes in the discriminator map do not need to be fully
qualified if the classes are contained in the same namespace as the entity
class on which the discriminator map is applied.

If no discriminator map is provided, then the map is generated
automatically. The automatically generated discriminator map contains the
lowercase short name of each class as key.

.. note::

    Automatically generating the discriminator map is very expensive
    computation-wise. The mapping driver has to provide all classes
    for which mapping configuration exists, and those have to be
    loaded and checked against  the current inheritance hierarchy
    to see if they are part of it. The resulting map, however, can be kept
    in the metadata cache.

Performance impact on to-one associations
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

There is a general performance consideration when using entity inheritance:
If the target-entity of a many-to-one or one-to-one association is part of
an inheritance hierarchy, it is preferable for performance reasons that it
be a leaf entity (ie. have no subclasses).

Otherwise, the ORM is currently unable to tell beforehand which entity class
will have to be used, and so no appropriate proxy instance can be created.
That means the referred-to entities will *always* be loaded eagerly, which
might even propagate to relationships of these entities (in the case of
self-referencing associations).

Single Table Inheritance
------------------------

`Single Table Inheritance <https://martinfowler.com/eaaCatalog/singleTableInheritance.html>`_
is an inheritance mapping strategy where all classes of a hierarchy are
mapped to a single database table.

Example:

.. configuration-block::

    .. code-block:: attribute

        <?php
        namespace MyProject\Model;

        #[Entity]
        #[InheritanceType('SINGLE_TABLE')]
        #[DiscriminatorColumn(name: 'discr', type: 'string')]
        #[DiscriminatorMap(['person' => Person::class, 'employee' => Employee::class])]
        class Person
        {
            // ...
        }

        #[Entity]
        class Employee extends Person
        {
            // ...
        }

    .. code-block:: xml

        <doctrine-mapping>
          <entity name="MyProject\Model\Person" inheritance-type="SINGLE_TABLE">
            <discriminator-column name="discr" type="string" />
            <discriminator-map>
                <discriminator-mapping value="person" class="MyProject\Model\Person"/>
                <discriminator-mapping value="employee" class="MyProject\Model\Employee"/>
            </discriminator-map>
          </entity>
        </doctrine-mapping>

        <doctrine-mapping>
            <entity name="MyProject\Model\Employee">
            </entity>
        </doctrine-mapping>

In this example, the ``#[DiscriminatorMap]`` specifies that in the
discriminator column, a value of "person" identifies a row as being of type
``Person`` and employee" identifies a row as being of type ``Employee``.

Design-time considerations
~~~~~~~~~~~~~~~~~~~~~~~~~~

This mapping approach works well when the type hierarchy is fairly
simple and stable. Adding a new type to the hierarchy and adding
fields to existing supertypes simply involves adding new columns to
the table, though in large deployments this may have an adverse
impact on the index and column layout inside the database.

Performance impact
~~~~~~~~~~~~~~~~~~

This strategy is very efficient for querying across all types in
the hierarchy or for specific types. No table joins are required,
only a ``WHERE`` clause listing the type identifiers. In particular,
relationships involving types that employ this mapping strategy are
very performing.

SQL Schema considerations
~~~~~~~~~~~~~~~~~~~~~~~~~

For Single-Table-Inheritance to work in scenarios where you are
using either a legacy database schema or a self-written database
schema you have to make sure that all columns that are not in the
root entity but in any of the different sub-entities has to allow
null values. Columns that have ``NOT NULL`` constraints have to be on
the root entity of the single-table inheritance hierarchy.

Class Table Inheritance
-----------------------

`Class Table Inheritance <https://martinfowler.com/eaaCatalog/classTableInheritance.html>`_
is an inheritance mapping strategy where each class in a hierarchy
is mapped to several tables: its own table and the tables of all
parent classes. The table of a child class is linked to the table
of a parent class through a foreign key constraint.

The discriminator column is placed in the topmost table of the hierarchy,
because this is the easiest way to achieve polymorphic queries with Class
Table Inheritance.

Example:

.. code-block:: php

    <?php
    namespace MyProject\Model;

    #[Entity]
    #[InheritanceType('JOINED')]
    #[DiscriminatorColumn(name: 'discr', type: 'string')]
    #[DiscriminatorMap(['person' => Person::class, 'employee' => Employee::class])]
    class Person
    {
        // ...
    }

    #[Entity]
    class Employee extends Person
    {
        // ...
    }

As before, the ``#[DiscriminatorMap]`` specifies that in the
discriminator column, a value of "person" identifies a row as being of type
``Person`` and "employee" identifies a row as being of type ``Employee``.

.. note::

    When you do not use the SchemaTool to generate the
    required SQL you should know that deleting a class table
    inheritance makes use of the foreign key property
    ``ON DELETE CASCADE`` in all database implementations. A failure to
    implement this yourself will lead to dead rows in the database.


Design-time considerations
~~~~~~~~~~~~~~~~~~~~~~~~~~

Introducing a new type to the hierarchy, at any level, simply
involves interjecting a new table into the schema. Subtypes of that
type will automatically join with that new type at runtime.
Similarly, modifying any entity type in the hierarchy by adding,
modifying or removing fields affects only the immediate table
mapped to that type. This mapping strategy provides the greatest
flexibility at design time, since changes to any type are always
limited to that type's dedicated table.

Performance impact
~~~~~~~~~~~~~~~~~~

This strategy inherently requires multiple JOIN operations to
perform just about any query which can have a negative impact on
performance, especially with large tables and/or large hierarchies.
When partial objects are allowed, either globally or on the
specific query, then querying for any type will not cause the
tables of subtypes to be ``OUTER JOIN``ed which can increase
performance but the resulting partial objects will not fully load
themselves on access of any subtype fields, so accessing fields of
subtypes after such a query is not safe.

.. note::

    When native lazy objects are enabled (PHP 8.4+), accessing a
    subtype field on a partial object transparently triggers a
    ``SELECT`` that loads the full entity, making the access safe.
    See :doc:`/reference/partial-objects` for details.

There is also another important performance consideration that it is *not possible*
to query for the base entity without any ``LEFT JOIN``s to the sub-types.

SQL Schema considerations
~~~~~~~~~~~~~~~~~~~~~~~~~

For each entity in the Class-Table Inheritance hierarchy all the
mapped fields have to be columns on the table of this entity.
Additionally each child table has to have an id column that matches
the id column definition on the root table (except for any sequence
or auto-increment details). Furthermore each child table has to
have a foreign key pointing from the id column to the root table id
column and cascading on delete.

.. _inheritance_mapping_cti_bulk_statements:

Bulk UPDATE and DELETE statements
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The rows of an entity are spread over several tables, so a DQL ``UPDATE`` or
``DELETE`` statement on an entity of a Class Table Inheritance hierarchy cannot
be executed as a single SQL statement. Instead, the ORM

1. creates a temporary table for the identifiers of the affected rows,
2. copies the identifiers of the rows matching the ``WHERE`` clause into it,
3. updates or deletes the rows in each table of the hierarchy that is concerned,
   using these identifiers, and
4. drops the temporary table.

The same happens when the whole collection of a one-to-many association with
``orphanRemoval`` is replaced by a new one and the target entity is part of a
Class Table Inheritance hierarchy. The database user therefore needs the
privilege to create temporary tables.

The identifier columns of the temporary table are declared from the mapping of
the identifier of the root entity, so that their values can be compared with the
ones of the actual tables. The type, the ``length``, ``precision`` and ``scale``
and the ``fixed``, ``unsigned``, ``charset`` and ``collation`` options of the
column are taken over. A ``string`` identifier without ``length`` gets the same
default length as in the SchemaTool (see :doc:`/reference/advanced-configuration`).
A ``columnDefinition`` is not used.

.. note::

    MySQL and MariaDB usually refuse to compare string columns of different
    collations ("Illegal mix of collations"). Besides, the primary key of a
    temporary table with a case insensitive collation cannot hold identifiers
    that differ only in case, although your tables can when their collation is
    case sensitive. A temporary table would get the default character set and
    collation of the database, which are not necessarily the ones of your
    tables. For identifiers of a string type, the temporary table is therefore
    created with the character set and collation of the root table: the
    ``charset`` and ``collation`` entries of the ``options`` of its ``#[Table]``
    attribute take precedence over the ``defaultTableOptions`` of the
    connection, and the ``charset`` parameter of the connection is used as a
    last resort. If your tables were created with other options than the ones
    configured for the ORM, the statements can fail with the errors mentioned
    above.

.. _inheritence_mapping_overrides:

Overrides
---------

Overrides can only be applied to entities that extend a mapped superclass or
use traits. They are used to override a mapping for an entity field or
relationship defined in that mapped superclass or trait.

It is not supported to use overrides in entity inheritance scenarios.

.. note::

    When using traits, make sure not to miss the warnings given in the
    :doc:`Limitations and Known Issues </reference/limitations-and-known-issues>` chapter.


Association Override
~~~~~~~~~~~~~~~~~~~~
Override a mapping for an entity relationship.

Could be used by an entity that extends a mapped superclass
to override a relationship mapping defined by the mapped superclass.

Example:

.. configuration-block::

    .. code-block:: attribute

        <?php
        // user mapping
        namespace MyProject\Model;

        #[MappedSuperclass]
        class User
        {
            // other fields mapping

            /** @var Collection<int, Group> */
            #[JoinTable(name: 'users_groups')]
            #[JoinColumn(name: 'user_id', referencedColumnName: 'id')]
            #[InverseJoinColumn(name: 'group_id', referencedColumnName: 'id')]
            #[ManyToMany(targetEntity: 'Group', inversedBy: 'users')]
            protected Collection $groups;

            #[ManyToOne(targetEntity: 'Address')]
            #[JoinColumn(name: 'address_id', referencedColumnName: 'id')]
            protected Address|null $address = null;
        }

        // admin mapping
        namespace MyProject\Model;

        #[Entity]
        #[AssociationOverrides([
            new AssociationOverride(
                name: 'groups',
                joinTable: new JoinTable(
                    name: 'users_admingroups',
                ),
                joinColumns: [new JoinColumn(name: 'adminuser_id')],
                inverseJoinColumns: [new JoinColumn(name: 'admingroup_id')]
            ),
            new AssociationOverride(
                name: 'address',
                joinColumns: [new JoinColumn(name: 'adminaddress_id', referencedColumnName: 'id')]
            )
        ])]
        class Admin extends User
        {
        }

    .. code-block:: xml

        <!-- user mapping -->
        <doctrine-mapping>
          <mapped-superclass name="MyProject\Model\User">
                <!-- other fields mapping -->
                <many-to-many field="groups" target-entity="Group" inversed-by="users">
                    <cascade>
                        <cascade-persist/>
                        <cascade-detach/>
                    </cascade>
                    <join-table name="users_groups">
                        <join-columns>
                            <join-column name="user_id" referenced-column-name="id" />
                        </join-columns>
                        <inverse-join-columns>
                            <join-column name="group_id" referenced-column-name="id" />
                        </inverse-join-columns>
                    </join-table>
                </many-to-many>
            </mapped-superclass>
        </doctrine-mapping>

        <!-- admin mapping -->
        <doctrine-mapping>
            <entity name="MyProject\Model\Admin">
                <association-overrides>
                    <association-override name="groups">
                        <join-table name="users_admingroups">
                            <join-columns>
                                <join-column name="adminuser_id"/>
                            </join-columns>
                            <inverse-join-columns>
                                <join-column name="admingroup_id"/>
                            </inverse-join-columns>
                        </join-table>
                    </association-override>
                    <association-override name="address">
                        <join-columns>
                            <join-column name="adminaddress_id" referenced-column-name="id"/>
                        </join-columns>
                    </association-override>
                </association-overrides>
            </entity>
        </doctrine-mapping>

Things to note:

-  The "association override" specifies the overrides based on the property
 name.
-  This feature is available for all kind of associations (OneToOne, OneToMany, ManyToOne, ManyToMany).
-  The association type *cannot* be changed.
-  The override could redefine the ``joinTables`` or ``joinColumns`` depending on the association type.
-  The override could redefine ``inversedBy`` to reference more than one extended entity.
-  The override could redefine fetch to modify the fetch strategy of the extended entity.

Attribute Override
~~~~~~~~~~~~~~~~~~~~
Override the mapping of a field.

Could be used by an entity that extends a mapped superclass to override a field mapping defined by the mapped superclass.

.. configuration-block::

    .. code-block:: attribute

        <?php
        // user mapping
        namespace MyProject\Model;

        #[MappedSuperclass]
        class User
        {
            #[Id, GeneratedValue, Column(type: 'integer', name: 'user_id', length: 150)]
            protected int|null $id = null;

            #[Column(name: 'user_name', nullable: true, unique: false, length: 250)]
            protected string $name;

            // other fields mapping
        }

        // guest mapping
        namespace MyProject\Model;
        #[Entity]
        #[AttributeOverrides([
            new AttributeOverride(
                name: 'id',
                column: new Column(
                    name: 'guest_id',
                    type: 'integer',
                    length: 140
                )
            ),
            new AttributeOverride(
                name: 'name',
                column: new Column(
                    name: 'guest_name',
                    nullable: false,
                    unique: true,
                    length: 240
                )
            )
        ])]
        class Guest extends User
        {
        }

    .. code-block:: xml

        <!-- user mapping -->
        <doctrine-mapping>
          <mapped-superclass name="MyProject\Model\User">
                <id name="id" type="integer" column="user_id" length="150">
                    <generator strategy="AUTO"/>
                </id>
                <field name="name" column="user_name" type="string" length="250" nullable="true" unique="false" />
                <many-to-one field="address" target-entity="Address">
                    <cascade>
                        <cascade-persist/>
                    </cascade>
                    <join-column name="address_id" referenced-column-name="id"/>
                </many-to-one>
                <!-- other fields mapping -->
            </mapped-superclass>
        </doctrine-mapping>

        <!-- admin mapping -->
        <doctrine-mapping>
            <entity name="MyProject\Model\Guest">
                <attribute-overrides>
                    <attribute-override name="id">
                        <field column="guest_id" length="140"/>
                    </attribute-override>
                    <attribute-override name="name">
                        <field column="guest_name" type="string" length="240" nullable="false" unique="true" />
                    </attribute-override>
                </attribute-overrides>
            </entity>
        </doctrine-mapping>

Things to note:

-  The "attribute override" specifies the overrides based on the property name.
-  The column type *cannot* be changed. If the column type is not equal, you get a ``MappingException``.
-  The override can redefine all the attributes except the type.

Query the Type
--------------

It may happen that the entities of a special type should be queried. Because there
is no direct access to the discriminator column, Doctrine provides the
``INSTANCE OF`` construct.

The following example shows how to use ``INSTANCE OF``. There is a three level hierarchy
with a base entity ``NaturalPerson`` which is extended by ``Staff`` which in turn
is extended by ``Technician``.

Querying for the staffs without getting any technicians can be achieved by this DQL:

.. code-block:: php

    <?php
    $query = $em->createQuery("SELECT staff FROM MyProject\Model\Staff staff WHERE staff NOT INSTANCE OF MyProject\Model\Technician");
    $staffs = $query->getResult();
