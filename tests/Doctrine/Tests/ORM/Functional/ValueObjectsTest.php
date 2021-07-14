<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use Doctrine\Common\Reflection\RuntimePublicReflectionProperty as CommonRuntimePublicReflectionProperty;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\ReflectionEmbeddedProperty;
use Doctrine\ORM\Query\QueryException;
use Doctrine\Persistence\Reflection\RuntimePublicReflectionProperty;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function class_exists;
use function sprintf;

/**
 * @group DDC-93
 */
class ValueObjectsTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC93Person::class),
                    $this->_em->getClassMetadata(DDC93Address::class),
                    $this->_em->getClassMetadata(DDC93Vehicle::class),
                    $this->_em->getClassMetadata(DDC93Car::class),
                    $this->_em->getClassMetadata(DDC3027Animal::class),
                    $this->_em->getClassMetadata(DDC3027Dog::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testMetadataHasReflectionEmbeddablesAccessible(): void
    {
        $classMetadata = $this->_em->getClassMetadata(DDC93Person::class);

        if (class_exists(CommonRuntimePublicReflectionProperty::class)) {
            $this->assertInstanceOf(
                CommonRuntimePublicReflectionProperty::class,
                $classMetadata->getReflectionProperty('address')
            );
        } else {
            $this->assertInstanceOf(
                RuntimePublicReflectionProperty::class,
                $classMetadata->getReflectionProperty('address')
            );
        }

        $this->assertInstanceOf(ReflectionEmbeddedProperty::class, $classMetadata->getReflectionProperty('address.street'));
    }

    public function testCRUD(): void
    {
        $person                   = new DDC93Person();
        $person->name             = 'Tara';
        $person->address          = new DDC93Address();
        $person->address->street  = 'United States of Tara Street';
        $person->address->zip     = '12345';
        $person->address->city    = 'funkytown';
        $person->address->country = new DDC93Country('Germany');

        // 1. check saving value objects works
        $this->_em->persist($person);
        $this->_em->flush();

        $this->_em->clear();

        // 2. check loading value objects works
        $person = $this->_em->find(DDC93Person::class, $person->id);

        $this->assertInstanceOf(DDC93Address::class, $person->address);
        $this->assertEquals('United States of Tara Street', $person->address->street);
        $this->assertEquals('12345', $person->address->zip);
        $this->assertEquals('funkytown', $person->address->city);
        $this->assertInstanceOf(DDC93Country::class, $person->address->country);
        $this->assertEquals('Germany', $person->address->country->name);

        // 3. check changing value objects works
        $person->address->street        = 'Street';
        $person->address->zip           = '54321';
        $person->address->city          = 'another town';
        $person->address->country->name = 'United States of America';
        $this->_em->flush();

        $this->_em->clear();

        $person = $this->_em->find(DDC93Person::class, $person->id);

        $this->assertEquals('Street', $person->address->street);
        $this->assertEquals('54321', $person->address->zip);
        $this->assertEquals('another town', $person->address->city);
        $this->assertEquals('United States of America', $person->address->country->name);

        // 4. check deleting works
        $personId = $person->id;

        $this->_em->remove($person);
        $this->_em->flush();

        $this->assertNull($this->_em->find(DDC93Person::class, $personId));
    }

    public function testLoadDql(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $person                   = new DDC93Person();
            $person->name             = 'Donkey Kong' . $i;
            $person->address          = new DDC93Address();
            $person->address->street  = 'Tree';
            $person->address->zip     = '12345';
            $person->address->city    = 'funkytown';
            $person->address->country = new DDC93Country('United States of America');

            $this->_em->persist($person);
        }

        $this->_em->flush();
        $this->_em->clear();

        $dql     = 'SELECT p FROM ' . __NAMESPACE__ . '\DDC93Person p';
        $persons = $this->_em->createQuery($dql)->getResult();

        $this->assertCount(3, $persons);
        foreach ($persons as $person) {
            $this->assertInstanceOf(DDC93Address::class, $person->address);
            $this->assertEquals('Tree', $person->address->street);
            $this->assertEquals('12345', $person->address->zip);
            $this->assertEquals('funkytown', $person->address->city);
            $this->assertInstanceOf(DDC93Country::class, $person->address->country);
            $this->assertEquals('United States of America', $person->address->country->name);
        }

        $dql     = 'SELECT p FROM ' . __NAMESPACE__ . '\DDC93Person p';
        $persons = $this->_em->createQuery($dql)->getArrayResult();

        foreach ($persons as $person) {
            $this->assertEquals('Tree', $person['address.street']);
            $this->assertEquals('12345', $person['address.zip']);
            $this->assertEquals('funkytown', $person['address.city']);
            $this->assertEquals('United States of America', $person['address.country.name']);
        }
    }

    /**
     * @group dql
     */
    public function testDqlOnEmbeddedObjectsField(): void
    {
        if ($this->isSecondLevelCacheEnabled) {
            $this->markTestSkipped('SLC does not work with UPDATE/DELETE queries through EM.');
        }

        $person = new DDC93Person('Johannes', new DDC93Address('Moo', '12345', 'Karlsruhe', new DDC93Country('Germany')));
        $this->_em->persist($person);
        $this->_em->flush();

        // SELECT
        $selectDql    = 'SELECT p FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.address.city = :city AND p.address.country.name = :country';
        $loadedPerson = $this->_em->createQuery($selectDql)
            ->setParameter('city', 'Karlsruhe')
            ->setParameter('country', 'Germany')
            ->getSingleResult();
        $this->assertEquals($person, $loadedPerson);

        $this->assertNull(
            $this->_em->createQuery($selectDql)
                ->setParameter('city', 'asdf')
                ->setParameter('country', 'Germany')
                ->getOneOrNullResult()
        );

        // UPDATE
        $updateDql = 'UPDATE ' . __NAMESPACE__ . '\\DDC93Person p SET p.address.street = :street, p.address.country.name = :country WHERE p.address.city = :city';
        $this->_em->createQuery($updateDql)
            ->setParameter('street', 'Boo')
            ->setParameter('country', 'DE')
            ->setParameter('city', 'Karlsruhe')
            ->execute();

        $this->_em->refresh($person);
        $this->assertEquals('Boo', $person->address->street);
        $this->assertEquals('DE', $person->address->country->name);

        // DELETE
        $this->_em->createQuery('DELETE ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.address.city = :city AND p.address.country.name = :country')
            ->setParameter('city', 'Karlsruhe')
            ->setParameter('country', 'DE')
            ->execute();

        $this->_em->clear();
        $this->assertNull($this->_em->find(DDC93Person::class, $person->id));
    }

    public function testPartialDqlOnEmbeddedObjectsField(): void
    {
        $person = new DDC93Person('Karl', new DDC93Address('Foo', '12345', 'Gosport', new DDC93Country('England')));
        $this->_em->persist($person);
        $this->_em->flush();
        $this->_em->clear();

        // Prove that the entity was persisted correctly.
        $dql = 'SELECT p FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.name = :name';

        $person = $this->_em->createQuery($dql)
            ->setParameter('name', 'Karl')
            ->getSingleResult();

        $this->assertEquals('Gosport', $person->address->city);
        $this->assertEquals('Foo', $person->address->street);
        $this->assertEquals('12345', $person->address->zip);
        $this->assertEquals('England', $person->address->country->name);

        // Clear the EM and prove that the embeddable can be the subject of a partial query.
        $this->_em->clear();

        $dql = 'SELECT PARTIAL p.{id,address.city} FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.name = :name';

        $person = $this->_em->createQuery($dql)
            ->setParameter('name', 'Karl')
            ->getSingleResult();

        // Selected field must be equal, all other fields must be null.
        $this->assertEquals('Gosport', $person->address->city);
        $this->assertNull($person->address->street);
        $this->assertNull($person->address->zip);
        $this->assertNull($person->address->country);
        $this->assertNull($person->name);

        // Clear the EM and prove that the embeddable can be the subject of a partial query regardless of attributes positions.
        $this->_em->clear();

        $dql = 'SELECT PARTIAL p.{address.city, id} FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.name = :name';

        $person = $this->_em->createQuery($dql)
            ->setParameter('name', 'Karl')
            ->getSingleResult();

        // Selected field must be equal, all other fields must be null.
        $this->assertEquals('Gosport', $person->address->city);
        $this->assertNull($person->address->street);
        $this->assertNull($person->address->zip);
        $this->assertNull($person->address->country);
        $this->assertNull($person->name);
    }

    public function testDqlWithNonExistentEmbeddableField(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no field or association named address.asdfasdf');

        $this->_em->createQuery('SELECT p FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.address.asdfasdf IS NULL')
            ->execute();
    }

    public function testPartialDqlWithNonExistentEmbeddableField(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("no mapped field named 'address.asdfasdf'");

        $this->_em->createQuery('SELECT PARTIAL p.{id,address.asdfasdf} FROM ' . __NAMESPACE__ . '\\DDC93Person p')
            ->execute();
    }

    public function testEmbeddableWithInheritance(): void
    {
        $car = new DDC93Car(new DDC93Address('Foo', '12345', 'Asdf'));
        $this->_em->persist($car);
        $this->_em->flush();

        $reloadedCar = $this->_em->find(DDC93Car::class, $car->id);
        $this->assertEquals($car, $reloadedCar);
    }

    public function testInlineEmbeddableWithPrefix(): void
    {
        $metadata = $this->_em->getClassMetadata(DDC3028PersonWithPrefix::class);

        $this->assertEquals('foobar_id', $metadata->getColumnName('id.id'));
        $this->assertEquals('bloo_foo_id', $metadata->getColumnName('nested.nestedWithPrefix.id'));
        $this->assertEquals('bloo_nestedWithEmptyPrefix_id', $metadata->getColumnName('nested.nestedWithEmptyPrefix.id'));
        $this->assertEquals('bloo_id', $metadata->getColumnName('nested.nestedWithPrefixFalse.id'));
    }

    public function testInlineEmbeddableEmptyPrefix(): void
    {
        $metadata = $this->_em->getClassMetadata(DDC3028PersonEmptyPrefix::class);

        $this->assertEquals('id_id', $metadata->getColumnName('id.id'));
        $this->assertEquals('nested_foo_id', $metadata->getColumnName('nested.nestedWithPrefix.id'));
        $this->assertEquals('nested_nestedWithEmptyPrefix_id', $metadata->getColumnName('nested.nestedWithEmptyPrefix.id'));
        $this->assertEquals('nested_id', $metadata->getColumnName('nested.nestedWithPrefixFalse.id'));
    }

    public function testInlineEmbeddablePrefixFalse(): void
    {
        $expectedColumnName = 'id';

        $actualColumnName = $this->_em
            ->getClassMetadata(DDC3028PersonPrefixFalse::class)
            ->getColumnName('id.id');

        $this->assertEquals($expectedColumnName, $actualColumnName);
    }

    public function testInlineEmbeddableInMappedSuperClass(): void
    {
        $isFieldMapped = $this->_em
            ->getClassMetadata(DDC3027Dog::class)
            ->hasField('address.street');

        $this->assertTrue($isFieldMapped);
    }

    /**
     * @dataProvider getInfiniteEmbeddableNestingData
     */
    public function testThrowsExceptionOnInfiniteEmbeddableNesting(
        string $embeddableClassName,
        string $declaredEmbeddableClassName
    ): void {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Infinite nesting detected for embedded property %s::nested. ' .
                'You cannot embed an embeddable from the same type inside an embeddable.',
                __NAMESPACE__ . '\\' . $declaredEmbeddableClassName
            )
        );

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(__NAMESPACE__ . '\\' . $embeddableClassName),
            ]
        );
    }

    /**
     * @psalm-return list<array{string, string}>
     */
    public function getInfiniteEmbeddableNestingData(): array
    {
        return [
            ['DDCInfiniteNestingEmbeddable', 'DDCInfiniteNestingEmbeddable'],
            ['DDCNestingEmbeddable1', 'DDCNestingEmbeddable4'],
        ];
    }
}


