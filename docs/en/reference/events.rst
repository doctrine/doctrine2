Events
======

Doctrine ORM features a lightweight event system that is part of the
Common package. Doctrine uses it to dispatch system events, mainly
:ref:`lifecycle events <reference-events-lifecycle-events>`.
You can also use it for your own custom events.

The Event System
----------------

The event system is controlled by the ``EventManager``. It is the
central point of Doctrine's event listener system. Listeners are
registered on the manager and events are dispatched through the
manager.

.. code-block:: php

    <?php
    $evm = new EventManager();

Now we can add some event listeners to the ``$evm``. Let's create a
``TestEvent`` class to play around with.

.. code-block:: php

    <?php
    class TestEvent
    {
        const preFoo = 'preFoo';
        const postFoo = 'postFoo';

        private $_evm;

        public $preFooInvoked = false;
        public $postFooInvoked = false;

        public function __construct($evm)
        {
            $evm->addEventListener(array(self::preFoo, self::postFoo), $this);
        }

        public function preFoo(EventArgs $e)
        {
            $this->preFooInvoked = true;
        }

        public function postFoo(EventArgs $e)
        {
            $this->postFooInvoked = true;
        }
    }

    // Create a new instance
    $test = new TestEvent($evm);

Events can be dispatched by using the ``dispatchEvent()`` method.

.. code-block:: php

    <?php
    $evm->dispatchEvent(TestEvent::preFoo);
    $evm->dispatchEvent(TestEvent::postFoo);

You can easily remove a listener with the ``removeEventListener()``
method.

.. code-block:: php

    <?php
    $evm->removeEventListener(array(self::preFoo, self::postFoo), $this);

The Doctrine ORM event system also has a simple concept of event
subscribers. We can define a simple ``TestEventSubscriber`` class
which implements the ``\Doctrine\Common\EventSubscriber`` interface
and implements a ``getSubscribedEvents()`` method which returns an
array of events it should be subscribed to.

.. code-block:: php

    <?php
    use Doctrine\Common\EventSubscriber;

    class TestEventSubscriber implements EventSubscriber
    {
        public $preFooInvoked = false;

        public function preFoo()
        {
            $this->preFooInvoked = true;
        }

        public function getSubscribedEvents()
        {
            return array(TestEvent::preFoo);
        }
    }

    $eventSubscriber = new TestEventSubscriber();
    $evm->addEventSubscriber($eventSubscriber);

.. note::

    The array to return in the ``getSubscribedEvents`` method is a simple array
    with the values being the event names. The subscriber must have a method
    that is named exactly like the event.

Now when you dispatch an event, any event subscribers will be
notified for that event.

.. code-block:: php

    <?php
    $evm->dispatchEvent(TestEvent::preFoo);

Now you can test the ``$eventSubscriber`` instance to see if the
``preFoo()`` method was invoked.

.. code-block:: php

    <?php
    if ($eventSubscriber->preFooInvoked) {
        echo 'pre foo invoked!';
    }

Registering Event Handlers
~~~~~~~~~~~~~~~~~~~~~~~~~~

There are two ways to set up an event handler:

* For *all events* you can create a Lifecycle Event Listener or Subscriber class and register
it by calling ``$eventManager->addEventListener()`` or ``eventManager->addEventSubscriber()``,
see
:ref:`Listening and subscribing to Lifecycle Events <listening-and-subscribing-to-lifecycle-events>`
* For *some events* (see table below), you can create a *Lifecycle Callback* method in the
entity, see :ref:`Lifecycle Callbacks <lifecycle-callbacks>`.

.. _reference-events-lifecycle-events:

Events Overview
---------------

