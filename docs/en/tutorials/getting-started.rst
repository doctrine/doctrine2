Getting Started with Doctrine
=============================

This guide covers getting started with the Doctrine ORM. After working
through the guide you should know:

- How to install and configure Doctrine by connecting it to a database
- Mapping PHP objects to database tables
- Generating a database schema from PHP objects
- Using the ``EntityManager`` to insert, update, delete and find
  objects in the database.

Guide Assumptions
-----------------

This guide is designed for beginners that haven't worked with Doctrine ORM
before. There are some prerequisites for the tutorial that have to be
installed:

- PHP (latest stable version)
- Composer Package Manager (\ `Install Composer <https://getcomposer.org/doc/00-intro.md>`_)

The code of this tutorial is `available on Github <https://github.com/doctrine/doctrine2-orm-tutorial>`_.

What is Doctrine?
-----------------

Doctrine ORM is an `object-relational mapper (ORM) <https://en.wikipedia.org/wiki/Object-relational_mapping>`_
for PHP that provides transparent persistence for PHP objects. It uses the Data Mapper
pattern at the heart, aiming for a complete separation of your domain/business
logic from the persistence in a relational database management system.

The benefit of Doctrine for the programmer is the ability to focus
on the object-oriented business logic and worry about persistence only
as a secondary problem. This doesn't mean persistence is downplayed by Doctrine
2, however it is our belief that there are considerable benefits for
object-oriented programming if persistence and entities are kept
separated.

What are Entities?
~~~~~~~~~~~~~~~~~~

Entities are PHP Objects that can be identified over many requests
by a unique identifier or primary key. These classes don't need to extend any
abstract base class or interface.

An entity contains persistable properties. A persistable property
is an instance variable of the entity that is saved into and retrieved from the database
by Doctrine's data mapping capabilities.

An entity class can be final or read-only. It may contain final methods or read-only properties too.

An Example Model: Bug Tracker
-----------------------------

For this Getting Started Guide for Doctrine we will implement the
Bug Tracker domain model from the
`Zend_Db_Table <https://framework.zend.com/manual/1.12/en/zend.db.adapter.html>`_
documentation. Reading their documentation we can extract the
requirements:

-  A Bug has a description, creation date, status, reporter and
   engineer
-  A Bug can occur on different Products (platforms)
-  A Product has a name.
-  Bug reporters and engineers are both Users of the system.
-  A User can create new Bugs.
-  The assigned engineer can close a Bug.
-  A User can see all their reported or assigned Bugs.
-  Bugs can be paginated through a list-view.

Project Setup
-------------

Create a new empty folder for this tutorial project, for example
``doctrine2-tutorial`` and create a new file ``composer.json`` inside
that directory with the following contents:

::

    {
        "require": {
            "doctrine/orm": "^3",
            "doctrine/dbal": "^4",
            "symfony/cache": "^7"
        },
        "autoload": {
            "psr-0": {"": "src/"}
        }
    }


Install Doctrine using the Composer Dependency Management tool, by calling:

::

    $ composer install

This will install the packages Doctrine Common, Doctrine DBAL, Doctrine ORM,
into the ``vendor`` directory.

Add the following directories::

    doctrine2-tutorial
    |-- config
    |   `-- xml
    `-- src

.. note::
    It is strongly recommended that you require ``doctrine/dbal`` in your
    ``composer.json`` as well, because using the ORM means mapping objects
    and their fields to database tables and their columns, and that
    requires mentioning so-called types that are defined in ``doctrine/dbal``
    in your application. Having an explicit requirement means you control
    when the upgrade to the next major version happens, so that you can
    do the necessary changes in your application beforehand.

Obtaining the EntityManager
---------------------------

Doctrine's public interface is through the ``EntityManager``. This class
provides access points to the complete lifecycle management for your entities,
and transforms entities from and back to persistence. You have to
configure and create it to use your entities with Doctrine ORM. I
will show the configuration steps and then discuss them step by
step:

.. code-block:: php

    <?php
    // bootstrap.php
    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\ORMSetup;

    require_once "vendor/autoload.php";

    // Create a simple "default" Doctrine ORM configuration for Attributes
    $config = ORMSetup::createAttributeMetadataConfig( // on PHP < 8.4, use ORMSetup::createAttributeMetadataConfiguration()
        paths: [__DIR__ . '/src'],
        isDevMode: true,
    );
    // or if you prefer XML
    // $config = ORMSetup::createXMLMetadataConfig( // on PHP < 8.4, use ORMSetup::createXMLMetadataConfiguration()
    //    paths: [__DIR__ . '/config/xml'],
    //    isDevMode: true,
    //);

    // configuring the database connection
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'path' => __DIR__ . '/db.sqlite',
    ], $config);

    // obtaining the entity manager
    $entityManager = new EntityManager($connection, $config);

The ``require_once`` statement sets up the class autoloading for Doctrine and
its dependencies using Composer's autoloader.

The second block consists of the instantiation of the ORM
``Configuration`` object using the ``ORMSetup`` helper. It assumes a bunch
of defaults that you don't have to bother about for now. You can
read up on the configuration details in the
:doc:`reference chapter on configuration <../reference/configuration>`.

The third block shows the configuration options required to connect to
a database. In this case, we'll use a file-based SQLite database. All the
configuration options for all the shipped drivers are given in the
`DBAL Configuration section of the manual <https://www.doctrine-project.org/projects/doctrine-dbal/en/current/>`_.

The last block shows how the ``EntityManager`` is obtained from a
factory method.

Generating the Database Schema
------------------------------