/**
 * @Entity
 */
class DDC93Person
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string|null
     * @Column(type="string")
     */
    public $name;

    /**
     * @var DDC93Address|null
     * @Embedded(class="DDC93Address")
     */
    public $address;

    /**
     * @var DDC93Timestamps
     * @Embedded(class = "DDC93Timestamps")
     */
    public $timestamps;

    public function __construct(?string $name = null, ?DDC93Address $address = null)
    {
        $this->name       = $name;
        $this->address    = $address;
        $this->timestamps = new DDC93Timestamps(new DateTime());
    }
}

/**
 * @Embeddable
 */
class DDC93Timestamps
{
    /**
     * @var DateTime
     * @Column(type = "datetime")
     */
    public $createdAt;

    public function __construct(DateTime $createdAt)
    {
        $this->createdAt = $createdAt;
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name = "t", type = "string", length = 10)
 * @DiscriminatorMap({
 *     "v" = "Doctrine\Tests\ORM\Functional\DDC93Car",
 * })
 */
abstract class DDC93Vehicle
{
    /**
     * @var int
     * @Id
     * @GeneratedValue(strategy = "AUTO") @Column(type = "integer")
     */
    public $id;

    /**
     * @var DDC93Address
     * @Embedded(class = "DDC93Address")
     */
    public $address;

