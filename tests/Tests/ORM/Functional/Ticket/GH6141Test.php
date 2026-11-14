<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\Tests\OrmFunctionalTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Stringable;

use function in_array;

class GH6141Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH6141PeopleType::NAME, GH6141PeopleType::class);

        $this->createSchemaForModels(
            GH6141Person::class,
            GH6141Boss::class,
            GH6141Employee::class,
        );
    }

    /**
     * The intent of this test is to ensure that the ORM is capable
     * of using objects as discriminators (which makes things a bit
     * more dynamic as you can see on the mapping of `GH6141Person`)
     */
    #[Group('GH-6141')]
    public function testEnumDiscriminatorsShouldBeConvertedToString(): void
    {
        $boss     = new GH6141Boss('John');
        $employee = new GH6141Employee('Bob');

        $this->_em->persist($boss);
        $this->_em->persist($employee);
        $this->_em->flush();
        $this->_em->clear();

        // Using DQL here to make sure that we'll use ObjectHydrator instead of SimpleObjectHydrator
        $query = $this->_em->createQueryBuilder()
            ->select('person')
            ->from(GH6141Person::class, 'person')
            ->where('person.name = :name')
            ->setMaxResults(1)
            ->getQuery();

        $query->setParameter('name', 'John');
        self::assertEquals($boss, $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT));
        self::assertEquals(
            GH6141People::get(GH6141People::BOSS),
            $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY)['discr'],
        );

        $query->setParameter('name', 'Bob');
        self::assertEquals($employee, $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT));
        self::assertEquals(
            GH6141People::get(GH6141People::EMPLOYEE),
            $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY)['discr'],
        );
    }
}

class GH6141PeopleType extends StringType
{
    final public const string NAME = 'gh6141people';

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        if (! $value instanceof GH6141People) {
            $value = GH6141People::get($value);
        }

        return (string) $value;
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): GH6141People
    {
        return GH6141People::get($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

class GH6141People implements Stringable
{
    final public const string BOSS     = 'boss';
    final public const string EMPLOYEE = 'employee';

    /** @throws InvalidArgumentException */
    public static function get(string $value): GH6141People
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException();
        }

        return new self($value);
    }

    private static function isValid(string $valid): bool
    {
        return in_array($valid, [self::BOSS, self::EMPLOYEE], true);
    }

    private function __construct(private string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

#[Entity]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'gh6141people')]
#[DiscriminatorMap([GH6141People::BOSS => GH6141Boss::class, GH6141People::EMPLOYEE => GH6141Employee::class])]
abstract class GH6141Person
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;

    public function __construct(
        #[Column(type: 'string', length: 255)]
        public string $name,
    ) {
    }
}

#[Entity]
class GH6141Boss extends GH6141Person
{
}

#[Entity]
class GH6141Employee extends GH6141Person
{
}