+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| Event                                                            | Dispatched by         | Lifecycle | Passed                              |
|                                                                  |                       | Callback  | Argument                            |
+==================================================================+=======================+===========+=====================================+
| :ref:`preRemove <reference-events-pre-remove>`                   | ``$em->remove()``     | Yes       | `PreRemoveEventArgs`_               |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`postRemove <reference-events-post-update-remove-persist>`  | ``$em->flush()``      | Yes       | `PostRemoveEventArgs`_              |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`prePersist <reference-events-pre-persist>`                 | ``$em->persist()``    | Yes       | `PrePersistEventArgs`_              |
|                                                                  | on *initial* persist  |           |                                     |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`postPersist <reference-events-post-update-remove-persist>` | ``$em->flush()``      | Yes       | `PostPersistEventArgs`_             |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`preUpdate <reference-events-pre-update>`                   | ``$em->flush()``      | Yes       | `PreUpdateEventArgs`_               |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`postUpdate <reference-events-post-update-remove-persist>`  | ``$em->flush()``      | Yes       | `PostUpdateEventArgs`_              |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`postLoad <reference-events-post-load>`                     | Loading from database | Yes       | `PostLoadEventArgs`_                |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`loadClassMetadata <reference-events-load-class-metadata>`  | Loading of mapping    | No        | `LoadClassMetadataEventArgs`_       |
|                                                                  | metadata              |           |                                     |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| ``onClassMetadataNotFound``                                      | ``MappingException``  | No        | `OnClassMetadataNotFoundEventArgs`_ |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`preFlush <reference-events-pre-flush>`                     | ``$em->flush()``      | Yes       | `PreFlushEventArgs`_                |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`onFlush <reference-events-on-flush>`                       | ``$em->flush()``      | No        | `OnFlushEventArgs`_                 |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`postFlush <reference-events-post-flush>`                   | ``$em->flush()``      | No        | `PostFlushEventArgs`_               |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+
| :ref:`onClear <reference-events-on-clear>`                       | ``$em->clear()``      | No        | `OnClearEventArgs`_                 |
+------------------------------------------------------------------+-----------------------+-----------+-------------------------------------+

.. warning::

    Making changes to entities and calling ``EntityManager::flush()`` from within
    event handlers dispatched by ``EntityManager::flush()`` itself is strongly
    discouraged, and might be deprecated and eventually prevented in the future.

    The reason is that it causes re-entrance into ``UnitOfWork::commit()`` while a commit
    is currently being processed. The ``UnitOfWork`` was never designed to support this,
    and its behavior in this situation is not covered by any tests.

    This may lead to entity or collection updates being missed, applied only in parts and
    changes being lost at the end of the commit phase.

Naming convention
~~~~~~~~~~~~~~~~~

Events being used with the Doctrine ORM EventManager are best named
with camelcase and the value of the corresponding constant should
be the name of the constant itself, even with spelling. This has
several reasons:


-  It is easy to read.
-  Simplicity.
-  Each method within an EventSubscriber is named after the
   corresponding constant's value. If the constant's name and value differ
   it contradicts the intention of using the constant and makes your code
   harder to maintain.

An example for a correct notation can be found in the example
``TestEvent`` above.

.. _lifecycle-callbacks:

Lifecycle Callbacks
-------------------

Lifecycle Callbacks are defined on an entity class. They allow you to
trigger callbacks whenever an instance of that entity class experiences
a relevant lifecycle event. More than one callback can be defined for each
lifecycle event. Lifecycle Callbacks are best used for simple operations
specific to a particular entity class's lifecycle.


.. note::

    Lifecycle Callbacks are not supported for :doc:`Embeddables </tutorials/embeddables>`.

.. configuration-block::

    .. code-block:: attribute

        <?php
        use Doctrine\DBAL\Types\Types;
        use Doctrine\ORM\Event\PrePersistEventArgs;
        use Doctrine\ORM\Mapping\Entity;
        use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
        use Doctrine\ORM\Mapping\PrePersist;
        use Doctrine\ORM\Mapping\PreUpdate;

        #[Entity]
        #[HasLifecycleCallbacks]
        class User
        {
            // ...

            #[Column(type: Types::STRING, length: 255)]
            public $value;

            #[PrePersist]
            public function doStuffOnPrePersist(PrePersistEventArgs $eventArgs)
            {
                $this->createdAt = date('Y-m-d H:i:s');
            }

            #[PrePersist]
            public function doOtherStuffOnPrePersist()
            {
                $this->value = 'changed from prePersist callback!';
            }

            #[PreUpdate]
            public function doStuffOnPreUpdate(PreUpdateEventArgs $eventArgs)
            {
                $this->value = 'changed from preUpdate callback!';
            }
        }
    .. code-block:: xml

        <?xml version="1.0" encoding="UTF-8"?>

        <doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                          xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                                              https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
            <entity name="User">
                <!-- ... -->
                <lifecycle-callbacks>
                    <lifecycle-callback type="prePersist" method="doStuffOnPrePersist"/>
                    <lifecycle-callback type="prePersist" method="doOtherStuffOnPrePersist"/>
                    <lifecycle-callback type="preUpdate" method="doStuffOnPreUpdate"/>
                </lifecycle-callbacks>
            </entity>
        </doctrine-mapping>