Doctrine has a command-line interface that allows you to access the SchemaTool,
a component that can generate a relational database schema based entirely on the
defined entity classes and their metadata. For this tool to work, you need to
create an executable console script as described in the
:doc:`tools chapter <../reference/tools>`.

If you created the ``bootstrap.php`` file as described in the previous section,
that script could look like this:

.. code-block:: php

    #!/usr/bin/env php
    <?php
    // bin/doctrine

    use Doctrine\ORM\Tools\Console\ConsoleRunner;
    use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;

    // Adjust this path to your actual bootstrap.php
    require __DIR__ . 'path/to/your/bootstrap.php';

    ConsoleRunner::run(
        new SingleManagerProvider($entityManager)
    );

In the following examples, we will assume that this script has been created as
``bin/doctrine``.

::

    $ php bin/doctrine orm:schema-tool:create

Since we haven't added any entity metadata in ``src`` yet, you'll see a message
stating "No Metadata Classes to process." In the next section, we'll create a
Product entity along with the corresponding metadata, and run this command again.

Note that as you modify your entities' metadata during the development process,
you'll need to update your database schema to stay in sync with the metadata.
You can easily recreate the database using the following commands:

::

    $ php bin/doctrine orm:schema-tool:drop --force
    $ php bin/doctrine orm:schema-tool:create

Or you can use the update functionality:

::

    $ php bin/doctrine orm:schema-tool:update --force

The updating of databases uses a diff algorithm for a given
database schema. This is a cornerstone of the ``Doctrine\DBAL`` package,
which can even be used without the Doctrine ORM package.

Starting with the Product Entity
--------------------------------

We start with the simplest entity, the Product. Create a ``src/Product.php`` file to contain the ``Product``
entity definition:

.. code-block:: php

    <?php
    // src/Product.php
    class Product
    {
        private int|null $id = null;
        private string $name;
    }

When creating entity classes, all of the fields should be ``private``.

Use ``protected`` when strictly needed and very rarely if not ever ``public``.

Adding behavior to Entities
~~~~~~~~~~~~~~~~~~~~~~~~~~~

There are two options to define methods in entities:
**getters/setters**, or **mutators and DTOs**,
respectively for **anemic entities** or **rich entities**.

**Anemic entities: Getters and setters**

The most popular method is to create two kinds of methods to
**read** (getter) and **update** (setter) the object's properties.

The id field has no setter since, generally speaking, your code
should not set this value since it represents a database id value.
(Note that Doctrine itself can still set the value using the
Reflection API instead of a defined setter function.)

.. note::

    Doctrine ORM does not use any of the methods you defined: it uses
    reflection to read and write values to your objects, and will never
    call methods, not even ``__construct``.

This approach is mostly used when you want to focus on behavior-less
entities, and when you want to have all your business logic in your
services rather than in the objects themselves.

Getters and setters are a common convention which makes it possible to
expose each field of your entity to the external world, while allowing
you to keep some type safety in place.

Such an approach is a good choice for RAD (rapid application development),
but may lead to problems later down the road, because providing such an
easy way to modify any field in your entity means that the entity itself
cannot guarantee validity of its internal state. Having any object in
invalid state is dangerous:

- An invalid state can bring bugs in your business logic.
- The state can be implicitly saved in the database: any forgotten ``flush``
  can persist the broken state.
- If persisted, the corrupted data will be retrieved later in your application
  when the data is loaded again, thereby leading to bugs in your business logic.
- When bugs occur after corrupted data is persisted, troubleshooting will
  become much harder, and you might be aware of the bug too late to fix it in a
  proper manner.

implicitly saved in database, thereby leading to corrupted or inconsistent
data in your storage, and later in your application when the data is loaded again.

.. note::

    This method, although very common, is inappropriate for Domain Driven
    Design (\ `DDD <https://en.wikipedia.org/wiki/Domain-driven_design>`_)
    where methods should represent real business operations and not simple
    property change, And business invariants should be maintained both in the
    application state (entities in this case) and in the database, with no
    space for data corruption.

Here is an example of a simple **anemic entity**:

.. configuration-block::

    .. code-block:: php

        <?php
        class User
        {
            private $username;
            private $passwordHash;
            private $bans;

            public function getUsername(): string
            {
                return $this->username;
            }

            public function setUsername(string $username): void
            {
                $this->username = $username;
            }

            public function getPasswordHash(): string
            {
                return $this->passwordHash;
            }

            public function setPasswordHash(string $passwordHash): void
            {
                $this->passwordHash = $passwordHash;
            }

            public function getBans(): array
            {
                return $this->bans;
            }

            public function addBan(Ban $ban): void
            {
                $this->bans[] = $ban;
            }
        }

In the example above, we avoid all possible logic in the entity and only care
about putting and retrieving data into it without validation (except the one
provided by type-hints) nor consideration about the object's state.

As Doctrine ORM is a persistence tool for your domain, the state of an object is
really important. This is why we strongly recommend using rich entities.

**Rich entities: Mutators and DTOs**

We recommend using a rich entity design and rely on more complex mutators,
and if needed based on DTOs.
In this design, you should **not** use getters nor setters, and instead,
implement methods that represent the **behavior** of your domain.

For example, when having a ``User`` entity, we could foresee
the following kind of optimization.

Example of a rich entity with proper accessors and mutators:

.. configuration-block::

    .. code-block:: php
        <?php
        class User
        {
            private $banned;
            private $username;
            private $passwordHash;
            private $bans;

            public function toNickname(): string
            {
                return $this->username;
            }

            public function authenticate(string $password, callable $checkHash): bool
            {
                return $checkHash($password, $this->passwordHash) && ! $this->hasActiveBans();
            }

            public function changePassword(string $password, callable $hash): void
            {
                $this->passwordHash = $hash($password);
            }

            public function ban(\DateInterval $duration): void
            {
                assert($duration->invert !== 1);

                $this->bans[] = new Ban($this);
            }
        }

