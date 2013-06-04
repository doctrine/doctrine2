<?php

namespace Doctrine\Tests\ORM\Functional;

/**
 * @group DDC-93
 */
class ValueObjectsTest extends \Doctrine\Tests\OrmFunctionalTestCase
{
    public function setUp()
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(array(
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC93Person'),
                $this->_em->getClassMetadata(__NAMESPACE__ . '\DDC93Address'),
            ));
        } catch(\Exception $e) {
        }
    }

    public function testCRUD()
    {
        $person = new DDC93Person();
        $person->name = "Tara";
        $person->address = new DDC93Address();
        $person->address->street = "United States of Tara Street";
        $person->address->zip = "12345";
        $person->address->city = "funkytown";

        // 1. check saving value objects works
        $this->_em->persist($person);
        $this->_em->flush();

        $this->_em->clear();

        // 2. check loading value objects works
        $person = $this->_em->find(DDC93Person::CLASSNAME, $person->id);

        $this->assertInstanceOf(DDC93Address::CLASSNAME, $person->address);
        $this->assertEquals('United States of Tara Street', $person->address->street);
        $this->assertEquals('12345', $person->address->zip);
        $this->assertEquals('funkytown', $person->address->city);

        // 3. check changing value objects works
        $person->address->street = "Street";
        $person->address->zip = "54321";
        $person->address->city = "another town";
        $this->_em->flush();

        $this->_em->clear();

        $person = $this->_em->find(DDC93Person::CLASSNAME, $person->id);

        $this->assertEquals('Street', $person->address->street);
        $this->assertEquals('54321', $person->address->zip);
        $this->assertEquals('another town', $person->address->city);

        // 4. check deleting works
        $personId = $person->id;;
        $this->_em->remove($person);
        $this->_em->flush();

        $this->assertNull($this->_em->find(DDC93Person::CLASSNAME, $personId));
    }

    public function testLoadDql()
    {
        for ($i = 0; $i < 3; $i++) {
            $person = new DDC93Person();
            $person->name = "Donkey Kong$i";
            $person->address = new DDC93Address();
            $person->address->street = "Tree";
            $person->address->zip = "12345";
            $person->address->city = "funkytown";

            $this->_em->persist($person);
        }

        $this->_em->flush();
        $this->_em->clear();

        $dql = "SELECT p FROM " . __NAMESPACE__ . "\DDC93Person p";
        $persons = $this->_em->createQuery($dql)->getResult();

        $this->assertCount(3, $persons);
        foreach ($persons as $person) {
            $this->assertInstanceOf(DDC93Address::CLASSNAME, $person->address);
            $this->assertEquals('Tree', $person->address->street);
            $this->assertEquals('12345', $person->address->zip);
            $this->assertEquals('funkytown', $person->address->city);
        }

        $dql = "SELECT p FROM " . __NAMESPACE__ . "\DDC93Person p";
        $persons = $this->_em->createQuery($dql)->getArrayResult();

        foreach ($persons as $person) {
            $this->assertEquals('Tree', $person['address.street']);
            $this->assertEquals('12345', $person['address.zip']);
            $this->assertEquals('funkytown', $person['address.city']);
        }
    }
}

/**
 * @Entity
 */
class DDC93Person
{
    const CLASSNAME = __CLASS__;

    /** @Id @GeneratedValue @Column(type="integer") */
    public $id;

    /** @Column(type="string") */
    public $name;

    /** @Embedded(class="DDC93Address") */
    public $address;
}

/**
 * @Embeddable
 */
class DDC93Address
{
    const CLASSNAME = __CLASS__;

    /**
     * @Column(type="string")
     */
    public $street;
    /**
     * @Column(type="string")
     */
    public $zip;
    /**
     * @Column(type="string")
     */
    public $city;
}

