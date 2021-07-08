Attributes Reference
====================

PHP 8 adds native support for metadata with its "Attributes" feature.
Doctrine ORM provides support for mapping metadata using PHP attributes as of version 2.9.

The attributes metadata support is closely modelled after the already existing
annotation metadata supported since the first version 2.0.

Index
-----

-  :ref:`#[Column] <attrref_column>`
-  :ref:`#[Cache] <attrref_cache>`
-  :ref:`#[ChangeTrackingPolicy <attrref_changetrackingpolicy>`
-  :ref:`#[CustomIdGenerator] <attrref_customidgenerator>`
-  :ref:`#[DiscriminatorColumn] <attrref_discriminatorcolumn>`
-  :ref:`#[DiscriminatorMap] <attrref_discriminatormap>`
-  :ref:`#[Embeddable] <attrref_embeddable>`
-  :ref:`#[Embedded] <attrref_embedded>`
-  :ref:`#[Entity] <attrref_entity>`
-  :ref:`#[GeneratedValue] <attrref_generatedvalue>`
-  :ref:`#[HasLifecycleCallbacks] <attrref_haslifecyclecallbacks>`
-  :ref:`#[Index] <attrref_index>`
-  :ref:`#[Id] <attrref_id>`
-  :ref:`#[InheritanceType] <attrref_inheritancetype>`
-  :ref:`#[JoinColumn] <attrref_joincolumn>`
-  :ref:`#[JoinColumns] <attrref_joincolumns>`
-  :ref:`#[JoinTable] <attrref_jointable>`
-  :ref:`#[ManyToOne] <attrref_manytoone>`
-  :ref:`#[ManyToMany] <attrref_manytomany>`
-  :ref:`#[MappedSuperclass] <attrref_mappedsuperclass>`
-  :ref:`#[OneToOne] <attrref_onetoone>`
-  :ref:`#[OneToMany] <attrref_onetomany>`
-  :ref:`#[OrderBy] <attrref_orderby>`
-  :ref:`#[PostLoad] <attrref_postload>`
-  :ref:`#[PostPersist] <attrref_postpersist>`
-  :ref:`#[PostRemove] <attrref_postremove>`
-  :ref:`#[PostUpdate] <attrref_postupdate>`
-  :ref:`#[PrePersist] <attrref_prepersist>`
-  :ref:`#[PreRemove] <attrref_preremove>`
-  :ref:`#[PreUpdate] <attrref_preupdate>`
-  :ref:`#[SequenceGenerator] <attrref_sequencegenerator>`
-  :ref:`#[Table] <attrref_table>`
-  :ref:`#[UniqueConstraint] <attrref_uniqueconstraint>`
-  :ref:`#[Version] <attrref_version>`


Reference
---------

.. _attrref_column:

#[Column]
~~~~~~~~~

Marks an annotated instance variable as "persistent". It has to be
inside the instance variables PHP DocBlock comment. Any value hold
inside this variable will be saved to and loaded from the database
as part of the lifecycle of the instance variables entity-class.

Required attributes:

-  **type**: Name of the DBAL Type which does the conversion between PHP
   and Database representation. Defaults to ``string`` or 
   :ref:`type from PHP property type <reference-php-mapping-types>`.

Optional attributes:

-  **name**: By default the property name is used for the database
   column name also, however the ``name`` attribute allows you to
   determine the column name.

-  **length**: Used by the ``string`` type to determine its maximum
   length in the database. Doctrine does not validate the length of a
   string value for you.

-  **precision**: The precision for a decimal (exact numeric) column
   (applies only for decimal column), which is the maximum number of
   digits that are stored for the values.

-  **scale**: The scale for a decimal (exact numeric) column (applies
   only for decimal column), which represents the number of digits
   to the right of the decimal point and must not be greater than
   *precision*.

-  **unique**: Boolean value to determine if the value of the column
   should be unique across all rows of the underlying entities table.

-  **nullable**: Determines if NULL values are allowed for this column. 
   If not specified and a type hint is given (e.g. ?int or int), the nullability
   of the type hint is used. Defaults to false.