.. note::

    Please note that this example is only a stub. When going further in the
    documentation, we will update this object with more behavior and maybe
    update some methods.

The entities should only mutate state after checking that all business logic
invariants are being respected.
Additionally, our entities should never see their state change without
validation. For example, creating a ``new Product()`` object without any data
makes it an **invalid object**.
Rich entities should represent **behavior**, not **data**, therefore
they should be valid even after a ``__construct()`` call.

To help creating such objects, we can rely on ``DTOs``, and/or make
our entities always up-to-date. This can be performed with static constructors,
or rich mutators that accept ``DTOs`` as parameters.

The role of the ``DTO`` is to maintain the entity's state and to help us rely
upon objects that correctly represent the data that is used to mutate the
entity.

.. note::

    A `DTO <https://en.wikipedia.org/wiki/Data_transfer_object>`_ is an object
    that only carries data without any logic. Its only goal is to be transferred
    from one service to another.
    A ``DTO`` often represents data sent by a client and that has to be validated,
    but can also be used as simple data carrier for other cases.

By using ``DTOs``, if we take our previous ``User`` example, we could create
a ``ProfileEditingForm`` DTO that will be a plain model, totally unrelated to
our database, that will be populated via a form and validated.
Then we can add a new mutator to our ``User``:

.. configuration-block::

    .. code-block:: php
        <?php
        class User
        {
            public function updateFromProfile(ProfileEditingDTO $profileFormDTO): void
            {
                // ...
            }

            public static function createFromRegistration(UserRegistrationDTO $registrationDTO): self
            {
                // ...
            }
        }

There are several advantages to using such a model:

* **Entity state is always valid.** Since no setters exist, this means that we
only update portions of the entity that should already be valid.

* Instead of having plain getters and setters, our entity now has
**real behavior**: it is much easier to determine the logic in the domain.

* DTOs can be reused in other components, for example deserializing mixed
content, using forms...

* Classic and static constructors can be used to manage different ways to
create our objects, and they can also use DTOs.

* Anemic entities tend to isolate the entity from logic, whereas rich
entities allow putting the logic in the object itself, including data
validation.

The next step for persistence with Doctrine is to describe the structure of
the ``Product`` entity to Doctrine using a metadata language. The metadata
language describes how entities, their properties and references should be
persisted and what constraints should be applied to them.

Metadata for an Entity can be configured using attributes directly in
the Entity class itself, or in an external XML file. This
Getting Started guide will demonstrate metadata mappings using both
methods, but you only need to choose one.

.. configuration-block::

    .. code-block:: attribute

        <?php
        // src/Product.php

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'products')]
        class Product
        {
            #[ORM\Id]
            #[ORM\Column(type: 'integer')]
            #[ORM\GeneratedValue]
            private int|null $id = null;
            #[ORM\Column(type: 'string')]
            private string $name;

            // .. (other code)
        }

    .. code-block:: xml

        <!-- config/xml/Product.dcm.xml -->
        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                          xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                              https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

              <entity name="Product" table="products">
                  <id name="id" type="integer">
                      <generator strategy="AUTO" />
                  </id>

                  <field name="name" type="string" />
              </entity>
        </doctrine-mapping>

The top-level ``entity`` definition specifies information about
the class and table name. The primitive type ``Product#name`` is
defined as a ``field`` attribute. The ``id`` property is defined with
the ``id`` tag.  It has a ``generator`` tag nested inside, which
specifies that the primary key generation mechanism should automatically
use the database platform's native id generation strategy (for
example, AUTO INCREMENT in the case of MySql, or Sequences in the
case of PostgreSQL and Oracle).

Now that we have defined our first entity and its metadata,
let's update the database schema:

::

    $ php bin/doctrine orm:schema-tool:update --force --dump-sql

Specifying both flags ``--force`` and ``--dump-sql`` will cause the DDL
statements to be executed and then printed to the screen.

Now, we'll create a new script to insert products into the database:

.. code-block:: php

    <?php
    // create_product.php <name>
    require_once "bootstrap.php";

    $newProductName = $argv[1];

    $product = new Product();
    $product->setName($newProductName);

    $entityManager->persist($product);
    $entityManager->flush();

    echo "Created Product with ID " . $product->getId() . "\n";

Call this script from the command-line to see how new products are created:

::

    $ php create_product.php ORM
    $ php create_product.php DBAL

What is happening here? Using the ``Product`` class is pretty standard OOP.
The interesting bits are the use of the ``EntityManager`` service. To
notify the EntityManager that a new entity should be inserted into the database,
you have to call ``persist()``. To initiate a transaction to actually *perform*
the insertion, you have to explicitly call ``flush()`` on the ``EntityManager``.

This distinction between persist and flush is what allows the aggregation of
all database writes (INSERT, UPDATE, DELETE) into one single transaction, which
is executed when ``flush()`` is called. Using this approach, the write-performance
is significantly better than in a scenario in which writes are performed on
each entity in isolation.

Next, we'll fetch a list of all the Products in the database. Let's create a
new script for this:

.. code-block:: php

    <?php
    // list_products.php
    require_once "bootstrap.php";

    $productRepository = $entityManager->getRepository('Product');
    $products = $productRepository->findAll();

    foreach ($products as $product) {
        echo sprintf("-%s\n", $product->getName());
    }

The ``EntityManager#getRepository()`` method can create a finder object (called
a repository) for every type of entity. It is provided by Doctrine and contains
some finder methods like ``findAll()``.

Let's continue by creating a script to display the name of a product based on its ID:

