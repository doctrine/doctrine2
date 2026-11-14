Partial Objects
===============

A partial object is an object whose state is not fully initialized
after being reconstituted from the database and that is
disconnected from the rest of its data. The following section will
describe why partial objects are problematic and what the approach
of Doctrine to this problem is.

.. note::

    The partial object problem in general does not apply to
    methods or queries where you do not retrieve the query result as
    objects. Examples are: ``Query#getArrayResult()``,
    ``Query#getScalarResult()``, ``Query#getSingleScalarResult()``,
    etc.

.. warning::

    On PHP versions before 8.4 (or when native lazy objects are not
    enabled), use of partial objects is tricky. Fields that are not
    retrieved from the database will not be updated by the UnitOfWork
    even if they get changed in your objects. You can only promote a
    partial object to a fully-loaded object by calling
    ``EntityManager#refresh()`` or a DQL query with the refresh flag.

    On PHP 8.4 with native lazy objects enabled, unloaded fields are
    transparently fetched from the database on first access, so this
    limitation no longer applies. See `Transparent lazy loading on PHP 8.4`_
    below.


What is the problem?
--------------------

In short, partial objects are problematic because they are usually
objects with broken invariants. As such, code that uses these
partial objects tends to be very fragile and either needs to "know"
which fields or methods can be safely accessed or add checks around
every field access or method invocation. The same holds true for
the internals, i.e. the method implementations, of such objects.
You usually simply assume the state you need in the method is
available, after all you properly constructed this object before
you pushed it into the database, right? These blind assumptions can
quickly lead to null reference errors when working with such
partial objects.

It gets worse with the scenario of an optional association (0..1 to
1). When the associated field is NULL, you don't know whether this
object does not have an associated object or whether it was simply
not loaded when the owning object was loaded from the database.

These are reasons why many ORMs do not allow partial objects at all
and instead you always have to load an object with all its fields
(associations being proxied). One secure way to allow partial
objects is if the programming language/platform allows the ORM tool
to hook deeply into the object and instrument it in such a way that
individual fields (not only associations) can be loaded lazily on
first access. This is possible in Java, for example, through
bytecode instrumentation. In PHP this became possible in PHP 8.4
through native lazy ghost objects. See `Transparent lazy loading on PHP 8.4`_
for details.

Doctrine, by default, does not allow partial objects. That means,
any query that only selects partial object data and wants to
retrieve the result as objects (i.e. ``Query#getResult()``) will
raise an exception telling you that partial objects are dangerous.
If you want to force a query to return you partial objects,
possibly as a performance tweak, you can use the ``partial``
keyword as follows:

.. code-block:: php

    <?php
    $q = $em->createQuery("select partial u.{id,name} from MyApp\Domain\User u");

You can also get a partial reference instead of a proxy reference by
calling:

.. code-block:: php

    <?php
    $reference = $em->getPartialReference('MyApp\Domain\User', 1);

Partial references are objects with only the identifiers set as they
are passed to the second argument of the ``getPartialReference()`` method.
All other fields are null.

When should I force partial objects?
------------------------------------

Mainly for optimization purposes, but be careful of premature
optimization as partial objects lead to potentially more fragile
code.

Transparent lazy loading on PHP 8.4
------------------------------------

Partial objects returned by DQL queries behave as **lazy ghosts**: only
the fields listed in the ``PARTIAL`` clause are loaded eagerly from the
database. All other scalar fields remain uninitialized in the ghost
object.

On first access to any uninitialized field, Doctrine automatically issues
a ``SELECT`` to load the full entity from the database, transparently
initializing the ghost. From that point the object is indistinguishable
from one loaded by a normal (non-partial) query.

.. code-block:: php

    <?php
    // Only id and name are fetched initially.
    $q = $em->createQuery("SELECT PARTIAL u.{id, name} FROM MyApp\Domain\User u");
    $user = $q->getSingleResult();

    // Accessing a loaded field — no additional query fired.
    echo $user->name;

    // Accessing a non-loaded field — Doctrine fetches the full entity now.
    echo $user->email; // triggers SELECT, then returns the value

This means the usual caveats about partial objects (broken invariants,
null-vs-not-loaded ambiguity) no longer apply when native lazy objects
are enabled: the object always presents complete, correct state to the
caller, and the initial partial load serves purely as a performance
optimisation that defers the cost of fetching remaining columns until
(and unless) they are actually needed.

Mutating a loaded field **before** the lazy initializer fires is safe.
The in-memory value is preserved and not overwritten when the ghost is
eventually initialized:

.. code-block:: php

    <?php
    $user = $em->createQuery("SELECT PARTIAL u.{id, name} FROM MyApp\Domain\User u")
               ->getSingleResult();

    // 'name' was loaded; change it before accessing any unloaded field.
    $user->name = 'Bob';

    // Accessing 'email' triggers the lazy load, but 'name' is NOT reset
    // to the database value — the in-memory change is preserved.
    echo $user->email;
    echo $user->name; // 'Bob'