-  **options**: Array of additional options:

   -  ``default``: The default value to set for the column if no value
      is supplied.

   -  ``unsigned``: Boolean value to determine if the column should
      be capable of representing only non-negative integers
      (applies only for integer column and might not be supported by
      all vendors).

   -  ``fixed``: Boolean value to determine if the specified length of
      a string column should be fixed or varying (applies only for
      string/binary column and might not be supported by all vendors).

   -  ``comment``: The comment of the column in the schema (might not
      be supported by all vendors).

   -  ``collation``: The collation of the column (only supported by Mysql, PostgreSQL, Sqlite and SQLServer).

   -  ``check``: Adds a check constraint type to the column (might not
      be supported by all vendors).

-  **columnDefinition**: DDL SQL snippet that starts after the column
   name and specifies the complete (non-portable!) column definition.
   This attribute allows to make use of advanced RMDBS features.
   However you should make careful use of this feature and the
   consequences. ``SchemaTool`` will not detect changes on the column correctly
   anymore if you use ``columnDefinition``.

   Additionally you should remember that the ``type``
   attribute still handles the conversion between PHP and Database
   values. If you use this attribute on a column that is used for
   joins between tables you should also take a look at
   :ref:`#[JoinColumn] <attrref_joincolumn>`.

.. note::

    For more detailed information on each attribute, please refer to
    the DBAL ``Schema-Representation`` documentation.

Examples:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Column;

    #[Column(type: "string", length: 32, unique: true, nullable: false)]
    protected $username;

    #[Column(type: "string", columnDefinition: "CHAR(2) NOT NULL")]
    protected $country;

    #[Column(type: "decimal", precision: 2, scale: 1)]
    protected $height;

    #[Column(type: "string", length: 2, options: [
        "fixed" => true,
        "comment" => "Initial letters of first and last name"
    ])]
    protected $initials;

    #[Column(
        type: "integer",
        name: "login_count",
        nullable: false,
        options: ["unsigned" => true, "default" => 0]
    )]
    protected $loginCount;

.. _attrref_cache:

#[Cache]
~~~~~~~~
Add caching strategy to a root entity or a collection.

Optional attributes:

-  **usage**: One of ``READ_ONLY``, ``READ_WRITE`` or ``NONSTRICT_READ_WRITE``, By default this is ``READ_ONLY``.
-  **region**: An specific region name

.. _attrref_changetrackingpolicy:

#[ChangeTrackingPolicy]
~~~~~~~~~~~~~~~~~~~~~~~

The Change Tracking Policy attribute allows to specify how the
Doctrine ORM ``UnitOfWork`` should detect changes in properties of
entities during flush. By default each entity is checked according
to a deferred implicit strategy, which means upon flush ``UnitOfWork``
compares all the properties of an entity to a previously stored
snapshot. This works out of the box, however you might want to
tweak the flush performance where using another change tracking
policy is an interesting option.

The :doc:`details on all the available change tracking policies <change-tracking-policies>`
can be found in the configuration section.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\ChangeTrackingPolicy;

    #[
        Entity,
        ChangeTrackingPolicy("DEFERRED_IMPLICIT"),
        ChangeTrackingPolicy("DEFERRED_EXPLICIT"),
        ChangeTrackingPolicy("NOTIFY")
    ]
    class User {}

.. _attrref_customidgenerator:

#[CustomIdGenerator]
~~~~~~~~~~~~~~~~~~~~

This attribute allows you to specify a user-provided class to generate identifiers. This attribute only works when both :ref:`#[Id] <attrref_id>` and :ref:`#[GeneratedValue(strategy: "CUSTOM")] <attrref_generatedvalue>` are specified.

Required attributes:

-  **class**: name of the class which should extend Doctrine\ORM\Id\AbstractIdGenerator

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Id;
    use Doctrine\ORM\Mapping\Column;
    use Doctrine\ORM\Mapping\GeneratedValue;
    use Doctrine\ORM\Mapping\CustomIdGenerator;
    use App\Doctrine\MyIdGenerator;

    #[Id]
    #[Column(type: "integer")]
    #[GeneratedValue(strategy: "CUSTOM")]
    #[CustomIdGenerator(class: MyIdGenerator::class)]
    public $id;