.. code-block:: php

    <?php
    // show_product.php <id>
    require_once "bootstrap.php";

    $id = $argv[1];
    $product = $entityManager->find('Product', $id);

    if ($product === null) {
        echo "No product found.\n";
        exit(1);
    }

    echo sprintf("-%s\n", $product->getName());

Next we'll update a product's name, given its id. This simple example will
help demonstrate Doctrine's implementation of the :ref:`UnitOfWork pattern <unit-of-work>`. Doctrine
keeps track of all the entities that were retrieved from the Entity Manager,
and can detect when any of those entities' properties have been modified.
As a result, rather than needing to call ``persist($entity)`` for each individual
entity whose properties were changed, a single call to ``flush()`` at the end of a
request is sufficient to update the database for all of the modified entities.

.. code-block:: php

    <?php
    // update_product.php <id> <new-name>
    require_once "bootstrap.php";

    $id = $argv[1];
    $newName = $argv[2];

    $product = $entityManager->find('Product', $id);

    if ($product === null) {
        echo "Product $id does not exist.\n";
        exit(1);
    }

    $product->setName($newName);

    $entityManager->flush();

After calling this script on one of the existing products, you can verify the
product name changed by calling the ``show_product.php`` script.

Adding Bug and User Entities
----------------------------

We continue with the bug tracker example by creating the ``Bug`` and ``User``
classes. We'll store them in ``src/Bug.php`` and ``src/User.php``, respectively.

.. code-block:: php

    <?php
    // src/Bug.php

    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    #[ORM\Table(name: 'bugs')]
    class Bug
    {
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        private int|null $id;

        #[ORM\Column(type: 'string')]
        private string $description;

        #[ORM\Column(type: 'datetime')]
        private DateTime $created;

        #[ORM\Column(type: 'string')]
        private string $status;

        public function getId(): int|null
        {
            return $this->id;
        }

        public function getDescription(): string
        {
            return $this->description;
        }

        public function setDescription(string $description): void
        {
            $this->description = $description;
        }

        public function setCreated(DateTime $created)
        {
            $this->created = $created;
        }

        public function getCreated(): DateTime
        {
            return $this->created;
        }

        public function setStatus($status): void
        {
            $this->status = $status;
        }

        public function getStatus():string
        {
            return $this->status;
        }
    }

.. code-block:: php

    <?php
    // src/User.php

    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    #[ORM\Table(name: 'users')]
    class User
    {
        /** @var int */
        #[ORM\Id]
        #[ORM\GeneratedValue]
        #[ORM\Column(type: 'integer')]
        private int|null $id = null;

        /** @var string */
        #[ORM\Column(type: 'string')]
        private string $name;

        public function getId(): int|null
        {
            return $this->id;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function setName(string $name): void
        {
            $this->name = $name;
        }
    }

All of the properties we've seen so far are of simple types (integer, string,
and datetime). But now, we'll add properties that will store objects of
specific *entity types* in order to model the relationships between different
entities.

At the database level, relationships between entities are represented by foreign
keys. But with Doctrine, you'll never have to (and never should) work with
the foreign keys directly. You should only work with objects that represent
foreign keys through their own identities.

For every foreign key you either have a Doctrine ManyToOne or OneToOne
association. On the inverse sides of these foreign keys you can have
OneToMany associations. Obviously you can have ManyToMany associations
that connect two tables with each other through a join table with
two foreign keys.

Now that you know the basics about references in Doctrine, we can extend the
domain model to match the requirements:

.. code-block:: php

    <?php
    // src/Bug.php

    use Doctrine\Common\Collections\ArrayCollection;
    use Doctrine\Common\Collections\Collection;

    class Bug
    {
        // ... (previous code)

        /** @var Collection<int, Product> */
        private Collection $products;

        public function __construct()
        {
            $this->products = new ArrayCollection();
        }
    }

.. code-block:: php

    <?php
    // src/User.php
    use Doctrine\Common\Collections\ArrayCollection;

    class User
    {
        // ... (previous code)

        /** @var Collection<int, Bug> */
        private Collection $reportedBugs;
        /** @var Collection<int, Bug> */
        private Collection $assignedBugs;

        public function __construct()
        {
            $this->reportedBugs = new ArrayCollection();
            $this->assignedBugs = new ArrayCollection();
        }
    }

.. note::

    Whenever an entity is created from the database, a ``Collection``
    implementation of the type ``PersistentCollection`` will be injected into
    your entity instead of an ``ArrayCollection``. This helps Doctrine ORM
    understand the changes that have happened to the collection that are
    noteworthy for persistence.

Because we only work with collections for the references we must be
careful to implement a bidirectional reference in the domain model.
The concept of owning or inverse side of a relation is central to
this notion and should always be kept in mind. The following
assumptions are made about relations and have to be followed to be
able to work with Doctrine ORM. These assumptions are not unique to
Doctrine ORM but are best practices in handling database relations
and Object-Relational Mapping.

-  In a one-to-one relation, the entity holding the foreign key of
   the related entity on its own database table is *always* the owning
   side of the relation.
-  In a many-to-one relation, the Many-side is the owning side by
   default because it holds the foreign key. Accordingly, the One-side
   is the inverse side by default.
-  In a many-to-one relation, the One-side can only be the owning side if
   the relation is implemented as a ManyToMany with a join table, and the
   One-side is restricted to allow only UNIQUE values per database constraint.
-  In a many-to-many relation, both sides can be the owning side of
   the relation. However, in a bi-directional many-to-many relation,
   only one side is allowed to be the owning side.
-  Changes to Collections are saved or updated, when the entity on
   the *owning* side of the collection is saved or updated.
