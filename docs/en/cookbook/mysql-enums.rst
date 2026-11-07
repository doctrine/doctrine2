Mysql Enums
===========

The type system of Doctrine ORM consists of flyweights, which means there is only
one instance of any given type. Additionally types do not contain state. Both
assumptions make it rather complicated to work with the Enum Type of MySQL that
is used quite a lot by developers.

When using Enums with a non-tweaked Doctrine ORM application you will get
errors from the Schema-Tool commands due to the unknown database type "enum".
By default Doctrine does not map the MySQL enum type to a Doctrine type.
This is because Enums contain state (their allowed values) and Doctrine
types don't.

This cookbook entry shows several possible solutions to work with MySQL enums.
But first a word of warning. The MySQL Enum type has considerable downsides:

-  Adding new values requires to rebuild the whole table, which can take hours
   depending on the size.
-  Enums are ordered in the way the values are specified, not in their "natural" order.
-  Enums validation mechanism for allowed values is not necessarily good,
   specifying invalid values leads to an empty enum for the default MySQL error
   settings. You can easily replicate the "allow only some values" requirement
   in your Doctrine entities.

Recommended: PHP enums and the DBAL ``enum`` type
-------------------------------------------------

Since DBAL 4.2, the ``Doctrine\DBAL\Types\Types::ENUM`` type is available and
generates a native ``ENUM(...)`` column on MySQL and MariaDB (and a ``VARCHAR``
column on other platforms). Combined with :ref:`PHP backed enums
<reference-enum-mapping>`, the ORM fills in the allowed values from the enum
cases, converts between the database value and the enum instance and validates
the values for you:

.. code-block:: php

    <?php
    enum Visibility: string
    {
        case Visible = 'visible';
        case Invisible = 'invisible';
    }

    #[Entity]
    class Article
    {
        #[Column(type: Types::ENUM, enumType: Visibility::class)]
        private Visibility $status;
    }

Without a PHP enum, the allowed values can be passed with the ``values`` option:

.. code-block:: php

    <?php
    #[Entity]
    class Article
    {
        #[Column(type: Types::ENUM, options: ['values' => ['visible', 'invisible']])]
        private string $status;
    }

If you do not need a native ``ENUM`` column, mapping a PHP backed enum to a
regular ``string`` column with ``enumType`` gives you the same type-safety while
avoiding the downsides listed above.

The following solutions are only needed when you cannot use DBAL 4.2 or newer.

Solution 1: Mapping to Varchars
-------------------------------

You can map ENUMs to varchars. You can register MySQL ENUMs to map to Doctrine
varchars. This way Doctrine always resolves ENUMs to Doctrine varchars. It
will even detect this match correctly when using SchemaTool update commands.

.. code-block:: php

    <?php
    $conn = $em->getConnection();
    $conn->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

In this case you have to ensure that each varchar field that is an enum in the
database only gets passed the allowed values. You can easily enforce this in your
entities:

.. code-block:: php

    <?php
    #[Entity]
    class Article
    {
        public const STATUS_VISIBLE = 'visible';
        public const STATUS_INVISIBLE = 'invisible';

        #[Column(type: "string")]
        private $status;

        public function setStatus(string $status): void
        {
            if (!in_array($status, [self::STATUS_VISIBLE, self::STATUS_INVISIBLE], true)) {
                throw new \InvalidArgumentException("Invalid status");
            }

            $this->status = $status;
        }
    }

If you want to actively create enums through the Doctrine Schema-Tool by using
the **columnDefinition** attribute.

.. code-block:: php

    <?php
    #[Entity]
    class Article
    {
        #[Column(type: "string", columnDefinition: "ENUM('visible', 'invisible')")]
        private $status;
    }

In this case however Schema-Tool update will have a hard time not to request changes for this column on each call.

Solution 2: Defining a Type
---------------------------

You can make a stateless ENUM type by creating a type class for each unique set of ENUM values.
For example for the previous enum type:

.. code-block:: php

    <?php
    namespace MyProject\DBAL;

    use Doctrine\DBAL\Types\Type;
    use Doctrine\DBAL\Platforms\AbstractPlatform;

    class EnumVisibilityType extends Type
    {
        private const ENUM_VISIBILITY = 'enumvisibility';
        private const STATUS_VISIBLE = 'visible';
        private const STATUS_INVISIBLE = 'invisible';

        public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
        {
            return "ENUM('visible', 'invisible')";
        }

        public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
        {
            return $value;
        }

        public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
        {
            if ($value === null) {
                return null;
            }

            if (!in_array($value, [self::STATUS_VISIBLE, self::STATUS_INVISIBLE], true)) {
                throw new \InvalidArgumentException("Invalid status");
            }

            return $value;
        }

        public function getName(): string
        {
            return self::ENUM_VISIBILITY;
        }
    }

You can register this type with ``Type::addType('enumvisibility', 'MyProject\DBAL\EnumVisibilityType');``.
Then in your entity you can just use this type:

.. code-block:: php

    <?php
    #[Entity]
    class Article
    {
        #[Column(type: "enumvisibility")]
        private $status;
    }

You can generalize this approach easily to create a base class for enums:

.. code-block:: php

    <?php
    namespace MyProject\DBAL;

    use Doctrine\DBAL\Types\Type;
    use Doctrine\DBAL\Platforms\AbstractPlatform;

    abstract class EnumType extends Type
    {
        protected $name;
        protected $values = [];

        public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
        {
            $values = array_map(fn($val) => "'".$val."'", $this->values);

            return "ENUM(".implode(", ", $values).")";
        }

        public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
        {
            return $value;
        }

        public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
        {
            if ($value === null) {
                return null;
            }

            if (!in_array($value, $this->values, true)) {
                throw new \InvalidArgumentException("Invalid '".$this->name."' value.");
            }

            return $value;
        }

        public function getName(): string
        {
            return $this->name;
        }
    }

The ``null`` checks allow the type to be used for nullable columns, since
Doctrine passes ``null`` to the type when the field has no value.

With this base class you can define an enum as easily as:

.. code-block:: php

    <?php
    namespace MyProject\DBAL;

    class EnumVisibilityType extends EnumType
    {
        protected $name = 'enumvisibility';
        protected $values = ['visible', 'invisible'];
    }