.. _attrref_discriminatorcolumn:

#[DiscriminatorColumn]
~~~~~~~~~~~~~~~~~~~~~~

This attribute is optional and set on the root entity
class of an inheritance hierarchy. It specifies the details of the
column which saves the name of the class, which the entity is
actually instantiated as.

If this attribute is not specified, the discriminator column defaults
to a string column of length 255 called ``dtype``.

Required attributes:


-  **name**: The column name of the discriminator. This name is also
   used during Array hydration as key to specify the class-name.

Optional attributes:


-  **type**: By default this is string.
-  **length**: By default this is 255.

.. _attrref_discriminatormap:

#[DiscriminatorMap]
~~~~~~~~~~~~~~~~~~~

The discriminator map is a required attribute on the
root entity class in an inheritance hierarchy. Its only argument is an
array which defines which class should be saved under
which name in the database. Keys are the database value and values
are the classes, either as fully- or as unqualified class names
depending on whether the classes are in the namespace or not.

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\InheritanceType;
    use Doctrine\ORM\Mapping\DiscriminatorColumn;
    use Doctrine\ORM\Mapping\DiscriminatorMap;

    #[Entity]
    #[InheritanceType("JOINED")]
    #[DiscriminatorColumn(name: "discr", type: "string")]
    #[DiscriminatorMap(["person" => Person::class, "employee" => Employee::class])]
    class Person
    {
        // ...
    }


.. _attrref_embeddable:

#[Embeddable]
~~~~~~~~~~~~~

The embeddable attribute is required on a class, in order to make it
embeddable inside an entity. It works together with the :ref:`#[Embedded] <attrref_embedded>`
attribute to establish the relationship between the two classes.

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Embeddable;
    use Doctrine\ORM\Mapping\Embedded;

    #[Embeddable]
    class Address
    { /* .. */ }

    class User
    {
        #[Embedded(class: Address::class)]
        private $address;


.. _attrref_embedded:

#[Embedded]
~~~~~~~~~~~

The embedded attribute is required on an entity's member variable,
in order to specify that it is an embedded class.

Required attributes:

-  **class**: The embeddable class

.. _attrref_entity:

#[Entity]
~~~~~~~~~

Required attribute to mark a PHP class as an entity. Doctrine manages
the persistence of all classes marked as entities.

Optional attributes:

-  **repositoryClass**: Specifies the FQCN of a subclass of the
   ``EntityRepository``. Use of repositories for entities is encouraged to keep
   specialized DQL and SQL operations separated from the Model/Domain
   Layer.
-  **readOnly**: Specifies that this entity is marked as read only and not
   considered for change-tracking. Entities of this type can be persisted
   and removed though.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use MyProject\Repository\UserRepository;

    #[Entity(repositoryClass: UserRepository::class, readOnly: false)]
    class User
    {
        // ...
    }

.. _attrref_generatedvalue:

#[GeneratedValue]
~~~~~~~~~~~~~~~~~

Specifies which strategy is used for identifier generation for an
instance variable which is annotated by :ref:`#[Id] <attrref_id>`. This
attribute is optional and only has meaning when used in
conjunction with #[Id].

If this attribute is not specified with ``#[Id]`` the ``NONE`` strategy is
used as default.

Optional attributes:

-  **strategy**: Set the name of the identifier generation strategy.
   Valid values are ``AUTO``, ``SEQUENCE``, ``TABLE``, ``IDENTITY``,
   ``UUID``, ``CUSTOM`` and ``NONE``.
   If not specified, the default value is ``AUTO``.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Column;
    use Doctrine\ORM\Mapping\GeneratedValue;
    use Doctrine\ORM\Mapping\Id;

    #[Id, Column(type: "integer"), GeneratedValue(strategy: "IDENTITY")]
    protected $id = null;

.. _attrref_haslifecyclecallbacks:

#[HasLifecycleCallbacks]
~~~~~~~~~~~~~~~~~~~~~~~~

This attribute has to be set on the entity-class to
notify Doctrine that this entity has entity lifecycle callback
attributes set on at least one of its methods. Using #[PostLoad],
``#[PrePersist]``, ``#[PostPersist]``, ``#[PreRemove]``, ``#[PostRemove]``,
``#[PreUpdate]`` or ``#[PostUpdate]`` without this marker attribute will
make Doctrine ignore the callbacks.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
    use Doctrine\ORM\Mapping\PostPersist;

    #[Entity, HasLifecycleCallbacks]
    class User
    {
        #[PostPersist]
        public function sendOptinMail() {}
    }

.. _attrref_index:

#[Index]
~~~~~~~~

Attribute is used on the entity-class level. It provides a hint to the SchemaTool to
generate a database index on the specified table columns. It only
has meaning in the ``SchemaTool`` schema generation context.

Required attributes:

-  **name**: Name of the Index
-  **fields**: Array of fields. Exactly one of **fields, columns** is required.
-  **columns**: Array of columns. Exactly one of **fields, columns** is required.


Optional attributes:

-  **options**: Array of platform specific options:

   -  ``where``: SQL WHERE condition to be used for partial indexes. It will
      only have effect on supported platforms.

Basic example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\Index;

    #[Entity]
    #[Index(name: "category_idx", columns: ["category"])]
    #[Index(name: "brand_idx", fields: ["brand"])]
    class ECommerceProduct
    {
    }

Example with partial indexes:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Index;

    #[Index(name: "search_idx", columns: {"category"},
        options: [
            "where": "((category IS NOT NULL))"
        ]
    )]
    class ECommerceProduct
    {
    }

