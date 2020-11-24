<?php

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\Tests\Models;
use Doctrine\Tests\Models\ValueConversionType as Entity;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * The entities all use a custom type that converst the value as identifier(s).
 * {@see \Doctrine\Tests\DbalTypes\Rot13Type}
 *
 * Test that OneToOne associations work correctly.
 *
 * @group DDC-3380
 */
class OneToOneTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('vct_onetoone');

        parent::setUp();

        $inversed = new Entity\InversedOneToOneEntity();
        $inversed->id1 = 'abc';
        $inversed->someProperty = 'some value to be loaded';

        $owning = new Entity\OwningOneToOneEntity();
        $owning->id2 = 'def';

        $inversed->associatedEntity = $owning;
        $owning->associatedEntity = $inversed;

        $this->_em->persist($inversed);
        $this->_em->persist($owning);

        $this->_em->flush();
        $this->_em->clear();
    }

    public static function tearDownAfterClass() : void
    {
        $conn = static::$_sharedConn;

        $conn->executeUpdate('DROP TABLE vct_owning_onetoone');
        $conn->executeUpdate('DROP TABLE vct_inversed_onetoone');
    }

    public function testThatTheValueOfIdentifiersAreConvertedInTheDatabase()
    {
        $conn = $this->_em->getConnection();

        $this->assertEquals('nop', $conn->fetchOne('SELECT id1 FROM vct_inversed_onetoone LIMIT 1'));

        $this->assertEquals('qrs', $conn->fetchOne('SELECT id2 FROM vct_owning_onetoone LIMIT 1'));
        $this->assertEquals('nop', $conn->fetchOne('SELECT associated_id FROM vct_owning_onetoone LIMIT 1'));
    }

    /**
     * @depends testThatTheValueOfIdentifiersAreConvertedInTheDatabase
     */
    public function testThatEntitiesAreFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToOneEntity::class,
            'abc'
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningOneToOneEntity::class,
            'def'
        );

        $this->assertInstanceOf(Models\ValueConversionType\InversedOneToOneEntity::class, $inversed);
        $this->assertInstanceOf(Models\ValueConversionType\OwningOneToOneEntity::class, $owning);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheValueOfIdentifiersAreConvertedBackAfterBeingFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToOneEntity::class,
            'abc'
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningOneToOneEntity::class,
            'def'
        );

        $this->assertEquals('abc', $inversed->id1);
        $this->assertEquals('def', $owning->id2);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheProxyFromOwningToInversedIsLoaded()
    {
        $owning = $this->_em->find(
            Models\ValueConversionType\OwningOneToOneEntity::class,
            'def'
        );

        $inversedProxy = $owning->associatedEntity;

        $this->assertEquals('some value to be loaded', $inversedProxy->someProperty);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheEntityFromInversedToOwningIsEagerLoaded()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToOneEntity::class,
            'abc'
        );

        $this->assertInstanceOf(Models\ValueConversionType\OwningOneToOneEntity::class, $inversed->associatedEntity);
    }
}