    public function __construct(DDC93Address $address)
    {
        $this->address = $address;
    }
}

/**
 * @Entity
 */
class DDC93Car extends DDC93Vehicle
{
}

/**
 * @Embeddable
 */
class DDC93Country
{
    /**
     * @var string|null
     * @Column(type="string", nullable=true)
     */
    public $name;

    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }
}

/**
 * @Embeddable
 */
class DDC93Address
{
    /**
     * @var string|null
     * @Column(type="string")
     */
    public $street;

    /**
     * @var string|null
     * @Column(type="string")
     */
    public $zip;

    /**
     * @var string|null
     * @Column(type="string")
     */
    public $city;

    /**
     * @var DDC93Country|null
     * @Embedded(class = "DDC93Country")
     */
    public $country;

    public function __construct(
        ?string $street = null,
        ?string $zip = null,
        ?string $city = null,
        ?DDC93Country $country = null
    ) {
        $this->street  = $street;
        $this->zip     = $zip;
        $this->city    = $city;
        $this->country = $country;
    }
}

/** @Entity */
class DDC93Customer
{
    /**
     * @var int
     * @Id
     * @GeneratedValue @Column(type="integer")
     */
    private $id;

    /**
     * @var DDC93ContactInfo
     * @Embedded(class = "DDC93ContactInfo", columnPrefix = "contact_info_")
     */
    private $contactInfo;
}