Lifecycle Callbacks Event Argument
----------------------------------

The triggered event is also given to the lifecycle-callback.

With the additional argument you have access to the
``EntityManager`` and ``UnitOfWork`` APIs inside these callback methods.

.. code-block:: php

    <?php
    // ...

    class User
    {
        public function preUpdate(PreUpdateEventArgs $event)
        {
            if ($event->hasChangedField('username')) {
                // Do something when the username is changed.
            }
        }
    }

.. _listening-and-subscribing-to-lifecycle-events:

Listening and subscribing to Lifecycle Events
---------------------------------------------

Lifecycle event listeners are much more powerful than the simple
lifecycle callbacks that are defined on the entity classes. They
sit at a level above the entities and allow you to implement re-usable
behaviors across different entity classes.

Note that they require much more detailed knowledge about the inner
workings of the ``EntityManager`` and ``UnitOfWork`` classes. Please
read the :ref:`Implementing Event Listeners <reference-events-implementing-listeners>` section
carefully if you are trying to write your own listener.

For event subscribers, there are no surprises. They declare the
lifecycle events in their ``getSubscribedEvents`` method and provide
public methods that expect the relevant arguments.

A lifecycle event listener looks like the following:

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PreUpdateEventArgs;

    class MyEventListener
    {
        public function preUpdate(PreUpdateEventArgs $args)
        {
            $entity = $args->getObject();
            $entityManager = $args->getObjectManager();

            // perhaps you only want to act on some "Product" entity
            if ($entity instanceof Product) {
                // do something with the Product
            }
        }
    }

