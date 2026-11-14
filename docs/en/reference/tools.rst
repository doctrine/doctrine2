Tools
=====

Doctrine Console
----------------

The Doctrine Console is a Command Line Interface tool for simplifying common
administration tasks during the development of a project that uses ORM.

For the following examples, we will set up the CLI as ``bin/doctrine``.

Setting Up the Console
~~~~~~~~~~~~~~~~~~~~~~

Whenever the ``doctrine`` command line tool is invoked, it can
access all Commands that were registered by a developer. There is no
auto-detection mechanism at work. The Doctrine binary
already registers all the commands that currently ship with
Doctrine DBAL and ORM. If you want to use additional commands you
have to register them yourself.

All the commands of the Doctrine Console require access to the
``EntityManager``. You have to inject it into the console application.

Here is an example of a project-specific ``bin/doctrine`` binary.

.. code-block:: php

    #!/usr/bin/env php
    <?php

    use Doctrine\ORM\Tools\Console\ConsoleRunner;
    use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;

    // replace with path to your own project bootstrap file
    require_once 'bootstrap.php';

    // replace with mechanism to retrieve EntityManager in your app
    $entityManager = GetEntityManager();

    $commands = [
        // If you want to add your own custom console commands,
        // you can do so here.
    ];

    ConsoleRunner::run(
        new SingleManagerProvider($entityManager),
        $commands
    );

.. note::

    You have to adjust this snippet for your specific application or framework
    and use their facilities to access the Doctrine EntityManager and
    Connection Resources.

Display Help Information
~~~~~~~~~~~~~~~~~~~~~~~~

Type ``php bin/doctrine`` on the command line and you should see an
overview of the available commands or use the ``--help`` flag to get
information on the available commands. If you want to know more
about the use of generate entities for example, you can call:

::

    $> php bin/doctrine orm:generate-entities --help

Command Overview
~~~~~~~~~~~~~~~~

The following Commands are currently available:


-  ``help`` Displays help for a command (?)
-  ``list`` Lists commands
-  ``dbal:import`` Import SQL file(s) directly to Database.
-  ``dbal:run-sql`` Executes arbitrary SQL directly from the
   command line.
-  ``orm:clear-cache:metadata`` Clear all metadata cache of the
   various cache drivers.
-  ``orm:clear-cache:query`` Clear all query cache of the various
   cache drivers.
-  ``orm:clear-cache:result`` Clear result cache of the various
   cache drivers.
-  ``orm:run-dql`` Executes arbitrary DQL directly from the command
   line.
-  ``orm:schema-tool:create`` Processes the schema and either
   create it directly on EntityManager Storage Connection or generate
   the SQL output.
-  ``orm:schema-tool:drop`` Processes the schema and either drop
   the database schema of EntityManager Storage Connection or generate
   the SQL output.
-  ``orm:schema-tool:update`` Processes the schema and either
   update the database schema of EntityManager Storage Connection or
   generate the SQL output.
-  ``orm:debug:event-manager`` Lists event listeners for an entity
   manager, optionally filtered by event name.
-  ``orm:debug:entity-listeners`` Lists entity listeners for a given
   entity, optionally filtered by event name.

The following alias is defined:


-  ``orm:generate:proxies`` is alias for ``orm:generate-proxies``.

.. note::

    Console also supports auto completion, for example, instead of
    ``orm:clear-cache:query`` you can use just ``o:c:q``.

Database Schema Generation
--------------------------

.. note::

    SchemaTool can do harm to your database. It will drop or alter
    tables, indexes, sequences and such. Please use this tool with
    caution in development and not on a production server. It is meant
    for helping you develop your Database Schema, but NOT with
    migrating schema from A to B in production. A safe approach would
    be generating the SQL on development server and saving it into SQL
    Migration files that are executed manually on the production
    server.

    SchemaTool assumes your Doctrine Project uses the given database on
    its own. Update and Drop commands will mess with other tables if
    they are not related to the current project that is using Doctrine.
    Please be careful!


To generate your database schema from your Doctrine mapping files
you can use the ``SchemaTool`` class or the ``schema-tool`` Console
Command.

When using the SchemaTool class directly, create your schema using
the ``createSchema()`` method. First create an instance of the
``SchemaTool`` and pass it an instance of the ``EntityManager``
that you want to use to create the schema. This method receives an
array of ``ClassMetadata`` instances.

