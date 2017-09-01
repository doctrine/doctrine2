Working with DateTime Instances
===============================

There are many nitty gritty details when working with PHPs DateTime instances. You have to know their inner
workings pretty well not to make mistakes with date handling. This cookbook entry holds several
interesting pieces of information on how to work with PHP DateTime instances in Doctrine 2.

DateTime changes are detected by Reference
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

When calling ``EntityManager#flush()`` Doctrine computes the changesets of all the currently managed entities
and saves the differences to the database. In case of object properties (@Column(type="datetime") or @Column(type="object"))
these comparisons are always made **BY REFERENCE**. That means the following change will **NOT** be saved into the database:

.. code-block:: php

    <?php

    use Doctrine\ORM\Annotation as ORM;

    /** @ORM\Entity */
    class Article
    {
        /** @ORM\Column(type="datetime") */
        private $updated;

        public function setUpdated()
        {
            // will NOT be saved in the database
            $this->updated->modify("now");
        }
    }

The way to go would be:

.. code-block:: php

    <?php
    class Article
    {
        public function setUpdated()
        {
            // WILL be saved in the database
            $this->updated = new \DateTime("now");
        }
    }

Default Timezone Gotcha
~~~~~~~~~~~~~~~~~~~~~~~

By default Doctrine assumes that you are working with a default timezone. Each DateTime instance that
is created by Doctrine will be assigned the timezone that is currently the default, either through
the ``date.timezone`` ini setting or by calling ``date_default_timezone_set()``.

This is very important to handle correctly if your application runs on different servers or is moved from one to another server
(with different timezone settings). You have to make sure that the timezone is the correct one
on all this systems.

Handling different Timezones with the DateTime Type
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

If you first come across the requirement to save different timezones you may be still optimistic about how
to manage this mess,
however let me crush your expectations fast. There is not a single database out there (supported by Doctrine 2)
that supports timezones correctly. Correctly here means that you can cover all the use-cases that
can come up with timezones. If you don't believe me you should read up on `Storing DateTime
in Databases <https://derickrethans.nl/storing-date-time-in-database.html>`_.

The problem is simple. Not a single database vendor saves the timezone, only the differences to UTC.
However with frequent daylight saving and political timezone changes you can have a UTC offset that moves
in different offset directions depending on the real location.

The solution for this dilemma seems simple. Don't use timezones with DateTime and Doctrine 2. However there is a workaround
that even allows correct date-time handling with timezones:

1. Don't convert DateTimes to UTC
2. Set Timezones for displaying purposes
3. Save the Timezone in the Entity for persistence.

Say we have an application for an international postal company and employees insert events regarding postal-package
around the world, in their current timezones. To determine the exact time an event occurred means to save both
the local time at the time of the booking and the timezone the event happened in.

Using the defalt DateTimeType will store the DateTime in the local time but without the timezone information. Therefore we need to store the timezone information as well and also need to provide a way to get the datetime back from the database with the correct timezone-information.

To be able to transform these values back into their real timezone you have to save the timezone in a separate field of the entity requiring timezoned datetimes:

.. code-block:: php

    <?php

    namespace Shipping;

    use Doctrine\ORM\Annotation as ORM;

    /**
     * @ORM\Entity
     */
    class Event
    {
        /** @ORM\Column(type="datetime") */
        private $created;

        /** @ORM\Column(type="string") */
        private $timezone;

        /**
         * @var bool
         */
        private $localized = false;

        public function __construct(\DateTimeInterface $createDate)
        {
            $this->localized = true;
            $this->created = $createDate;
            $this->timezone = $createDate->getTimeZone()->getName();
        }

        public function getCreated()
        {
            if (!$this->localized) {

                $class = $this->created::class;
                $this->created = new $class(
                    $this->created->format('Y-m-d H:i:s'),
                    new \DateTimeZone($this->timezone)
                );
            }
            return $this->created;
        }
    }

This snippet makes use of the previously discussed "changeset by reference only" property of
objects. That means a new DateTime will only be used during updating if the reference
changes between retrieval and flush operation. This means we can easily go and modify
the instance by setting the previous local timezone.

Using this way of handling timezones allows you also to use the database-specific ways of
doing DateTime-arithmetics with the appropriate timezones. Make sure though that the database
always has the latest version of the timezone-database when you use these features.
