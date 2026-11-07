Filters
=======

Doctrine ORM features a filter system that allows the developer to add SQL to
the conditional clauses of queries, regardless the place where the SQL is
generated (e.g. from a DQL query, or by loading associated entities).

The filter functionality works on SQL level. Whether a SQL query is generated
in a Persister, during lazy loading, in extra lazy collections or from DQL.
Each time the system iterates over all the enabled filters, adding a new SQL
part as a filter returns.

By adding SQL to the conditional clauses of queries, the filter system filters
out rows belonging to the entities at the level of the SQL result set. This
means that the filtered entities are never hydrated (which can be expensive).


Example filter class
--------------------
Throughout this document the example ``MyLocaleFilter`` class will be used to
illustrate how the filter feature works. A filter class must extend the base
``Doctrine\ORM\Query\Filter\SQLFilter`` class and implement the ``addFilterConstraint``
method. The method receives the ``ClassMetadata`` of the filtered entity and the
table alias of the SQL table of the entity.

.. note::

    In the case of joined or single table inheritance, you always get passed the ClassMetadata of the
    inheritance root. This is necessary to avoid edge cases that would break the SQL when applying the filters.

For the filter to correctly function, the following rules must be followed. Failure to do so will lead to unexpected results from the query cache.
  1. Parameters for the query should be set on the filter object by ``SQLFilter#setParameter()`` before the filter is used by the ORM ( i.e. do not set parameters inside ``SQLFilter#addFilterConstraint()`` function ).
  2. The filter must be deterministic. Don't change the values based on external inputs.

The ``SQLFilter#getParameter()`` function takes care of the proper quoting of parameters.

.. code-block:: php

    <?php
    namespace Example;
    use Doctrine\ORM\Mapping\ClassMetadata;
    use Doctrine\ORM\Query\Filter\SQLFilter;

    class MyLocaleFilter extends SQLFilter
    {
        public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
        {
            // Only filter entities that implement the LocaleAware interface and map a "locale" field
            if (! $targetEntity->reflClass->implementsInterface(LocaleAware::class) || ! $targetEntity->hasField('locale')) {
                return '';
            }

            // Resolve the column name from the metadata instead of assuming it,
            // getParameter() applies quoting automatically
            return $targetTableAlias . '.' . $targetEntity->getColumnName('locale') . ' = ' . $this->getParameter('locale');
        }
    }

If the parameter is an array and should be quoted as a list of values for an IN query
this is possible with the alternative ``SQLFilter#setParameterList()`` and
``SQLFilter#getParameterList()`` functions.

Configuration
-------------
Filter classes are added to the configuration as following:

.. code-block:: php

    <?php
    $config->addFilter("locale", "\Doctrine\Tests\ORM\Functional\MyLocaleFilter");


The ``Configuration#addFilter()`` method takes a name for the filter and the name of the
class responsible for the actual filtering.


Disabling/Enabling Filters and Setting Parameters
---------------------------------------------------
Filters can be disabled and enabled via the ``FilterCollection`` which is
stored in the ``EntityManager``. The ``FilterCollection#enable($name)`` method
will retrieve the filter object. You can set the filter parameters on that
object.

.. code-block:: php

    <?php
    $filter = $em->getFilters()->enable("locale");
    $filter->setParameter('locale', 'en');

    // Disable it
    $filter = $em->getFilters()->disable("locale");

.. warning::
    Disabling and enabling filters has no effect on managed entities. If you
    want to refresh or reload an object after having modified a filter or the
    FilterCollection, then you should clear the EntityManager and re-fetch your
    entities, having the new rules for filtering applied.


Suspending/Restoring Filters
----------------------------
When a filter is disabled, the instance is fully deleted and all the filter
parameters previously set are lost. Then, if you enable it again, a new filter
is created without the previous filter parameters. If you want to keep a filter
(in order to use it later) but temporary disable it, you'll need to use the
``FilterCollection#suspend($name)`` and ``FilterCollection#restore($name)``
methods instead.

.. code-block:: php

    <?php
    $filter = $em->getFilters()->enable("locale");
    $filter->setParameter('locale', 'en');

    // Temporary suspend the filter
    $filter = $em->getFilters()->suspend("locale");

    // Do things

    // Then restore it, the locale parameter will still be set
    $filter = $em->getFilters()->restore("locale");

.. warning::
    If you enable a previously disabled filter, doctrine will create a new
    one without keeping any of the previously parameter set with
    ``SQLFilter#setParameter()`` or ``SQLFilter#getParameterList()``. If you
    want to restore the previously disabled filter instead, you must use the
    ``FilterCollection#restore($name)`` method.
