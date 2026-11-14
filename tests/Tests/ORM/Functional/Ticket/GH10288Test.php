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
use Doctrine\Tests\Models\GH10288\GH10288People;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * This test makes sure that Discriminator columns can use both custom types using PHP enums as well as
 * enumType definition of enums.
 */
class GH10288Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registerDbalType(GH10288PeopleType::NAME, GH10288PeopleType::class);

        $this->createSchemaForModels(
            GH10288PersonWithEnumType::class,
            GH10288BossWithEnumType::class,
            GH10288EmployeeWithEnumType::class,
            GH10288PersonCustomEnumType::class,
            GH10288BossCustomEnumType::class,
            GH10288EmployeeCustomEnumType::class,
        );
    }

    /** @param class-string $personType */
    private function performEnumDiscriminatorTest(
        GH10288BossWithEnumType|GH10288BossCustomEnumType $boss,
        GH10288EmployeeWithEnumType|GH10288EmployeeCustomEnumType $employee,
        string $personType,
    ): void {
        $boss->bossId = 1;

        $this->_em->persist($boss);
        $this->_em->persist($employee);
        $this->_em->flush();
        $bossId = $boss->id;
        $this->_em->clear();

        // Using DQL here to make sure that we'll use ObjectHydrator instead of SimpleObjectHydrator
        $query = $this->_em->createQueryBuilder()
            ->select('person')
            ->from($personType, 'person')
            ->where('person.name = :name')
            ->setMaxResults(1)
            ->getQuery();

        $query->setParameter('name', 'John');

        self::assertEquals($boss, $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT));
        self::assertEquals(
            GH10288People::BOSS,
            $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY)['discr'],
        );

        $query->setParameter('name', 'Bob');
        self::assertEquals($employee, $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT));
        self::assertEquals(
            GH10288People::EMPLOYEE,
            $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY)['discr'],
        );

        $this->_em->clear();

        // test SimpleObjectHydrator
        $bossFetched = $this->_em->find($personType, $bossId);
        self::assertEquals($boss, $bossFetched);
    }

    #[Group('GH10288')]
    public function testEnumDiscriminatorWithEnumType(): void
    {
        $boss     = new GH10288BossWithEnumType('John');
        $employee = new GH10288EmployeeWithEnumType('Bob');

        $this->performEnumDiscriminatorTest($boss, $employee, GH10288PersonWithEnumType::class);
    }

    #[Group('GH10288')]
    public function testEnumDiscriminatorWithCustomEnumType(): void
    {
        $boss     = new GH10288BossCustomEnumType('John');
        $employee = new GH10288EmployeeCustomEnumType('Bob');

        $this->performEnumDiscriminatorTest($boss, $employee, GH10288PersonCustomEnumType::class);
    }
}

class GH10288PeopleType extends StringType
{
    final public const string NAME = 'GH10288PeopleType';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        if (! $value instanceof GH10288People) {
            $value = GH10288People::from($value);
        }

        return $value->value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): GH10288People
    {
        return GH10288People::from($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

#[Entity]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', enumType: GH10288People::class)]
#[DiscriminatorMap([
    'boss'     => GH10288BossWithEnumType::class,
    'employee' => GH10288EmployeeWithEnumType::class,
])]
abstract class GH10288PersonWithEnumType
{
    #[Id]
    #[Column]
    #[GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    public function __construct(
        #[Column(length: 255)]
        public string $name,
    ) {
    }
}

#[Entity]
class GH10288BossWithEnumType extends GH10288PersonWithEnumType
{
    /** @var int */
    #[Column(type: 'integer')]
    public $bossId;
}

#[Entity]
class GH10288EmployeeWithEnumType extends GH10288PersonWithEnumType
{
}

#[Entity]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'discr', type: 'GH10288PeopleType')]
#[DiscriminatorMap([
    'boss'     => GH10288BossCustomEnumType::class,
    'employee' => GH10288EmployeeCustomEnumType::class,
])]
abstract class GH10288PersonCustomEnumType
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;

    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

#[Entity]
class GH10288BossCustomEnumType extends GH10288PersonCustomEnumType
{
    /** @var int */
    #[Column(type: 'integer')]
    public $bossId;
}

#[Entity]
class GH10288EmployeeCustomEnumType extends GH10288PersonCustomEnumType
{
}
