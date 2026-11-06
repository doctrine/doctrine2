<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
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
use Doctrine\ORM\Query\QueryException;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function sprintf;

#[Group('DDC-93')]
class ValueObjectsTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            DDC93Person::class,
            DDC93Address::class,
            DDC93Vehicle::class,
            DDC93Car::class,
            DDC3027Animal::class,
            DDC3027Dog::class,
        );
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

        self::assertInstanceOf(DDC93Address::class, $person->address);
        self::assertEquals('United States of Tara Street', $person->address->street);
        self::assertEquals('12345', $person->address->zip);
        self::assertEquals('funkytown', $person->address->city);
        self::assertInstanceOf(DDC93Country::class, $person->address->country);
        self::assertEquals('Germany', $person->address->country->name);

        // 3. check changing value objects works
        $person->address->street        = 'Street';
        $person->address->zip           = '54321';
        $person->address->city          = 'another town';
        $person->address->country->name = 'United States of America';
        $this->_em->flush();

        $this->_em->clear();

        $person = $this->_em->find(DDC93Person::class, $person->id);

        self::assertEquals('Street', $person->address->street);
        self::assertEquals('54321', $person->address->zip);
        self::assertEquals('another town', $person->address->city);
        self::assertEquals('United States of America', $person->address->country->name);

        // 4. check deleting works
        $personId = $person->id;

        $this->_em->remove($person);
        $this->_em->flush();

        self::assertNull($this->_em->find(DDC93Person::class, $personId));
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

        self::assertCount(3, $persons);
        foreach ($persons as $person) {
            self::assertInstanceOf(DDC93Address::class, $person->address);
            self::assertEquals('Tree', $person->address->street);
            self::assertEquals('12345', $person->address->zip);
            self::assertEquals('funkytown', $person->address->city);
            self::assertInstanceOf(DDC93Country::class, $person->address->country);
            self::assertEquals('United States of America', $person->address->country->name);
        }

        $dql     = 'SELECT p FROM ' . __NAMESPACE__ . '\DDC93Person p';
        $persons = $this->_em->createQuery($dql)->getArrayResult();

        foreach ($persons as $person) {
            self::assertEquals('Tree', $person['address.street']);
            self::assertEquals('12345', $person['address.zip']);
            self::assertEquals('funkytown', $person['address.city']);
            self::assertEquals('United States of America', $person['address.country.name']);
        }
    }

    #[Group('dql')]
    public function testDqlOnEmbeddedObjectsField(): void
    {
        if ($this->isSecondLevelCacheEnabled) {
            self::markTestSkipped('SLC does not work with UPDATE/DELETE queries through EM.');
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
        self::assertEquals($person, $loadedPerson);

        self::assertNull(
            $this->_em->createQuery($selectDql)
                ->setParameter('city', 'asdf')
                ->setParameter('country', 'Germany')
                ->getOneOrNullResult(),
        );

        // UPDATE
        $updateDql = 'UPDATE ' . __NAMESPACE__ . '\\DDC93Person p SET p.address.street = :street, p.address.country.name = :country WHERE p.address.city = :city';
        $this->_em->createQuery($updateDql)
            ->setParameter('street', 'Boo')
            ->setParameter('country', 'DE')
            ->setParameter('city', 'Karlsruhe')
            ->execute();

        $this->_em->refresh($person);
        self::assertEquals('Boo', $person->address->street);
        self::assertEquals('DE', $person->address->country->name);

        // DELETE
        $this->_em->createQuery('DELETE ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.address.city = :city AND p.address.country.name = :country')
            ->setParameter('city', 'Karlsruhe')
            ->setParameter('country', 'DE')
            ->execute();

        $this->_em->clear();
        self::assertNull($this->_em->find(DDC93Person::class, $person->id));
    }

    public function testPartialDqlOnEmbeddedObjectsField(): void
    {
        if (! $this->_em->getConfiguration()->isNativeLazyObjectsEnabled()) {
            $this->markTestSkipped('Test requires native lazy objects to be enabled.');
        }

        $person = new DDC93Person('Karl', new DDC93Address('Foo', '12345', 'Gosport', new DDC93Country('England')));
        $this->_em->persist($person);
        $this->_em->flush();
        $this->_em->clear();

        // Prove that the entity was persisted correctly.
        $dql = 'SELECT p FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.name = :name';

        $person = $this->_em->createQuery($dql)
            ->setParameter('name', 'Karl')
            ->getSingleResult();

        self::assertEquals('Gosport', $person->address->city);
        self::assertEquals('Foo', $person->address->street);
        self::assertEquals('12345', $person->address->zip);
        self::assertEquals('England', $person->address->country->name);

        $uow = $this->_em->getUnitOfWork();

        // Clear the EM and prove that the embeddable can be the subject of a partial query.
        $this->_em->clear();

        $dql = 'SELECT PARTIAL p.{id,address.city} FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.name = :name';

        $person = $this->_em->createQuery($dql)
            ->setParameter('name', 'Karl')
            ->getSingleResult();

        // Immediately after loading: the entity ghost and its embedded address ghost
        // are both uninitialized — no SELECT has been issued yet.
        self::assertTrue($uow->isUninitializedObject($person));
        self::assertTrue($uow->isUninitializedObject($person->address));

        // Reading an embedded field through its property accessor does NOT trigger lazy init.
        self::assertSame('Gosport', $this->_em->getClassMetadata(DDC93Person::class)->propertyAccessors['address.city']->getValue($person));
        self::assertTrue($uow->isUninitializedObject($person));
        self::assertTrue($uow->isUninitializedObject($person->address));

        // Accessing the pre-loaded field (city) does NOT trigger lazy init.
        self::assertEquals('Gosport', $person->address->city);
        self::assertTrue($uow->isUninitializedObject($person));
        self::assertTrue($uow->isUninitializedObject($person->address));

        // Accessing an unloaded embedded field triggers the embeddable ghost's
        // lazy initializer, which calls loadById on the parent entity.
        // Both the embeddable ghost and the parent entity are now initialized.
        self::assertEquals('Foo', $person->address->street);
        self::assertFalse($uow->isUninitializedObject($person));
        self::assertFalse($uow->isUninitializedObject($person->address));

        // All remaining fields are now available without further queries.
        self::assertEquals('12345', $person->address->zip);
        self::assertNotNull($person->address->country);
        self::assertEquals('England', $person->address->country->name);
        self::assertEquals('Karl', $person->name);

        // Clear the EM and prove that the embeddable can be the subject of a partial query regardless of attributes positions.
        $this->_em->clear();

        $dql = 'SELECT PARTIAL p.{address.city, id} FROM ' . __NAMESPACE__ . '\\DDC93Person p WHERE p.name = :name';

        $person = $this->_em->createQuery($dql)
            ->setParameter('name', 'Karl')
            ->getSingleResult();

        // Same lazy-loading semantics regardless of field order in the DQL.
        self::assertTrue($uow->isUninitializedObject($person));
        self::assertTrue($uow->isUninitializedObject($person->address));

        self::assertEquals('Gosport', $person->address->city);
        self::assertTrue($uow->isUninitializedObject($person));
        self::assertTrue($uow->isUninitializedObject($person->address));

        self::assertEquals('Foo', $person->address->street);
        self::assertFalse($uow->isUninitializedObject($person));
        self::assertFalse($uow->isUninitializedObject($person->address));

        self::assertEquals('12345', $person->address->zip);
        self::assertNotNull($person->address->country);
        self::assertEquals('England', $person->address->country->name);
        self::assertEquals('Karl', $person->name);
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
        self::assertEquals($car, $reloadedCar);
    }

    public function testInlineEmbeddableWithPrefix(): void
    {
        $metadata = $this->_em->getClassMetadata(DDC3028PersonWithPrefix::class);

        self::assertEquals('foobar_id', $metadata->getColumnName('id.id'));
        self::assertEquals('bloo_foo_id', $metadata->getColumnName('nested.nestedWithPrefix.id'));
        self::assertEquals('bloo_nestedWithEmptyPrefix_id', $metadata->getColumnName('nested.nestedWithEmptyPrefix.id'));
        self::assertEquals('bloo_id', $metadata->getColumnName('nested.nestedWithPrefixFalse.id'));
    }

    public function testInlineEmbeddableEmptyPrefix(): void
    {
        $metadata = $this->_em->getClassMetadata(DDC3028PersonEmptyPrefix::class);

        self::assertEquals('id_id', $metadata->getColumnName('id.id'));
        self::assertEquals('nested_foo_id', $metadata->getColumnName('nested.nestedWithPrefix.id'));
        self::assertEquals('nested_nestedWithEmptyPrefix_id', $metadata->getColumnName('nested.nestedWithEmptyPrefix.id'));
        self::assertEquals('nested_id', $metadata->getColumnName('nested.nestedWithPrefixFalse.id'));
    }

    public function testInlineEmbeddablePrefixFalse(): void
    {
        $expectedColumnName = 'id';

        $actualColumnName = $this->_em
            ->getClassMetadata(DDC3028PersonPrefixFalse::class)
            ->getColumnName('id.id');

        self::assertEquals($expectedColumnName, $actualColumnName);
    }

    public function testInlineEmbeddableInMappedSuperClass(): void
    {
        $isFieldMapped = $this->_em
            ->getClassMetadata(DDC3027Dog::class)
            ->hasField('address.street');

        self::assertTrue($isFieldMapped);
    }

    #[DataProvider('getInfiniteEmbeddableNestingData')]
    public function testThrowsExceptionOnInfiniteEmbeddableNesting(
        string $embeddableClassName,
        string $declaredEmbeddableClassName,
    ): void {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Infinite nesting detected for embedded property %s::nested. ' .
                'You cannot embed an embeddable from the same type inside an embeddable.',
                __NAMESPACE__ . '\\' . $declaredEmbeddableClassName,
            ),
        );

        $this->createSchemaForModels(__NAMESPACE__ . '\\' . $embeddableClassName);
    }

    /** @phpstan-return list<array{string, string}> */
    public static function getInfiniteEmbeddableNestingData(): array
    {
        return [
            ['DDCInfiniteNestingEmbeddable', 'DDCInfiniteNestingEmbeddable'],
            ['DDCNestingEmbeddable1', 'DDCNestingEmbeddable4'],
        ];
    }
}