-  Saving an Entity at the inverse side of a relation never
   triggers a persist operation to changes to the collection.

.. note::

    Consistency of bi-directional references on the inverse side of a
    relation have to be managed in userland application code. Doctrine
    cannot magically update your collections to be consistent.


In the case of Users and Bugs we have references back and forth to
the assigned and reported bugs from a user, making this relation
bi-directional. We have to change the code to ensure consistency of
the bi-directional reference:

.. code-block:: php

    <?php
    // src/Bug.php
    class Bug
    {
        // ... (previous code)

        private User $engineer;
        private User $reporter;

        public function setEngineer(User $engineer): void
        {
            $engineer->assignedToBug($this);
            $this->engineer = $engineer;
        }

        public function setReporter(User $reporter): void
        {
            $reporter->addReportedBug($this);
            $this->reporter = $reporter;
        }

        public function getEngineer(): User
        {
            return $this->engineer;
        }

        public function getReporter(): User
        {
            return $this->reporter;
        }
    }

.. code-block:: php

    <?php
    // src/User.php
    class User
    {
        // ... (previous code)

        /** @var Collection<int, Bug> */
        private Collection $reportedBugs;
        /** @var Collection<int, Bug> */
        private Collection $assignedBugs;

        public function addReportedBug(Bug $bug): void
        {
            $this->reportedBugs[] = $bug;
        }

        public function assignedToBug(Bug $bug): void
        {
            $this->assignedBugs[] = $bug;
        }
    }

I chose to name the inverse methods in past-tense, which should
indicate that the actual assigning has already taken place and the
methods are only used for ensuring consistency of the references.
This approach is my personal preference, you can choose whatever
method to make this work.

You can see from ``User#addReportedBug()`` and
``User#assignedToBug()`` that using this method in userland alone
would not add the Bug to the collection of the owning side in
``Bug#reporter`` or ``Bug#engineer``. Using these methods and
calling Doctrine for persistence would not update the Collections'
representation in the database.

Only using ``Bug#setEngineer()`` or ``Bug#setReporter()``
correctly saves the relation information.

The ``Bug#reporter`` and ``Bug#engineer`` properties are
Many-To-One relations, which point to a User. In a normalized
relational model, the foreign key is saved on the Bug's table, hence
in our object-relation model the Bug is at the owning side of the
relation. You should always make sure that the use-cases of your
domain model should drive which side is an inverse or owning one in
your Doctrine mapping. In our example, whenever a new bug is saved
or an engineer is assigned to the bug, we don't want to update the
User to persist the reference, but the Bug. This is the case with
the Bug being at the owning side of the relation.

Bugs reference Products by a uni-directional ManyToMany relation in
the database that points from Bugs to Products.

.. code-block:: php

    <?php
    // src/Bug.php
    class Bug
    {
        // ... (previous code)

        /** @var Collection<int, Product> */
        private Collection $products;

        public function assignToProduct(Product $product): void
        {
            $this->products[] = $product;
        }

        /** @return Collection<int, Product> */
        public function getProducts(): Collection
        {
            return $this->products;
        }
    }

We are now finished with the domain model given the requirements.
Lets add metadata mappings for the ``Bug`` entity, as we did for
the ``Product`` before:

.. configuration-block::
    .. code-block:: attribute

        <?php
        // src/Bug.php

        use DateTime;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'bugs')]
        class Bug
        {
            #[ORM\Id]
            #[ORM\Column(type: 'integer')]
            #[ORM\GeneratedValue]
            private int|null $id = null;

            #[ORM\Column(type: 'string')]
            private string $description;

            #[ORM\Column(type: 'datetime')]
            private DateTime $created;

            #[ORM\Column(type: 'string')]
            private string $status;

            #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'assignedBugs')]
            private User|null $engineer = null;

            #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'reportedBugs')]
            private User|null $reporter;

            /** @var Collection<int, Product> */
            #[ORM\ManyToMany(targetEntity: Product::class)]
            private Collection $products;

            // ... (other code)
        }

    .. code-block:: xml

        <!-- config/xml/Bug.dcm.xml -->
        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                          xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                              https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

            <entity name="Bug" table="bugs">
                <id name="id" type="integer">
                    <generator strategy="AUTO" />
                </id>

                <field name="description" type="text" />
                <field name="created" type="datetime" />
                <field name="status" type="string" />

                <many-to-one target-entity="User" field="reporter" inversed-by="reportedBugs" />
                <many-to-one target-entity="User" field="engineer" inversed-by="assignedBugs" />

                <many-to-many target-entity="Product" field="products" />
            </entity>
        </doctrine-mapping>

Here we have the entity, id and primitive type definitions.
For the "created" field we have used the ``datetime`` type,
which translates the YYYY-mm-dd HH:mm:ss database format
into a PHP DateTime instance and back.

After the field definitions, the two qualified references to the
user entity are defined. They are created by the ``ManyToOne``
attribute. The class name of the related entity has to be specified with
the ``targetEntity`` parameter, which is enough information for
the database mapper to access the foreign table. Since
``reporter`` and ``engineer`` are on the owning side of a
bi-directional relation, we also have to specify the ``inversedBy``
parameter. They have to point to the field names on the inverse
side of the relationship. We will see in the next example that the ``inversedBy``
parameter has a counterpart ``mappedBy`` which makes that
the inverse side.

The last definition is for the ``Bug#products`` collection. It
holds all products where the specific bug occurs. Again
you have to define the ``targetEntity`` and ``field`` parameters
on the ``ManyToMany`` attribute.

Finally, we'll add metadata mappings for the ``User`` entity.