.. code-block:: php

    <?php
    $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
    $classes = array(
      $em->getClassMetadata('Entities\User'),
      $em->getClassMetadata('Entities\Profile')
    );
    $tool->createSchema($classes);

To drop the schema you can use the ``dropSchema()`` method.

.. code-block:: php

    <?php
    $tool->dropSchema($classes);

This drops all the tables that are currently used by your metadata
model. When you are changing your metadata a lot during development
you might want to drop the complete database instead of only the
tables of the current model to clean up with orphaned tables.

.. code-block:: php

    <?php
    $tool->dropDatabase();

You can also use database introspection to update your schema
easily with the ``updateSchema()`` method. It will compare your
existing database schema to the passed array of ``ClassMetadata``
instances.

.. code-block:: php

    <?php
    $tool->updateSchema($classes);

If you want to use this functionality from the command line you can
use the ``schema-tool`` command.

To create the schema use the ``create`` command:

.. code-block:: php

    $ php bin/doctrine orm:schema-tool:create

To drop the schema use the ``drop`` command:

.. code-block:: php

    $ php bin/doctrine orm:schema-tool:drop

If you want to drop and then recreate the schema then use both
options:

.. code-block:: php

    $ php bin/doctrine orm:schema-tool:drop
    $ php bin/doctrine orm:schema-tool:create

As you would think, if you want to update your schema use the
``update`` command:

.. code-block:: php

    $ php bin/doctrine orm:schema-tool:update

All of the above commands also accept a ``--dump-sql`` option that
will output the SQL for the ran operation.

.. code-block:: php

    $ php bin/doctrine orm:schema-tool:create --dump-sql

Runtime vs Development Mapping Validation
-----------------------------------------

For performance reasons Doctrine ORM has to skip some of the
necessary validation of metadata mappings. You have to execute
this validation in your development workflow to verify the
associations are correctly defined.

You can either use the Doctrine Command Line Tool:

.. code-block:: php

    doctrine orm:validate-schema

If the validation fails, you can change the verbosity level to
check the detected errors:

    doctrine orm:validate-schema -v

Or you can trigger the validation manually:

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\SchemaValidator;

    $validator = new SchemaValidator($entityManager);
    $errors = $validator->validateMapping();

    if (count($errors) > 0) {
        // Lots of errors!
        echo implode("\n\n", $errors);
    }

If the mapping is invalid the errors array contains a positive
number of elements with error messages.

.. warning::

    One mapping option that is not validated is the use of the referenced column name.
    It has to point to the equivalent primary key otherwise Doctrine will not work.

.. note::

    One common error is to use a backlash in front of the
    fully-qualified class-name. Whenever a FQCN is represented inside a
    string (such as in your mapping definitions) you have to drop the
    prefix backslash. PHP does this with ``get_class()`` or Reflection
    methods for backwards compatibility reasons.


Adding own commands
-------------------

You can also add your own commands on-top of the Doctrine supported
tools if you are using a manually built console script.

To include a new command on Doctrine Console, you need to do modify the
``doctrine.php`` file a little:

.. code-block:: php

    <?php
    // doctrine.php
    use Symfony\Component\Console\Application;

    // as before ...

    // replace the ConsoleRunner::run() statement with:
    $cli = new Application('Doctrine Command Line Interface', \Doctrine\ORM\Version::VERSION);
    $cli->setCatchExceptions(true);
    $cli->setHelperSet($helperSet);

    // Register All Doctrine Commands
    ConsoleRunner::addCommands($cli);

    // Register your own command
    $cli->addCommand(new \MyProject\Tools\Console\Commands\MyCustomCommand);

    // Runs console application
    $cli->run();

Additionally, include multiple commands (and overriding previously
defined ones) is possible through the command:

.. code-block:: php

    <?php

    $cli->addCommands(array(
        new \MyProject\Tools\Console\Commands\MyCustomCommand(),
        new \MyProject\Tools\Console\Commands\SomethingCommand(),
        new \MyProject\Tools\Console\Commands\AnotherCommand(),
        new \MyProject\Tools\Console\Commands\OneMoreCommand(),
    ));


Re-use console application
--------------------------

You are also able to retrieve and re-use the default console application.
Just call ``ConsoleRunner::createApplication(...)`` with an appropriate
HelperSet, like it is described in the configuration section.

.. code-block:: php

    <?php

    // Retrieve default console application
    $cli = ConsoleRunner::createApplication($helperSet);

    // Runs console application
    $cli->run();
