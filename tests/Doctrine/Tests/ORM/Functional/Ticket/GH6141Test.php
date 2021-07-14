<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
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

use function in_array;

class GH6141Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Type::addType(GH6141PeopleType::NAME, GH6141PeopleType::class);

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(GH6141Person::class),
                $this->_em->getClassMetadata(GH6141Boss::class),
                $this->_em->getClassMetadata(GH6141Employee::class),
            ]
        );
    }

    /**
     * The intent of this test is to ensure that the ORM is capable
     * of using objects as discriminators (which makes things a bit
     * more dynamic as you can see on the mapping of `GH6141Person`)
     *
     * @group GH-6141
     */
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
            $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY)['discr']
        );

        $query->setParameter('name', 'Bob');
        self::assertEquals($employee, $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT));
        self::assertEquals(
            GH6141People::get(GH6141People::EMPLOYEE),
            $query->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY)['discr']
        );
    }
}

class GH6141PeopleType extends StringType
{
    public const NAME = 'gh6141people';

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        if (! $value instanceof GH6141People) {
            $value = GH6141People::get($value);
        }

        return (string) $value;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return GH6141People::get($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }
}

class GH6141People
{
    public const BOSS     = 'boss';
    public const EMPLOYEE = 'employee';

    /** @var string */
    private $value;

    /**
     * @throws InvalidArgumentException
     */
    public static function get(string $value): GH6141People
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException();
        }

        return new self($value);
    }

    private static function isValid(string $valid): bool
    {
        return in_array($valid, [self::BOSS, self::EMPLOYEE]);
    }

    private function __construct(string $value)
    {
        $this->value = $value;
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

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="gh6141people")
 * @DiscriminatorMap({
 *      Doctrine\Tests\ORM\Functional\Ticket\GH6141People::BOSS     = GH6141Boss::class,
 *      Doctrine\Tests\ORM\Functional\Ticket\GH6141People::EMPLOYEE = GH6141Employee::class
 * })
 */
abstract class GH6141Person
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

/** @Entity */
class GH6141Boss extends GH6141Person
{
}

/** @Entity */
class GH6141Employee extends GH6141Person
{
}
