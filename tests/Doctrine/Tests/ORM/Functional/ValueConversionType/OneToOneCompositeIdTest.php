<?php

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\Tests\Models;
use Doctrine\Tests\Models\ValueConversionType as Entity;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * The entities all use a custom type that converst the value as identifier(s).
 * {@see \Doctrine\Tests\DbalTypes\Rot13Type}
 *
 * Test that OneToOne associations with composite id work correctly.
 *
 * @group DDC-3380
 */
class OneToOneCompositeIdTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('vct_onetoone_compositeid');
        parent::setUp();

        $inversed = new Entity\InversedOneToOneCompositeIdEntity();
        $inversed->id1 = 'abc';
        $inversed->id2 = 'def';
        $inversed->someProperty = 'some value to be loaded';

        $owning = new Entity\OwningOneToOneCompositeIdEntity();
        $owning->id3 = 'ghi';

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

        $conn->executeUpdate('DROP TABLE vct_owning_onetoone_compositeid');
        $conn->executeUpdate('DROP TABLE vct_inversed_onetoone_compositeid');
    }

    public function testThatTheValueOfIdentifiersAreConvertedInTheDatabase()
    {
        $conn = $this->_em->getConnection();

        $this->assertEquals('nop', $conn->fetchOne('SELECT id1 FROM vct_inversed_onetoone_compositeid LIMIT 1'));
        $this->assertEquals('qrs', $conn->fetchOne('SELECT id2 FROM vct_inversed_onetoone_compositeid LIMIT 1'));

        $this->assertEquals('tuv', $conn->fetchOne('SELECT id3 FROM vct_owning_onetoone_compositeid LIMIT 1'));
        $this->assertEquals('nop', $conn->fetchOne('SELECT associated_id1 FROM vct_owning_onetoone_compositeid LIMIT 1'));
        $this->assertEquals('qrs', $conn->fetchOne('SELECT associated_id2 FROM vct_owning_onetoone_compositeid LIMIT 1'));
    }

    /**
     * @depends testThatTheValueOfIdentifiersAreConvertedInTheDatabase
     */
    public function testThatEntitiesAreFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToOneCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningOneToOneCompositeIdEntity::class,
            'ghi'
        );

        $this->assertInstanceOf(Models\ValueConversionType\InversedOneToOneCompositeIdEntity::class, $inversed);
        $this->assertInstanceOf(Models\ValueConversionType\OwningOneToOneCompositeIdEntity::class, $owning);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheValueOfIdentifiersAreConvertedBackAfterBeingFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToOneCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningOneToOneCompositeIdEntity::class,
            'ghi'
        );

        $this->assertEquals('abc', $inversed->id1);
        $this->assertEquals('def', $inversed->id2);
        $this->assertEquals('ghi', $owning->id3);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheProxyFromOwningToInversedIsLoaded()
    {
        $owning = $this->_em->find(
            Models\ValueConversionType\OwningOneToOneCompositeIdEntity::class,
            'ghi'
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
            Models\ValueConversionType\InversedOneToOneCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        $this->assertInstanceOf(Models\ValueConversionType\OwningOneToOneCompositeIdEntity::class, $inversed->associatedEntity);
    }
}