.. configuration-block::

    .. code-block:: attribute

        <?php
        // src/User.php

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'users')]
        class User
        {
            #[ORM\Id]
            #[ORM\GeneratedValue]
            #[ORM\Column(type: 'integer')]
            private int|null $id = null;

            #[ORM\Column(type: 'string')]
            private string $name;

            /** @var Collection<int, Bug> An ArrayCollection of Bug objects. */
            #[ORM\OneToMany(targetEntity: Bug::class, mappedBy: 'reporter')]
            private Collection $reportedBugs;

            /** @var Collection<int,Bug> An ArrayCollection of Bug objects. */
            #[ORM\OneToMany(targetEntity: Bug::class, mappedBy: 'engineer')]
            private $assignedBugs;

            // .. (other code)
        }

    .. code-block:: xml

        <!-- config/xml/User.dcm.xml -->
        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                          xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                              https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

             <entity name="User" table="users">
                 <id name="id" type="integer">
                     <generator strategy="AUTO" />
                 </id>

                 <field name="name" type="string" />

                 <one-to-many target-entity="Bug" field="reportedBugs" mapped-by="reporter" />
                 <one-to-many target-entity="Bug" field="assignedBugs" mapped-by="engineer" />
             </entity>
        </doctrine-mapping>

Here are some new things to mention about the ``one-to-many`` tags.
Remember that we discussed about the inverse and owning side. Now
both reportedBugs and assignedBugs are inverse relations, which
means the join details have already been defined on the owning
side. Therefore we only have to specify the property on the Bug
class that holds the owning sides.

Update your database schema by running::

    $ php bin/doctrine orm:schema-tool:update --force


Implementing more Requirements
------------------------------

So far, we've seen the most basic features of the metadata definition language.
To explore additional functionality, let's first create new ``User`` entities:

.. code-block:: php

    <?php
    // create_user.php
    require_once "bootstrap.php";

    $newUsername = $argv[1];

    $user = new User();
    $user->setName($newUsername);

    $entityManager->persist($user);
    $entityManager->flush();

    echo "Created User with ID " . $user->getId() . "\n";

Now call:

::

    $ php create_user.php beberlei

We now have the necessary data to create a new Bug entity:

.. code-block:: php

    <?php
    // create_bug.php <reporter-id> <engineer-id> <product-ids>
    require_once "bootstrap.php";

    $reporterId = $argv[1];
    $engineerId = $argv[2];
    $productIds = explode(",", $argv[3]);

    $reporter = $entityManager->find("User", $reporterId);
    $engineer = $entityManager->find("User", $engineerId);
    if (!$reporter || !$engineer) {
        echo "No reporter and/or engineer found for the given id(s).\n";
        exit(1);
    }

    $bug = new Bug();
    $bug->setDescription("Something does not work!");
    $bug->setCreated(new DateTime("now"));
    $bug->setStatus("OPEN");

    foreach ($productIds as $productId) {
        $product = $entityManager->find("Product", $productId);
        $bug->assignToProduct($product);
    }

    $bug->setReporter($reporter);
    $bug->setEngineer($engineer);

    $entityManager->persist($bug);
    $entityManager->flush();

    echo "Your new Bug Id: ".$bug->getId()."\n";

Since we only have one user and product, probably with the ID of 1, we can
call this script as follows:

::

    php create_bug.php 1 1 1

See how simple it is to relate a Bug, Reporter, Engineer and Products?
Also recall that thanks to the :ref:`UnitOfWork pattern <unit-of-work>`, Doctrine will detect
these relations and update all of the modified entities in the database
automatically when ``flush()`` is called.

Queries for Application Use-Cases
---------------------------------

List of Bugs
~~~~~~~~~~~~

Using the previous examples we can fill up the database quite a
bit. However, we now need to discuss how to query the underlying
mapper for the required view representations. When opening the
application, bugs can be paginated through a list-view, which is
the first read-only use-case:

.. code-block:: php

    <?php
    // list_bugs.php
    require_once "bootstrap.php";

    $dql = "SELECT b, e, r FROM Bug b JOIN b.engineer e JOIN b.reporter r ORDER BY b.created DESC";

    $query = $entityManager->createQuery($dql);
    $query->setMaxResults(30);
    $bugs = $query->getResult();

    foreach ($bugs as $bug) {
        echo $bug->getDescription()." - ".$bug->getCreated()->format('d.m.Y')."\n";
        echo "    Reported by: ".$bug->getReporter()->getName()."\n";
        echo "    Assigned to: ".$bug->getEngineer()->getName()."\n";
        foreach ($bug->getProducts() as $product) {
            echo "    Platform: ".$product->getName()."\n";
        }
        echo "\n";
    }

The DQL Query in this example fetches the 30 most recent bugs with
their respective engineer and reporter in one single SQL statement.
The console output of this script is then:

::

    Something does not work! - 02.04.2010
        Reported by: beberlei
        Assigned to: beberlei
        Platform: My Product

.. note::

    **DQL is not SQL**

    You may wonder why we start writing SQL at the beginning of this
    use-case. Don't we use an ORM to get rid of all the endless
    hand-writing of SQL? Doctrine introduces DQL which is best
    described as **object-query-language** and is a dialect of
    `OQL <https://en.wikipedia.org/wiki/Object_Query_Language>`_ and
    similar to `HQL <http://www.hibernate.org>`_ or
    `JPQL <https://en.wikipedia.org/wiki/Java_Persistence_Query_Language>`_.
    It does not know the concept of columns and tables, but only those
    of Entity-Class and property. Using the Metadata we defined before
    it allows for very short distinctive and powerful queries.


    An important reason why DQL is favourable to the Query API of most
    ORMs is its similarity to SQL. The DQL language allows query
    constructs that most ORMs don't: GROUP BY even with HAVING,
    Sub-selects, Fetch-Joins of nested classes, mixed results with
    entities and scalar data such as COUNT() results and much more.
    Using DQL you should seldom come to the point where you want to
    throw your ORM into the dumpster, because it doesn't support some
    the more powerful SQL concepts.

    If you need to build your query dynamically, you can use the ``QueryBuilder`` retrieved
    by calling ``$entityManager->createQueryBuilder()``. There are more
    details about this in the relevant part of the documentation.


    As a last resort you can still use Native SQL and a description of the
    result set to retrieve entities from the database. DQL boils down to a
    Native SQL statement and a ``ResultSetMapping`` instance itself. Using
    Native SQL you could even use stored procedures for data retrieval, or
    make use of advanced non-portable database queries like PostgreSQL's
    recursive queries.