.. _attrref_id:

#[Id]
~~~~~

The annotated instance variable will be marked as entity
identifier, the primary key in the database. This attribute is a
marker only and has no required or optional attributes. For
entities that have multiple identifier columns each column has to
be marked with ``#[Id]``.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Column;
    use Doctrine\ORM\Mapping\Id;

    #[Id, Column(type: "integer")]
    protected $id = null;

.. _attrref_inheritancetype:

#[InheritanceType]
~~~~~~~~~~~~~~~~~~

In an inheritance hierarchy you have to use this attribute on the
topmost/super class to define which strategy should be used for
inheritance. Currently Single Table and Class Table Inheritance are
supported.

This attribute has always been used in conjunction with the
:ref:`#[DiscriminatorMap] <attrref_discriminatormap>` and
:ref:`#[DiscriminatorColumn] <attrref_discriminatorcolumn>` attributes.

Examples:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\InheritanceType;
    use Doctrine\ORM\Mapping\DiscriminatorColumn;
    use Doctrine\ORM\Mapping\DiscriminatorMap;

    #[Entity]
    #[InheritanceType("SINGLE_TABLE")]
    #[DiscriminatorColumn(name: "discr", type: "string")]
    #[DiscriminatorMap({"person" = "Person", "employee" = "Employee"})]
    class Person
    {
        // ...
    }

    #[Entity]
    #[InheritanceType("JOINED")]
    #[DiscriminatorColumn(name: "discr", type: "string")]
    #[DiscriminatorMap({"person" = "Person", "employee" = "Employee"})]
    class Person
    {
        // ...
    }

.. _attrref_joincolumn:

#[JoinColumn], #[InverseJoinColumn]
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

This attribute is used in the context of relations in
:ref:`#[ManyToOne] <attrref_manytoone>`, :ref:`#[OneToOne] <attrref_onetoone>` fields
and in the Context of a :ref:`#[ManyToMany] <attrref_manytomany>`. If this attribute or both *name* and *referencedColumnName*
are missing they will be computed considering the field's name and the current
:doc:`naming strategy <namingstrategy>`.

The ``#[InverseJoinColumn]`` is the same as ``#[JoinColumn]`` and is used in the context
of a ``#[ManyToMany]`` attribute declaration to specifiy the details of the join table's
column information used for the join to the inverse entity.

Optional attributes:

-  **name**: Column name that holds the foreign key identifier for
   this relation. In the context of ``#[JoinTable]`` it specifies the column
   name in the join table.