#[Entity]
class DDC93Person
{
    /** @var int */
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public $id;

    /** @var DDC93Timestamps */
    #[Embedded(class: 'DDC93Timestamps')]
    public $timestamps;

    public function __construct(
        /** @var string|null */
        #[Column(type: 'string', length: 255)]
        public $name = null,
        #[Embedded(class: 'DDC93Address')]
        public DDC93Address|null $address = null,
    ) {
        $this->timestamps = new DDC93Timestamps(new DateTime());
    }
}

#[Embeddable]
class DDC93Timestamps
{
    public function __construct(
        #[Column(type: 'datetime')]
        public DateTime $createdAt,
    ) {
    }
}

#[Entity]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 't', type: 'string', length: 10)]
#[DiscriminatorMap(['v' => 'Doctrine\Tests\ORM\Functional\DDC93Car'])]
abstract class DDC93Vehicle
{
    /** @var int */
    #[Id]
    #[GeneratedValue(strategy: 'AUTO')]
    #[Column(type: 'integer')]
    public $id;

    public function __construct(
        #[Embedded(class: 'DDC93Address')]
        public DDC93Address $address,
    ) {
    }
}

#[Entity]
class DDC93Car extends DDC93Vehicle
{
}

#[Embeddable]
class DDC93Country
{
    public function __construct(
        #[Column(type: 'string', nullable: true)]
        public string|null $name = null,
    ) {
    }
}