Array Hydration of the Bug List
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

In the previous use-case we retrieved the results as their
respective object instances. We are not limited to retrieving
objects only from Doctrine however. For a simple list view like the
previous one we only need read access to our entities and can
switch the hydration from objects to simple PHP arrays instead.

Hydration can be an expensive process so only retrieving what you need can
yield considerable performance benefits for read-only requests.

Implementing the same list view as before using array hydration we
can rewrite our code:

.. code-block:: php

    <?php
    // list_bugs_array.php
    require_once "bootstrap.php";

    $dql = "SELECT b, e, r, p FROM Bug b JOIN b.engineer e ".
           "JOIN b.reporter r JOIN b.products p ORDER BY b.created DESC";
    $query = $entityManager->createQuery($dql);
    $bugs = $query->getArrayResult();

    foreach ($bugs as $bug) {
        echo $bug['description'] . " - " . $bug['created']->format('d.m.Y')."\n";
        echo "    Reported by: ".$bug['reporter']['name']."\n";
        echo "    Assigned to: ".$bug['engineer']['name']."\n";
        foreach ($bug['products'] as $product) {
            echo "    Platform: ".$product['name']."\n";
        }
        echo "\n";
    }

There is one significant difference in the DQL query however, we
have to add an additional fetch-join for the products connected to
a bug. The resulting SQL query for this single select statement is
pretty large, however still more efficient to retrieve compared to
hydrating objects.

Find by Primary Key
~~~~~~~~~~~~~~~~~~~

The next Use-Case is displaying a Bug by primary key. This could be
done using DQL as in the previous example with a where clause,
however there is a convenience method on the ``EntityManager`` that
handles loading by primary key, which we have already seen in the
write scenarios:

.. code-block:: php

    <?php
    // show_bug.php <id>
    require_once "bootstrap.php";

    $theBugId = $argv[1];

    $bug = $entityManager->find("Bug", (int)$theBugId);

    echo "Bug: ".$bug->getDescription()."\n";
    echo "Engineer: ".$bug->getEngineer()->getName()."\n";

The output of the engineer’s name is fetched from the database! What is happening?

Since we only retrieved the bug by primary key both the engineer and reporter
are not immediately loaded from the database but are replaced by LazyLoading
proxies. These proxies will load behind the scenes, when attempting to access
any of their un-initialized state.

The call prints:

::

    $ php show_bug.php 1
    Bug: Something does not work!
    Engineer: beberlei

.. warning::

    Lazy loading additional data can be very convenient but the additional
    queries create an overhead. If you know that certain fields will always
    (or usually) be required by the query then you will get better performance
    by explicitly retrieving them all in the first query.


Dashboard of the User
---------------------

For the next use-case we want to retrieve the dashboard view, a
list of all open bugs the user reported or was assigned to. This
will be achieved using DQL again, this time with some WHERE clauses
and usage of bound parameters:

.. code-block:: php

    <?php
    // dashboard.php <user-id>
    require_once "bootstrap.php";

    $theUserId = $argv[1];

    $dql = "SELECT b, e, r FROM Bug b JOIN b.engineer e JOIN b.reporter r ".
           "WHERE b.status = 'OPEN' AND (e.id = ?1 OR r.id = ?1) ORDER BY b.created DESC";

    $myBugs = $entityManager->createQuery($dql)
                            ->setParameter(1, $theUserId)
                            ->setMaxResults(15)
                            ->getResult();

    echo "You have created or assigned to " . count($myBugs) . " open bugs:\n\n";

    foreach ($myBugs as $bug) {
        echo $bug->getId() . " - " . $bug->getDescription()."\n";
    }

Number of Bugs
--------------

Until now we only retrieved entities or their array representation.
Doctrine also supports the retrieval of non-entities through DQL.
These values are called "scalar result values" and may even be
aggregate values using COUNT, SUM, MIN, MAX or AVG functions.

We will need this knowledge to retrieve the number of open bugs
grouped by product:

.. code-block:: php

    <?php
    // products.php
    require_once "bootstrap.php";

    $dql = "SELECT p.id, p.name, count(b.id) AS openBugs FROM Bug b ".
           "JOIN b.products p WHERE b.status = 'OPEN' GROUP BY p.id";
    $productBugs = $entityManager->createQuery($dql)->getScalarResult();

    foreach ($productBugs as $productBug) {
        echo $productBug['name']." has " . $productBug['openBugs'] . " open bugs!\n";
    }

Updating Entities
-----------------

There is a single use-case missing from the requirements, Engineers
should be able to close a bug. This looks like:

.. code-block:: php

    <?php
    // src/Bug.php

    class Bug
    {
        public function close()
        {
            $this->status = "CLOSE";
        }
    }

.. code-block:: php

    <?php
    // close_bug.php <bug-id>
    require_once "bootstrap.php";

    $theBugId = $argv[1];

    $bug = $entityManager->find("Bug", (int)$theBugId);
    $bug->close();

    $entityManager->flush();