-  **referencedColumnName**: Name of the primary key identifier that
   is used for joining of this relation. Defaults to ``id``.
-  **unique**: Determines whether this relation is exclusive between the
   affected entities and should be enforced as such on the database
   constraint level. Defaults to false.
-  **nullable**: Determine whether the related entity is required, or if
   null is an allowed state for the relation. Defaults to true.
-  **onDelete**: Cascade Action (Database-level)
-  **columnDefinition**: DDL SQL snippet that starts after the column
   name and specifies the complete (non-portable!) column definition.
   This attribute enables the use of advanced RMDBS features. Using
   this attribute on ``#[JoinColumn]`` is necessary if you need slightly
   different column definitions for joining columns, for example
   regarding NULL/NOT NULL defaults. However by default a
   "columnDefinition" attribute on :ref:`#[Column] <attrref_column>` also sets
   the related ``#[JoinColumn]``'s columnDefinition. This is necessary to
   make foreign keys work.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\OneToOne;
    use Doctrine\ORM\Mapping\JoinColumn;

    #[OneToOne(targetEntity: Customer::class)]
    #[JoinColumn(name: "customer_id", referencedColumnName: "id")]
    private $customer;

.. _attrref_jointable:

#[JoinTable]
~~~~~~~~~~~~

Using
:ref:`#[ManytoMany] <attrref_manytomany>` on the owning side of the relation
requires to specify the #[JoinTable] attribute which describes the
details of the database join table. If you do not specify
``#[JoinTable]`` on these relations reasonable mapping defaults apply
using the affected table and the column names.

A notable difference to the annotation metadata support, ``#[JoinColumn]``
and ``#[InverseJoinColumn]`` are specified at the property level and are not
nested within the ``#[JoinTable]`` attribute.

Required attribute:

-  **name**: Database name of the join-table

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\ManyToMany;
    use Doctrine\ORM\Mapping\JoinTable;

    #[ManyToMany(targetEntity: "Phonenumber")]
    #[JoinTable(name: "users_phonenumbers")]
    public $phonenumbers;

.. _attrref_manytoone:

#[ManyToOne]
~~~~~~~~~~~~

Defines that the annotated instance variable holds a reference that
describes a many-to-one relationship between two entities.

Required attributes:


-  **targetEntity**: FQCN of the referenced target entity. Can be the
   unqualified class name if both classes are in the same namespace.
   *IMPORTANT:* No leading backslash!

Optional attributes:


-  **cascade**: Cascade Option
-  **fetch**: One of LAZY or EAGER
-  inversedBy - The inversedBy attribute designates the field in
   the entity that is the inverse side of the relationship.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\ManyToOne;

    #[ManyToOne(targetEntity: "Cart", cascade: ["all"], fetch: "EAGER")]
    private $cart;

.. _attrref_manytomany:

#[ManyToMany]
~~~~~~~~~~~~~

Defines that the annotated instance variable holds a many-to-many relationship
between two entities. :ref:`#[JoinTable] <attrref_jointable>` is an
additional, optional attribute that has reasonable default
configuration values using the table and names of the two related
entities.

Required attributes:


-  **targetEntity**: FQCN of the referenced target entity. Can be the
   unqualified class name if both classes are in the same namespace.
   *IMPORTANT:* No leading backslash!

Optional attributes:


-  **mappedBy**: This option specifies the property name on the
   targetEntity that is the owning side of this relation. It is a
   required attribute for the inverse side of a relationship.
-  **inversedBy**: The inversedBy attribute designates the field in the
   entity that is the inverse side of the relationship.
-  **cascade**: Cascade Option
-  **fetch**: One of ``LAZY``, ``EXTRA_LAZY`` or ``EAGER``
-  **indexBy**: Index the collection by a field on the target entity.