#[Embeddable]
class DDC93Address
{
    #[Embedded(class: DDC93Country::class)]
    public DDC93Country|null $country = null;

    /**
     * @param string|null $street
     * @param string|null $zip
     */
    public function __construct(
        #[Column(type: 'string', length: 255)]
        public $street = null,
        #[Column(type: 'string', length: 255)]
        public $zip = null,
        #[Column(type: 'string', length: 255)]
        public string|null $city = null,
        DDC93Country|null $country = null,
    ) {
        $this->country = $country;
    }
}

#[Entity]
class DDC3028PersonWithPrefix
{
    public function __construct(
        #[Embedded(class: 'DDC3028Id', columnPrefix: 'foobar_')]
        public DDC3028Id|null $id = null,
        #[Embedded(class: 'DDC3028NestedEmbeddable', columnPrefix: 'bloo_')]
        public DDC3028NestedEmbeddable|null $nested = null,
    ) {
    }
}

#[Entity]
class DDC3028PersonEmptyPrefix
{
    public function __construct(
        #[Embedded(class: 'DDC3028Id', columnPrefix: '')]
        public DDC3028Id|null $id = null,
        #[Embedded(class: 'DDC3028NestedEmbeddable', columnPrefix: '')]
        public DDC3028NestedEmbeddable|null $nested = null,
    ) {
    }
}