A lifecycle event subscriber may look like this:

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PostUpdateEventArgs;
    use Doctrine\ORM\Events;
    use Doctrine\EventSubscriber;

    class MyEventSubscriber implements EventSubscriber
    {
        public function getSubscribedEvents()
        {
            return array(
                Events::postUpdate,
            );
        }

        public function postUpdate(PostUpdateEventArgs $args)
        {
            $entity = $args->getObject();
            $entityManager = $args->getObjectManager();

            // perhaps you only want to act on some "Product" entity
            if ($entity instanceof Product) {
                // do something with the Product
            }
        }

.. note::

    Lifecycle events are triggered for all entities. It is the responsibility
    of the listeners and subscribers to check if the entity is of a type
    it wants to handle.

To register an event listener or subscriber, you have to hook it into the
EventManager that is passed to the EntityManager factory:

.. code-block:: php

    <?php
    use Doctrine\ORM\Events;

    $eventManager = new EventManager();
    $eventManager->addEventListener([Events::preUpdate], new MyEventListener());
    $eventManager->addEventSubscriber(new MyEventSubscriber());

    $entityManager = new EntityManager($connection, $config, $eventManager);

You can also retrieve the event manager instance after the
EntityManager was created:

.. code-block:: php

    <?php
    use Doctrine\ORM\Events;

    $entityManager->getEventManager()->addEventListener([Events::preUpdate], new MyEventListener());
    $entityManager->getEventManager()->addEventSubscriber(new MyEventSubscriber());

.. _reference-events-implementing-listeners:

Implementing Event Listeners
----------------------------

This section explains what is and what is not allowed during
specific lifecycle events of the ``UnitOfWork`` class. Although you get
passed the ``EntityManager`` instance in all of these events, you have
to follow these restrictions very carefully since operations in the
wrong event may produce lots of different errors, such as inconsistent
data and lost updates/persists/removes.

For the described events that are also lifecycle callback events
the restrictions apply as well, with the additional restriction
that (prior to version 2.4) you do not have access to the
``EntityManager`` or ``UnitOfWork`` APIs inside these events.

.. _reference-events-pre-persist:

prePersist
~~~~~~~~~~

There are two ways for the ``prePersist`` event to be triggered:

- One is when you call ``EntityManager::persist()``. The
  event is also called for all :ref:`cascaded associations <transitive-persistence>`.
- The other is inside the ``flush()`` method when changes to associations are computed and
  this association is marked as :ref:`cascade: persist <transitive-persistence>`. Any new entity found
  during this operation is also persisted and ``prePersist`` called
  on it. This is called :ref:`persistence by reachability <persistence-by-reachability>`.

In both cases you get passed a ``PrePersistEventArgs`` instance
which has access to the entity and the entity manager.

This event is only triggered on *initial* persist of an entity
(i.e. it does not trigger on future updates).

The following restrictions apply to ``prePersist``:

-  If you are using a PrePersist Identity Generator such as
   sequences the ID value will *NOT* be available within any
   PrePersist events.
-  Doctrine will not recognize changes made to relations in a prePersist
   event. This includes modifications to
   collections such as additions, removals or replacement.

.. _reference-events-pre-remove:

preRemove
~~~~~~~~~

The ``preRemove`` event is called on every entity immediately when it is passed
to the ``EntityManager::remove()`` method. It is cascaded for all
associations that are marked as :ref:`cascade: remove <transitive-persistence>`

It is not called for a DQL ``DELETE`` statement.

There are no restrictions to what methods can be called inside the
``preRemove`` event, except when the remove method itself was
called during a flush operation.

.. _reference-events-pre-flush:

preFlush
~~~~~~~~

``preFlush`` is called inside ``EntityManager::flush()`` before
anything else. ``EntityManager::flush()`` must not be called inside
its listeners, since it would fire the ``preFlush`` event again, which would
result in an infinite loop.

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PreFlushEventArgs;

    class PreFlushExampleListener
    {
        public function preFlush(PreFlushEventArgs $args)
        {
            // ...
        }
    }

.. _reference-events-on-flush:

onFlush
~~~~~~~

``onFlush`` is a very powerful event. It is called inside
``EntityManager::flush()`` after the changes to all the managed
entities and their associations have been computed. This means, the
``onFlush`` event has access to the sets of:

-  Entities scheduled for insert
-  Entities scheduled for update
-  Entities scheduled for removal
-  Collections scheduled for update
-  Collections scheduled for removal

To make use of the ``onFlush`` event you have to be familiar with the
internal :ref:`UnitOfWork <unit-of-work>` API, which grants you access to the previously
mentioned sets. See this example:

.. code-block:: php

    <?php
    class FlushExampleListener
    {
        public function onFlush(OnFlushEventArgs $eventArgs)
        {
            $em = $eventArgs->getObjectManager();
            $uow = $em->getUnitOfWork();

            foreach ($uow->getScheduledEntityInsertions() as $entity) {

            }

            foreach ($uow->getScheduledEntityUpdates() as $entity) {

            }

            foreach ($uow->getScheduledEntityDeletions() as $entity) {

            }

            foreach ($uow->getScheduledCollectionDeletions() as $col) {

            }

            foreach ($uow->getScheduledCollectionUpdates() as $col) {

            }
        }
    }

The following restrictions apply to the ``onFlush`` event:

-  If you create and persist a new entity in ``onFlush``, then
   calling ``EntityManager::persist()`` is not enough.
   You have to execute an additional call to
   ``$unitOfWork->computeChangeSet($classMetadata, $entity)``.
-  Changing primitive fields or associations requires you to
   explicitly trigger a re-computation of the changeset of the
   affected entity. This can be done by calling
   ``$unitOfWork->recomputeSingleEntityChangeSet($classMetadata, $entity)``.

.. _reference-events-post-flush:

postFlush
~~~~~~~~~

``postFlush`` is called at the end of ``EntityManager::flush()``.
``EntityManager::flush()`` can **NOT** be called safely inside its listeners.
This event is not a lifecycle callback.

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PostFlushEventArgs;

    class PostFlushExampleListener
    {
        public function postFlush(PostFlushEventArgs $args)
        {
            // ...
        }
    }

.. _reference-events-pre-update:

preUpdate
~~~~~~~~~

PreUpdate is called inside the ``EntityManager::flush()`` method,
right before an SQL ``UPDATE`` statement. This event is not
triggered when the computed changeset is empty, nor for a DQL
   ``UPDATE`` statement.

Changes to associations of the updated entity are never allowed in
this event, since Doctrine cannot guarantee to correctly handle
referential integrity at this point of the flush operation. This
event has a powerful feature however, it is executed with a
`PreUpdateEventArgs`_ instance, which contains a reference to the
computed change-set of this entity.

This means you have access to all the fields that have changed for
this entity with their old and new value. The following methods are
available on the ``PreUpdateEventArgs``:

-  ``getEntity()`` to get access to the actual entity.
-  ``getEntityChangeSet()`` to get a copy of the changeset array.
   Changes to this returned array do not affect updating.
-  ``hasChangedField($fieldName)`` to check if the given field name
   of the current entity changed.
-  ``getOldValue($fieldName)`` and ``getNewValue($fieldName)`` to
   access the values of a field.
-  ``setNewValue($fieldName, $value)`` to change the value of a
   field to be updated.

A simple example for this event looks like:

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PreUpdateEventArgs;

    class NeverAliceOnlyBobListener
    {
        public function preUpdate(PreUpdateEventArgs $eventArgs)
        {
            if ($eventArgs->getEntity() instanceof User) {
                if ($eventArgs->hasChangedField('name') && $eventArgs->getNewValue('name') == 'Alice') {
                    $eventArgs->setNewValue('name', 'Bob');
                    // The following will only work if `status` is already present in the computed changeset.
                    // Otherwise it will throw an InvalidArgumentException:
                    $eventArgs->setNewValue('status', 'active');
                }
            }
        }
    }

You could also use this listener to implement validation of all the
fields that have changed. This is more efficient than using a
lifecycle callback when there are expensive validations to call:

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PreUpdateEventArgs;

    class ValidCreditCardListener
    {
        public function preUpdate(PreUpdateEventArgs $eventArgs)
        {
            if ($eventArgs->getEntity() instanceof Account) {
                if ($eventArgs->hasChangedField('creditCard')) {
                    $this->validateCreditCard($eventArgs->getNewValue('creditCard'));
                }
            }
        }

        private function validateCreditCard($no)
        {
            // throw an exception to interrupt flush event. Transaction will be rolled back.
        }
    }

Restrictions for this event:

-  Changes to associations of the passed entities are not
   recognized by the flush operation anymore.
-  Changes to fields of the passed entities are not recognized by
   the flush operation anymore, use the computed change-set passed to
   the event to modify primitive field values, e.g. use
   ``$eventArgs->setNewValue($field, $value);`` as in the Alice to Bob example above.
-  Any calls to ``EntityManager::persist()`` or
   ``EntityManager::remove()``, even in combination with the ``UnitOfWork``
   API are strongly discouraged and don't work as expected outside the
   flush operation.

.. _reference-events-post-update-remove-persist:

postUpdate, postRemove, postPersist
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

These three ``post*`` events are called inside ``EntityManager::flush()``.
Changes in here are not relevant to the persistence in the
database, but you can use these events to alter non-persistable items,
like non-mapped fields, logging or even associated classes that are
not directly mapped by Doctrine.

-  The ``postUpdate`` event occurs after the database
   update operations to entity data, but before the database transaction
   has been committed. It is not called for a DQL ``UPDATE`` statement.
-  The ``postPersist`` event occurs for an entity after the entity has
   been made persistent. It will be invoked after all database insert
   operations for new entities have been performed, but before the database
   transaction has been committed. Generated primary key values will be
   available for all entities at the time this event is triggered.
-  The ``postRemove`` event occurs for an entity after the
   entity has been deleted. It will be invoked after all database
   delete operations for entity rows have been executed, but before the
   database transaction has been committed. This event is not called for
   a DQL ``DELETE`` statement.

.. note::

    At the time ``postPersist`` is called, there may still be collection and/or
    "extra" updates pending. The database may not yet be completely in
    sync with the entity states in memory, not even for the new entities. Similarly,
    also at the time ``postUpdate`` and ``postRemove`` are called, in-memory collections
    may still be in a "dirty" state or still contain removed entities.

.. warning::

    The ``postRemove`` event or any events triggered after an entity removal
    can receive an uninitializable proxy in case you have configured an entity to
    cascade remove relations. In this case, you should load yourself the proxy in
    the associated ``pre*`` event.

.. _reference-events-post-load:

postLoad
~~~~~~~~

The postLoad event occurs after the entity has been loaded into the current
``EntityManager`` from the database or after ``refresh()`` has been applied to it.

The event is deferred until the outermost hydration has finished. Loading an entity
can start further hydrations, for example for eagerly fetched associations. Entities
hydrated that way receive their ``postLoad`` event at the end of the outermost hydration
as well, when all of their associations are populated.

.. warning::

    When using ``Doctrine\ORM\AbstractQuery::toIterable()``, ``postLoad`` events are
    executed after each row has been hydrated, and therefore associations that are
    not part of that row are not guaranteed to be initialized. For example, eagerly
    fetched to-one associations are only loaded at the end of the iteration. It is not
    safe to rely on such associations in ``postLoad`` event handlers when using
    ``Doctrine\ORM\AbstractQuery::toIterable()``.

.. _reference-events-on-clear:

onClear
~~~~~~~~

The ``onClear`` event occurs when the ``EntityManager::clear()`` operation is invoked,
after all references to entities have been removed from the unit of work.
This event is not a lifecycle callback.

Entity listeners
----------------

An entity listener is a lifecycle listener class used for an entity.

- The entity listener's mapping may be applied to an entity class or mapped superclass.
- An entity listener is defined by mapping the entity class with the corresponding mapping.

.. configuration-block::

    .. code-block:: attribute

        <?php
        namespace MyProject\Entity;
        use App\EventListener\UserListener;

        #[Entity]
        #[EntityListeners([UserListener::class])]
        class User
        {
            // ....
        }
    .. code-block:: xml

        <doctrine-mapping>
            <entity name="MyProject\Entity\User">
                <entity-listeners>
                    <entity-listener class="UserListener"/>
                </entity-listeners>
                <!-- .... -->
            </entity>
        </doctrine-mapping>

.. _reference-entity-listeners:

Entity listeners class
~~~~~~~~~~~~~~~~~~~~~~

An ``Entity Listener`` could be any class, by default it should be a class with a no-arg constructor.

- Different from :ref:`reference-events-implementing-listeners` an ``Entity Listener`` is invoked just to the specified entity
- An entity listener method receives two arguments, the entity instance and the lifecycle event.
- The callback method can be defined by naming convention or specifying a method mapping.
- When a listener mapping is not given the parser will use the naming convention to look for a matching method,
  e.g. it will look for a public ``preUpdate()`` method if you are listening to the ``preUpdate`` event.
- When a listener mapping is given the parser will not look for any methods using the naming convention.

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PreUpdateEventArgs;

    class UserListener
    {
        public function preUpdate(User $user, PreUpdateEventArgs $event)
        {
            // Do something on pre update.
        }
    }

To define a specific event listener method (one that does not follow the naming convention)
you need to map the listener method using the event type mapping:

.. configuration-block::

    .. code-block:: attribute

        <?php
        use Doctrine\ORM\Event\PostLoadEventArgs;
        use Doctrine\ORM\Event\PostPersistEventArgs;
        use Doctrine\ORM\Event\PostRemoveEventArgs;
        use Doctrine\ORM\Event\PostUpdateEventArgs;
        use Doctrine\ORM\Event\PreFlushEventArgs;
        use Doctrine\ORM\Event\PrePersistEventArgs;
        use Doctrine\ORM\Event\PreRemoveEventArgs;
        use Doctrine\ORM\Event\PreUpdateEventArgs;

        class UserListener
        {
            #[PrePersist]
            public function prePersistHandler(User $user, PrePersistEventArgs $event): void { // ... }

            #[PostPersist]
            public function postPersistHandler(User $user, PostPersistEventArgs $event): void { // ... }

            #[PreUpdate]
            public function preUpdateHandler(User $user, PreUpdateEventArgs $event): void { // ... }

            #[PostUpdate]
            public function postUpdateHandler(User $user, PostUpdateEventArgs $event): void { // ... }

            #[PostRemove]
            public function postRemoveHandler(User $user, PostRemoveEventArgs $event): void { // ... }

            #[PreRemove]
            public function preRemoveHandler(User $user, PreRemoveEventArgs $event): void { // ... }

            #[PreFlush]
            public function preFlushHandler(User $user, PreFlushEventArgs $event): void { // ... }

            #[PostLoad]
            public function postLoadHandler(User $user, PostLoadEventArgs $event): void { // ... }
        }

    .. code-block:: xml

        <doctrine-mapping>
            <entity name="MyProject\Entity\User">
                 <entity-listeners>
                    <entity-listener class="UserListener">
                        <lifecycle-callback type="preFlush"      method="preFlushHandler"/>
                        <lifecycle-callback type="postLoad"      method="postLoadHandler"/>

                        <lifecycle-callback type="postPersist"   method="postPersistHandler"/>
                        <lifecycle-callback type="prePersist"    method="prePersistHandler"/>

                        <lifecycle-callback type="postUpdate"    method="postUpdateHandler"/>
                        <lifecycle-callback type="preUpdate"     method="preUpdateHandler"/>

                        <lifecycle-callback type="postRemove"    method="postRemoveHandler"/>
                        <lifecycle-callback type="preRemove"     method="preRemoveHandler"/>
                    </entity-listener>
                </entity-listeners>
                <!-- .... -->
            </entity>
        </doctrine-mapping>

.. note::

    The order of execution of multiple methods for the same event (e.g. multiple @PrePersist) is not guaranteed.


Entity listeners resolver
~~~~~~~~~~~~~~~~~~~~~~~~~
Doctrine invokes the listener resolver to get the listener instance.

- A resolver allows you register a specific entity listener instance.
- You can also implement your own resolver by extending ``Doctrine\ORM\Mapping\DefaultEntityListenerResolver`` or implementing ``Doctrine\ORM\Mapping\EntityListenerResolver``

Specifying an entity listener instance :

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\PreUpdateEventArgs;

    // User.php

    #[Entity]
    #[EntityListeners(["UserListener"])
    class User
    {
        // ....
    }

    // UserListener.php
    class UserListener
    {
        public function __construct(MyService $service)
        {
            $this->service = $service;
        }

        public function preUpdate(User $user, PreUpdateEventArgs $event)
        {
            $this->service->doSomething($user);
        }
    }

    // register a entity listener.
    $listener = $container->get('user_listener');
    $em->getConfiguration()->getEntityListenerResolver()->register($listener);

Implementing your own resolver:

.. code-block:: php

    <?php
    use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;

    class MyEntityListenerResolver extends DefaultEntityListenerResolver
    {
        public function __construct($container)
        {
            $this->container = $container;
        }

        public function resolve($className)
        {
            // resolve the service id by the given class name;
            $id = 'user_listener';

            return $this->container->get($id);
        }
    }

    // Configure the listener resolver only before instantiating the EntityManager
    $configurations->setEntityListenerResolver(new MyEntityListenerResolver);
    $entityManager = new EntityManager(.., $configurations, ..);

.. _reference-events-load-class-metadata:

Load ClassMetadata Event
------------------------

``loadClassMetadata`` - The ``loadClassMetadata`` event occurs after the
mapping metadata for a class has been loaded from a mapping source
(attributes/xml) in to a ``Doctrine\ORM\Mapping\ClassMetadata`` instance.
You can hook in to this process and manipulate the instance.
This event is not a lifecycle callback.

.. code-block:: php

    <?php
    use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

    $test = new TestEventListener();
    $evm = $em->getEventManager();
    $evm->addEventListener(Doctrine\ORM\Events::loadClassMetadata, $test);

    class TestEventListener
    {
        public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs)
        {
            $classMetadata = $eventArgs->getClassMetadata();
            $fieldMapping = array(
                'fieldName' => 'about',
                'type' => 'string',
                'length' => 255
            );
            $classMetadata->mapField($fieldMapping);
        }
    }

If not class metadata can be found, an ``onClassMetadataNotFound`` event is dispatched.
Manipulating the given event args instance
allows providing fallback metadata even when no actual metadata exists
or could be found. This event is not a lifecycle callback.

SchemaTool Events
-----------------

It is possible to access the schema metadata during schema changes that are happening in ``Doctrine\ORM\Tools\SchemaTool``.
There are two different events where you can hook in.

postGenerateSchemaTable
~~~~~~~~~~~~~~~~~~~~~~~

This event is fired for each ``Doctrine\DBAL\Schema\Table`` instance, after one was created and built up with the current class metadata
of an entity. It is possible to access to the current state of ``Doctrine\DBAL\Schema\Schema``, the current table schema
instance and class metadata.

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\ToolEvents;
    use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;

    $test = new TestEventListener();
    $evm = $em->getEventManager();
    $evm->addEventListener(ToolEvents::postGenerateSchemaTable, $test);

    class TestEventListener
    {
        public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs)
        {
            $classMetadata = $eventArgs->getClassMetadata();
            $schema = $eventArgs->getSchema();
            $table = $eventArgs->getClassTable();
        }
    }

.. note::

    Starting in ORM 3.6, the ``GenerateSchemaTableEventArgs`` class
    provides ``setSchema()`` and ``setClassTable()`` methods to replace
    the schema or table objects. These methods require ``doctrine/dbal``
    ^4.5 or higher, which provides the Schema Editor API. If called with
    an earlier DBAL version, a ``BadMethodCallException`` will be
    thrown.

postGenerateSchema
~~~~~~~~~~~~~~~~~~

This event is fired after the schema instance was successfully built and before SQL queries are generated from the
schema information of ``Doctrine\DBAL\Schema\Schema``. It allows to access the full object representation of the database schema
and the EntityManager.

.. code-block:: php

    <?php
    use Doctrine\ORM\Tools\ToolEvents;
    use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

    $test = new TestEventListener();
    $evm = $em->getEventManager();
    $evm->addEventListener(ToolEvents::postGenerateSchema, $test);

    class TestEventListener
    {
        public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs)
        {
            $schema = $eventArgs->getSchema();
            $em = $eventArgs->getEntityManager();
        }
    }

Starting in ORM 3.8, ``getOperation()`` returns ``SchemaOperation::Create``, ``SchemaOperation::Drop`` or ``SchemaOperation::Update`` for the corresponding ``SchemaTool`` operation.
When you call ``getSchemaFromMetadata()`` directly, ``getOperation()`` returns ``null``.
A listener can use this context to skip unrelated tables during entity creation or deletion.

.. note::

    Starting in ORM 3.6, the ``GenerateSchemaEventArgs`` class provides
    a ``setSchema()`` method to replace the schema object. This method
    requires ``doctrine/dbal`` ^4.5 or higher, which provides the Schema
    Editor API. If called with an earlier DBAL version, a
    ``BadMethodCallException`` will be thrown.

.. _PrePersistEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PrePersistEventArgs.php
.. _PreRemoveEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PreRemoveEventArgs.php
.. _PreUpdateEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PreUpdateEventArgs.php
.. _PostPersistEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PostPersistEventArgs.php
.. _PostRemoveEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PostRemoveEventArgs.php
.. _PostUpdateEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PostUpdateEventArgs.php
.. _PostLoadEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PostLoadEventArgs.php
.. _PreFlushEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PreFlushEventArgs.php
.. _PostFlushEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/PostFlushEventArgs.php
.. _OnFlushEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/OnFlushEventArgs.php
.. _OnClearEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/OnClearEventArgs.php
.. _LoadClassMetadataEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/LoadClassMetadataEventArgs.php
.. _OnClassMetadataNotFoundEventArgs: https://github.com/doctrine/orm/blob/HEAD/src/Event/OnClassMetadataNotFoundEventArgs.php