.. note::

    For ``ManyToMany`` bidirectional relationships either side may
    be the owning side (the side that defines the ``#[JoinTable]`` and/or
    does not make use of the mappedBy attribute, thus using a default
    join table).

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\ManyToMany;
    use Doctrine\ORM\Mapping\JoinColumn;
    use Doctrine\ORM\Mapping\InverseJoinColumn;
    use Doctrine\ORM\Mapping\JoinTable;

    /** Owning Side */
    #[ManyToMany(targetEntity: "Group", inversedBy: "features")]
    #[JoinTable(name: "user_groups")]
    #[JoinColumn(name: "user_id", referencedColumnName: "id")]
    #[InverseJoinColumn(name: "group_id", referencedColumnName: "id")]
    private $groups;

    /** Inverse Side */
    #[ManyToMany(targetEntity: "User", mappedBy: "groups")]
    private $features;

.. _attrref_mappedsuperclass:

#[MappedSuperclass]
~~~~~~~~~~~~~~~~~~~

A mapped superclass is an abstract or concrete class that provides
persistent entity state and mapping information for its subclasses,
but which is not itself an entity. This attribute is specified on
the Class level and has no additional settings.

The ``#[MappedSuperclass]`` attribute cannot be used in conjunction with
``#[Entity]``. See the Inheritance Mapping section for
:doc:`more details on the restrictions of mapped superclasses <inheritance-mapping>`.

Optional attributes:

-  **repositoryClass**: Specifies the FQCN of a subclass of the EntityRepository.
   That will be inherited for all subclasses of that Mapped Superclass.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\MappedSuperclass;
    use Doctrine\ORM\Mapping\Entity;

    #[MappedSuperclass]
    abstract class BaseEntity
    {
        // ... fields and methods
    }

    #[Entity]
    class EntitySubClassFoo extends BaseEntity
    {
        // ... fields and methods
    }

.. _attrref_onetoone:

#[OneToOne]
~~~~~~~~~~~

The ``#[OneToOne]`` attribute works almost exactly as the
:ref:`#[ManyToOne] <attrref_manytoone>` with one additional option which can
be specified. When no
:ref:`#[JoinColumn] <attrref_joincolumn>` is specified it defaults to using the target entity table and
primary key column names and the current naming strategy to determine a name for the join column.

Required attributes:

-  **targetEntity**: FQCN of the referenced target entity. Can be the
   unqualified class name if both classes are in the same namespace.
   *IMPORTANT:* No leading backslash!

Optional attributes:

-  **cascade**: Cascade Option
-  **fetch**: One of LAZY or EAGER
-  **orphanRemoval**: Boolean that specifies if orphans, inverse
   OneToOne entities that are not connected to any owning instance,
   should be removed by Doctrine. Defaults to false.
-  **inversedBy**: The inversedBy attribute designates the field in the
   entity that is the inverse side of the relationship.

Example:

.. code-block:: php

    <?php
    #[OneToOne(targetEntity: "Customer")]
    #[JoinColumn(name: "customer_id", referencedColumnName: "id")]
    private $customer;

.. _attrref_onetomany:

#[OneToMany]
~~~~~~~~~~~~

Required attributes:

-  **targetEntity**: FQCN of the referenced target entity. Can be the
   unqualified class name if both classes are in the same namespace.
   *IMPORTANT:* No leading backslash!

Optional attributes:

-  **cascade**: Cascade Option
-  **orphanRemoval**: Boolean that specifies if orphans, inverse
   OneToOne entities that are not connected to any owning instance,
   should be removed by Doctrine. Defaults to false.
-  **mappedBy**: This option specifies the property name on the
   targetEntity that is the owning side of this relation. Its a
   required attribute for the inverse side of a relationship.
-  **fetch**: One of LAZY, EXTRA_LAZY or EAGER.
-  **indexBy**: Index the collection by a field on the target entity.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\OneToMany;

    #[OneToMany(
        targetEntity: "Phonenumber",
        mappedBy: "user",
        cascade: ["persist", "remove", "merge"],
        orphanRemoval: true)
    ]
    public $phonenumbers;

.. _attrref_orderby:

#[OrderBy]
~~~~~~~~~~

Optional attribute that can be specified with a
:ref:`#[ManyToMany] <attrref_manytomany>` or :ref:`#[OneToMany] <attrref_onetomany>`
attribute to specify by which criteria the collection should be
retrieved from the database by using an ORDER BY clause.