#[Entity]
class DDC3028PersonPrefixFalse
{
    public function __construct(
        #[Embedded(class: 'DDC3028Id', columnPrefix: false)]
        public DDC3028Id|null $id = null,
    ) {
    }
}

#[Embeddable]
class DDC3028Id
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 255)]
        public string|null $id = null,
    ) {
    }
}

#[Embeddable]
class DDC3028NestedEmbeddable
{
    public function __construct(
        #[Embedded(class: 'DDC3028Id', columnPrefix: 'foo_')]
        public DDC3028Id|null $nestedWithPrefix = null,
        #[Embedded(class: 'DDC3028Id', columnPrefix: '')]
        public DDC3028Id|null $nestedWithEmptyPrefix = null,
        #[Embedded(class: 'DDC3028Id', columnPrefix: false)]
        public DDC3028Id|null $nestedWithPrefixFalse = null,
    ) {
    }
}

#[MappedSuperclass]
abstract class DDC3027Animal
{
    /** @var int */
    #[Id]
    #[GeneratedValue(strategy: 'AUTO')]
    #[Column(type: 'integer')]
    public $id;

    /** @var DDC93Address */
    #[Embedded(class: 'DDC93Address')]
    public $address;
}

#[Entity]
class DDC3027Dog extends DDC3027Animal
{
}

#[Embeddable]
class DDCInfiniteNestingEmbeddable
{
    /** @var DDCInfiniteNestingEmbeddable */
    #[Embedded(class: 'DDCInfiniteNestingEmbeddable')]
    public $nested;
}

#[Embeddable]
class DDCNestingEmbeddable1
{
    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id1;

    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id2;

    /** @var DDCNestingEmbeddable2 */
    #[Embedded(class: 'DDCNestingEmbeddable2')]
    public $nested;
}

#[Embeddable]
class DDCNestingEmbeddable2
{
    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id1;

    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id2;

    /** @var DDCNestingEmbeddable3 */
    #[Embedded(class: 'DDCNestingEmbeddable3')]
    public $nested;
}

#[Embeddable]
class DDCNestingEmbeddable3
{
    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id1;

    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id2;

    /** @var DDCNestingEmbeddable4 */
    #[Embedded(class: 'DDCNestingEmbeddable4')]
    public $nested;
}

#[Embeddable]
class DDCNestingEmbeddable4
{
    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id1;

    /** @var DDC3028Id */
    #[Embedded(class: 'DDC3028Id')]
    public $id2;

    /** @var DDCNestingEmbeddable1 */
    #[Embedded(class: 'DDCNestingEmbeddable1')]
    public $nested;
}
