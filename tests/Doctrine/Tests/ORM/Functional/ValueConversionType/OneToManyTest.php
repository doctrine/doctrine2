<?php

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\Tests\Models\ValueConversionType as Entity;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * The entities all use a custom type that converst the value as identifier(s).
 * {@see \Doctrine\Tests\DbalTypes\Rot13Type}
 *
 * Test that OneToMany associations work correctly.
 *
 * @group DDC-3380
 */
class OneToManyTest extends OrmFunctionalTestCase
{
    public function setUp()
    {
        if (DBALType::hasType('rot13')) {
            DBALType::overrideType('rot13', 'Doctrine\Tests\DbalTypes\Rot13Type');
        } else {
            DBALType::addType('rot13', 'Doctrine\Tests\DbalTypes\Rot13Type');
        }

        $this->useModelSet('vct_onetomany');
        parent::setUp();

        $inversed = new Entity\InversedOneToManyEntity();
        $inversed->id = 'abc';
        $inversed->someProperty = 'some value to be loaded';

        $owning = new Entity\OwningManyToOneEntity();
        $owning->id = 'def';

        $inversed->associatedEntities->add($owning);
        $owning->associatedEntity = $inversed;

        $this->_em->persist($inversed);
        $this->_em->persist($owning);

        $this->_em->flush();
        $this->_em->clear();
    }

    public static function tearDownAfterClass()
    {
        $conn = static::$_sharedConn;

        $conn->executeUpdate('DROP TABLE vct_owning_manytoone');
        $conn->executeUpdate('DROP TABLE vct_inversed_onetomany');
    }

    public function testThatTheValueOfIdentifiersAreConvertedInTheDatabase()
    {
        $conn = $this->_em->getConnection();

        $this->assertEquals('nop', $conn->fetchColumn('SELECT id FROM vct_inversed_onetomany LIMIT 1'));

        $this->assertEquals('qrs', $conn->fetchColumn('SELECT id FROM vct_owning_manytoone LIMIT 1'));
        $this->assertEquals('nop', $conn->fetchColumn('SELECT associated_id FROM vct_owning_manytoone LIMIT 1'));
    }

    /**
     * @depends testThatTheValueOfIdentifiersAreConvertedInTheDatabase
     */
    public function testThatEntitiesAreFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            'Doctrine\Tests\Models\ValueConversionType\InversedOneToManyEntity',
            'abc'
        );

        $owning = $this->_em->find(
            'Doctrine\Tests\Models\ValueConversionType\OwningManyToOneEntity',
            'def'
        );

        $this->assertInstanceOf('Doctrine\Tests\Models\ValueConversionType\InversedOneToManyEntity', $inversed);
        $this->assertInstanceOf('Doctrine\Tests\Models\ValueConversionType\OwningManyToOneEntity', $owning);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheValueOfIdentifiersAreConvertedBackAfterBeingFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            'Doctrine\Tests\Models\ValueConversionType\InversedOneToManyEntity',
            'abc'
        );

        $owning = $this->_em->find(
            'Doctrine\Tests\Models\ValueConversionType\OwningManyToOneEntity',
            'def'
        );

        $this->assertEquals('abc', $inversed->id);
        $this->assertEquals('def', $owning->id);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheProxyFromOwningToInversedIsLoaded()
    {
        $owning = $this->_em->find(
            'Doctrine\Tests\Models\ValueConversionType\OwningManyToOneEntity',
            'def'
        );

        $inversedProxy = $owning->associatedEntity;

        $this->assertEquals('some value to be loaded', $inversedProxy->someProperty);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheCollectionFromInversedToOwningIsLoaded()
    {
        $inversed = $this->_em->find(
            'Doctrine\Tests\Models\ValueConversionType\InversedOneToManyEntity',
            'abc'
        );

        $this->assertCount(1, $inversed->associatedEntities);
    }
}