Example:

.. code-block:: php

    <?php
    #[ManyToMany(targetEntity: "Group")]
    #[OrderBy(["name" => "ASC"])]
    private $groups;

The key in ``OrderBy`` is only allowed to consist of
unqualified, unquoted field names and of an optional ``ASC``/``DESC``
positional statement. Multiple Fields are separated by a comma (,).
The referenced field names have to exist on the ``targetEntity``
class of the ``#[ManyToMany]`` or ``#[OneToMany]`` attribute.

.. _attrref_postload:

#[PostLoad]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a ``#[PostLoad]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_postpersist:

#[PostPersist]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a ``#[PostPersist]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_postremove:

#[PostRemove]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a ``#[PostRemove]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_postupdate:

#[PostUpdate]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a ``#[PostUpdate]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_prepersist:

#[PrePersist]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a ``#[PrePersist]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_preremove:

#[PreRemove]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a #``[PreRemove]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_preupdate:

#[PreUpdate]
~~~~~~~~~~~~~~

Marks a method on the entity to be called as a ``#[PreUpdate]`` event.
Only works with ``#[HasLifecycleCallbacks]`` in the entity class PHP
level.

.. _attrref_sequencegenerator:

#[SequenceGenerator]
~~~~~~~~~~~~~~~~~~~~~

For use with ``#[GeneratedValue(strategy: "SEQUENCE")]`` this
attribute allows to specify details about the sequence, such as
the increment size and initial values of the sequence.

Required attributes:

-  **sequenceName**: Name of the sequence

Optional attributes:

-  **allocationSize**: Increment the sequence by the allocation size
   when its fetched. A value larger than 1 allows optimization for
   scenarios where you create more than one new entity per request.
   Defaults to 10
-  **initialValue**: Where the sequence starts, defaults to 1.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Id;
    use Doctrine\ORM\Mapping\GeneratedValue;
    use Doctrine\ORM\Mapping\Column;
    use Doctrine\ORM\Mapping\SequenceGenerator;

    #[Id]
    #[GeneratedValue(strategy: "SEQUENCE")]
    #[Column(type: "integer")]
    #[SequenceGenerator(sequenceName: "tablename_seq", initialValue: 1, allocationSize: 100)]
    protected $id = null;

.. _attrref_table:

#[Table]
~~~~~~~~

Attribute describes the table an entity is persisted in. It is
placed on the entity-class level and is optional. If it is
not specified the table name will default to the entity's
unqualified classname.

Required attributes:

-  **name**: Name of the table

Optional attributes:

-  **schema**: Name of the schema the table lies in.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\Table;

    #[Entity]
    #[Table(name: "user", schema: "schema_name")]
    class User { }

.. _attrref_uniqueconstraint:

#[UniqueConstraint]
~~~~~~~~~~~~~~~~~~~

Attribute is used on
the entity-class level. It allows to hint the ``SchemaTool`` to
generate a database unique constraint on the specified table
columns. It only has meaning in the SchemaTool schema generation
context.

Required attributes:

-  **name**: Name of the Index
-  **columns**: Array of columns.

Optional attributes:

-  **options**: Array of platform specific options:

   -  ``where``: SQL WHERE condition to be used for partial indexes. It will
      only have effect on supported platforms.

Basic example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Entity;
    use Doctrine\ORM\Mapping\UniqueConstraint;

    #[Entity]
    #[UniqueConstraint(name: "ean", columns: ["ean"])]
    class ECommerceProduct
    {
    }

.. _attrref_version:

#[Version]
~~~~~~~~~~

Marker attribute that defines a specified column as version attribute used in
an :ref:`optimistic locking <transactions-and-concurrency_optimistic-locking>`
scenario. It only works on :ref:`#[Column] <attrref_column>` attributes that have
the type ``integer`` or ``datetime``. Setting ``#[Version]`` on a property with
:ref:`#[Id <attrref_id>` is not supported.

Example:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\Column;
    use Doctrine\ORM\Mapping\Version;

    #[Column(type: "integer")]
    #[Version]
    protected $version;