/** @Embeddable */
class DDC93ContactInfo
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $email;

    /**
     * @var DDC93Address
     * @Embedded(class = "DDC93Address")
     */
    public $address;
}

/**
 * @Entity
 */
class DDC3028PersonWithPrefix
{
    /**
     * @var DDC3028Id|null
     * @Embedded(class="DDC3028Id", columnPrefix = "foobar_")
     */
    public $id;

    /**
     * @var DDC3028NestedEmbeddable|null
     * @Embedded(class="DDC3028NestedEmbeddable", columnPrefix = "bloo_")
     */
    public $nested;

    public function __construct(?DDC3028Id $id = null, ?DDC3028NestedEmbeddable $nested = null)
    {
        $this->id     = $id;
        $this->nested = $nested;
    }
}

/**
 * @Entity
 */
class DDC3028PersonEmptyPrefix
{
    /**
     * @var DDC3028Id|null
     * @Embedded(class="DDC3028Id", columnPrefix = "")
     */
    public $id;

    /**
     * @var DDC3028NestedEmbeddable|null
     * @Embedded(class="DDC3028NestedEmbeddable", columnPrefix = "")
     */
    public $nested;

    public function __construct(?DDC3028Id $id = null, ?DDC3028NestedEmbeddable $nested = null)
    {
        $this->id     = $id;
        $this->nested = $nested;
    }
}

