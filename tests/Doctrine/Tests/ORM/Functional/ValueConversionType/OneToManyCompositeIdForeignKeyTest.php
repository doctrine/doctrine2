<?php

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\Tests\Models;
use Doctrine\Tests\Models\ValueConversionType as Entity;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * The entities all use a custom type that converst the value as identifier(s).
 * {@see \Doctrine\Tests\DbalTypes\Rot13Type}
 *
 * Test that OneToMany associations with composite id of which one is a
 * association itself work correctly.
 *
 * @group DDC-3380
 */
class OneToManyCompositeIdForeignKeyTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('vct_onetomany_compositeid_foreignkey');

        parent::setUp();

        $auxiliary = new Entity\AuxiliaryEntity();
        $auxiliary->id4 = 'abc';

        $inversed = new Entity\InversedOneToManyCompositeIdForeignKeyEntity();
        $inversed->id1 = 'def';
        $inversed->foreignEntity = $auxiliary;
        $inversed->someProperty = 'some value to be loaded';

        $owning = new Entity\OwningManyToOneCompositeIdForeignKeyEntity();
        $owning->id2 = 'ghi';

        $inversed->associatedEntities->add($owning);
        $owning->associatedEntity = $inversed;

        $this->_em->persist($auxiliary);
        $this->_em->persist($inversed);
        $this->_em->persist($owning);

        $this->_em->flush();
        $this->_em->clear();
    }

    public static function tearDownAfterClass() : void
    {
        $conn = static::$_sharedConn;

        $conn->executeUpdate('DROP TABLE vct_owning_manytoone_compositeid_foreignkey');
        $conn->executeUpdate('DROP TABLE vct_inversed_onetomany_compositeid_foreignkey');
        $conn->executeUpdate('DROP TABLE vct_auxiliary');
    }

    public function testThatTheValueOfIdentifiersAreConvertedInTheDatabase()
    {
        $conn = $this->_em->getConnection();

        $this->assertEquals('nop', $conn->fetchOne('SELECT id4 FROM vct_auxiliary LIMIT 1'));

        $this->assertEquals('qrs', $conn->fetchOne('SELECT id1 FROM vct_inversed_onetomany_compositeid_foreignkey LIMIT 1'));
        $this->assertEquals('nop', $conn->fetchOne('SELECT foreign_id FROM vct_inversed_onetomany_compositeid_foreignkey LIMIT 1'));

        $this->assertEquals('tuv', $conn->fetchOne('SELECT id2 FROM vct_owning_manytoone_compositeid_foreignkey LIMIT 1'));
        $this->assertEquals('qrs', $conn->fetchOne('SELECT associated_id FROM vct_owning_manytoone_compositeid_foreignkey LIMIT 1'));
        $this->assertEquals('nop', $conn->fetchOne('SELECT associated_foreign_id FROM vct_owning_manytoone_compositeid_foreignkey LIMIT 1'));
    }

    /**
     * @depends testThatTheValueOfIdentifiersAreConvertedInTheDatabase
     */
    public function testThatEntitiesAreFetchedFromTheDatabase()
    {
        $auxiliary = $this->_em->find(
            Models\ValueConversionType\AuxiliaryEntity::class,
            'abc'
        );

        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class,
            ['id1' => 'def', 'foreignEntity' => 'abc']
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningManyToOneCompositeIdForeignKeyEntity::class,
            'ghi'
        );

        $this->assertInstanceOf(Models\ValueConversionType\AuxiliaryEntity::class, $auxiliary);
        $this->assertInstanceOf(Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class, $inversed);
        $this->assertInstanceOf(Models\ValueConversionType\OwningManyToOneCompositeIdForeignKeyEntity::class, $owning);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheValueOfIdentifiersAreConvertedBackAfterBeingFetchedFromTheDatabase()
    {
        $auxiliary = $this->_em->find(
            Models\ValueConversionType\AuxiliaryEntity::class,
            'abc'
        );

        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class,
            ['id1' => 'def', 'foreignEntity' => 'abc']
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningManyToOneCompositeIdForeignKeyEntity::class,
            'ghi'
        );

        $this->assertEquals('abc', $auxiliary->id4);
        $this->assertEquals('def', $inversed->id1);
        $this->assertEquals('abc', $inversed->foreignEntity->id4);
        $this->assertEquals('ghi', $owning->id2);
    }

    /**
     * @depends testThatTheValueOfIdentifiersAreConvertedBackAfterBeingFetchedFromTheDatabase
     */
    public function testThatInversedEntityIsFetchedFromTheDatabaseUsingAuxiliaryEntityAsId()
    {
        $auxiliary = $this->_em->find(
            Models\ValueConversionType\AuxiliaryEntity::class,
            'abc'
        );

        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class,
            ['id1' => 'def', 'foreignEntity' => $auxiliary]
        );

        $this->assertInstanceOf(Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class, $inversed);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheProxyFromOwningToInversedIsLoaded()
    {
        $owning = $this->_em->find(
            Models\ValueConversionType\OwningManyToOneCompositeIdForeignKeyEntity::class,
            'ghi'
        );

        $inversedProxy = $owning->associatedEntity;

        $this->assertSame('def', $inversedProxy->id1, 'Proxy identifier is converted');

        $this->assertEquals('some value to be loaded', $inversedProxy->someProperty);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheCollectionFromInversedToOwningIsLoaded()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedOneToManyCompositeIdForeignKeyEntity::class,
            ['id1' => 'def', 'foreignEntity' => 'abc']
        );

        $this->assertCount(1, $inversed->associatedEntities);
    }
}