When retrieving the Bug from the database it is inserted into the
IdentityMap inside the UnitOfWork of Doctrine. This means your Bug
with exactly this id can only exist once during the whole request
no matter how often you call ``EntityManager#find()``. It even
detects entities that are hydrated using DQL and are already
present in the Identity Map.

When flush is called the EntityManager loops over all the entities
in the identity map and performs a comparison between the values
originally retrieved from the database and those values the entity
currently has. If at least one of these properties is different the
entity is scheduled for an UPDATE against the database. Only the
changed columns are updated, which offers a pretty good performance
improvement compared to updating all the properties.

Entity Repositories
-------------------

For now we have not discussed how to separate the Doctrine query logic from your model.
In Doctrine 1 there was the concept of ``Doctrine_Table`` instances for this
separation. The similar concept in Doctrine2 is called Entity Repositories, integrating
the `repository pattern <https://martinfowler.com/eaaCatalog/repository.html>`_ at the heart of Doctrine.

Every Entity uses a default repository by default and offers a bunch of convenience
methods that you can use to query for instances of that Entity. Take for example
our Product entity. If we wanted to Query by name, we can use:

.. code-block:: php

    <?php
    $product = $entityManager->getRepository('Product')
                             ->findOneBy(array('name' => $productName));

The method ``findOneBy()`` takes an array of fields or association keys and the values to match against.

If you want to find all entities matching a condition you can use ``findBy()``, for
example querying for all closed bugs:

.. code-block:: php

    <?php
    $bugs = $entityManager->getRepository('Bug')
                          ->findBy(array('status' => 'CLOSED'));

    foreach ($bugs as $bug) {
        // do stuff
    }

Compared to DQL these query methods are falling short of functionality very fast.
Doctrine offers you a convenient way to extend the functionalities of the default ``EntityRepository``
and put all the specialized DQL query logic on it. For this you have to create a subclass
of ``Doctrine\ORM\EntityRepository``, in our case a ``BugRepository`` and group all
the previously discussed query functionality in it:

.. code-block:: php

    <?php
    // src/BugRepository.php

    use Doctrine\ORM\EntityRepository;

    class BugRepository extends EntityRepository
    {
        public function getRecentBugs($number = 30)
        {
            $dql = "SELECT b, e, r FROM Bug b JOIN b.engineer e JOIN b.reporter r ORDER BY b.created DESC";

            $query = $this->getEntityManager()->createQuery($dql);
            $query->setMaxResults($number);
            return $query->getResult();
        }

        public function getRecentBugsArray($number = 30)
        {
            $dql = "SELECT b, e, r, p FROM Bug b JOIN b.engineer e ".
                   "JOIN b.reporter r JOIN b.products p ORDER BY b.created DESC";
            $query = $this->getEntityManager()->createQuery($dql);
            $query->setMaxResults($number);
            return $query->getArrayResult();
        }

        public function getUsersBugs($userId, $number = 15)
        {
            $dql = "SELECT b, e, r FROM Bug b JOIN b.engineer e JOIN b.reporter r ".
                   "WHERE b.status = 'OPEN' AND e.id = ?1 OR r.id = ?1 ORDER BY b.created DESC";

            return $this->getEntityManager()->createQuery($dql)
                                 ->setParameter(1, $userId)
                                 ->setMaxResults($number)
                                 ->getResult();
        }

        public function getOpenBugsByProduct()
        {
            $dql = "SELECT p.id, p.name, count(b.id) AS openBugs FROM Bug b ".
                   "JOIN b.products p WHERE b.status = 'OPEN' GROUP BY p.id";
            return $this->getEntityManager()->createQuery($dql)->getScalarResult();
        }
    }

To be able to use this query logic through ``$this->getEntityManager()->getRepository('Bug')``
we have to adjust the metadata slightly.

.. configuration-block::

    .. code-block:: attribute

        <?php

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity(repositoryClass: BugRepository::class)]
        #[ORM\Table(name: 'bugs')]
        class Bug
        {
            // ...
        }

    .. code-block:: xml

        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
              xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
              xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                  https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

              <entity name="Bug" table="bugs" repository-class="BugRepository">

              </entity>
        </doctrine-mapping>

Now we can remove our query logic in all the places and instead use them through the EntityRepository.
As an example here is the code of the first use case "List of Bugs":

.. code-block:: php

    <?php
    // list_bugs_repository.php
    require_once "bootstrap.php";

    $bugs = $entityManager->getRepository('Bug')->getRecentBugs();

    foreach ($bugs as $bug) {
        echo $bug->getDescription()." - ".$bug->getCreated()->format('d.m.Y')."\n";
        echo "    Reported by: ".$bug->getReporter()->getName()."\n";
        echo "    Assigned to: ".$bug->getEngineer()->getName()."\n";
        foreach ($bug->getProducts() as $product) {
            echo "    Platform: ".$product->getName()."\n";
        }
        echo "\n";
    }

Using EntityRepositories you can avoid coupling your model with specific query logic.
You can also re-use query logic easily throughout your application.

The method ``count()`` takes an array of fields or association keys and the values to match against.
This provides you with a convenient and lightweight way to count a resultset when you don't need to
deal with it:

.. code-block:: php

    <?php
    $productCount = $entityManager->getRepository(Product::class)
                             ->count(['name' => $productName]);

Conclusion
----------

This tutorial is over here, I hope you had fun. Additional content
will be added to this tutorial incrementally, topics will include:

-   More on Association Mappings
-   Lifecycle Events triggered in the UnitOfWork
-   Ordering of Collections

Additional details on all the topics discussed here can be found in
the respective manual chapters.