/**
 * @Entity
 */
class DDC3028PersonPrefixFalse
{
    /**
     * @var DDC3028Id|null
     * @Embedded(class="DDC3028Id", columnPrefix = false)
     */
    public $id;

    public function __construct(?DDC3028Id $id = null)
    {
        $this->id = $id;
    }
}

/**
 * @Embeddable
 */
class DDC3028Id
{
    /**
     * @var string|null
     * @Id
     * @Column(type="string")
     */
    public $id;

    public function __construct(?string $id = null)
    {
        $this->id = $id;
    }
}

/**
 * @Embeddable
 */
class DDC3028NestedEmbeddable
{
    /**
     * @var DDC3028Id|null
     * @Embedded(class="DDC3028Id", columnPrefix = "foo_")
     */
    public $nestedWithPrefix;

    /**
     * @var DDC3028Id|null
     * @Embedded(class="DDC3028Id", columnPrefix = "")
     */
    public $nestedWithEmptyPrefix;

    /**
     * @var DDC3028Id|null
     * @Embedded(class="DDC3028Id", columnPrefix = false)
     */
    public $nestedWithPrefixFalse;

    public function __construct(
        ?DDC3028Id $nestedWithPrefix = null,
        ?DDC3028Id $nestedWithEmptyPrefix = null,
        ?DDC3028Id $nestedWithPrefixFalse = null
    ) {
        $this->nestedWithPrefix      = $nestedWithPrefix;
        $this->nestedWithEmptyPrefix = $nestedWithEmptyPrefix;
        $this->nestedWithPrefixFalse = $nestedWithPrefixFalse;
    }
}

/**
 * @MappedSuperclass
 */
abstract class DDC3027Animal
{
    /**
     * @var int
     * @Id
     * @GeneratedValue(strategy = "AUTO")
     * @Column(type = "integer")
     */
    public $id;

    /**
     * @var DDC93Address
     * @Embedded(class = "DDC93Address")
     */
    public $address;
}

/**
 * @Entity
 */
class DDC3027Dog extends DDC3027Animal
{
}

/**
 * @Embeddable
 */
class DDCInfiniteNestingEmbeddable
{
    /**
     * @var DDCInfiniteNestingEmbeddable
     * @Embedded(class="DDCInfiniteNestingEmbeddable")
     */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable1
{
    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id1;

    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id2;

    /**
     * @var DDCNestingEmbeddable2
     * @Embedded(class="DDCNestingEmbeddable2")
     */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable2
{
    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id1;

    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id2;

    /**
     * @var DDCNestingEmbeddable3
     * @Embedded(class="DDCNestingEmbeddable3")
     */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable3
{
    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id1;

    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id2;

    /**
     * @var DDCNestingEmbeddable4
     * @Embedded(class="DDCNestingEmbeddable4")
     */
    public $nested;
}

/**
 * @Embeddable
 */
class DDCNestingEmbeddable4
{
    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id1;

    /**
     * @var DDC3028Id
     * @Embedded(class="DDC3028Id")
     */
    public $id2;

    /**
     * @var DDCNestingEmbeddable1
     * @Embedded(class="DDCNestingEmbeddable1")
     */
    public $nested;
}
