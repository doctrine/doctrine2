<?php

namespace Doctrine\Tests\ORM\Functional\ValueConversionType;

use Doctrine\Tests\Models;
use Doctrine\Tests\Models\ValueConversionType as Entity;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * The entities all use a custom type that converst the value as identifier(s).
 * {@see \Doctrine\Tests\DbalTypes\Rot13Type}
 *
 * Test that ManyToMany associations with composite id work correctly.
 *
 * @group DDC-3380
 */
class ManyToManyCompositeIdTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        $this->useModelSet('vct_manytomany_compositeid');

        parent::setUp();

        $inversed = new Entity\InversedManyToManyCompositeIdEntity();
        $inversed->id1 = 'abc';
        $inversed->id2 = 'def';

        $owning = new Entity\OwningManyToManyCompositeIdEntity();
        $owning->id3 = 'ghi';

        $inversed->associatedEntities->add($owning);
        $owning->associatedEntities->add($inversed);

        $this->_em->persist($inversed);
        $this->_em->persist($owning);

        $this->_em->flush();
        $this->_em->clear();
    }

    public static function tearDownAfterClass() : void
    {
        $conn = static::$_sharedConn;

        $conn->executeUpdate('DROP TABLE vct_xref_manytomany_compositeid');
        $conn->executeUpdate('DROP TABLE vct_owning_manytomany_compositeid');
        $conn->executeUpdate('DROP TABLE vct_inversed_manytomany_compositeid');
    }

    public function testThatTheValueOfIdentifiersAreConvertedInTheDatabase()
    {
        $conn = $this->_em->getConnection();

        $this->assertEquals('nop', $conn->fetchOne('SELECT id1 FROM vct_inversed_manytomany_compositeid LIMIT 1'));
        $this->assertEquals('qrs', $conn->fetchOne('SELECT id2 FROM vct_inversed_manytomany_compositeid LIMIT 1'));

        $this->assertEquals('tuv', $conn->fetchOne('SELECT id3 FROM vct_owning_manytomany_compositeid LIMIT 1'));

        $this->assertEquals('nop', $conn->fetchOne('SELECT inversed_id1 FROM vct_xref_manytomany_compositeid LIMIT 1'));
        $this->assertEquals('qrs', $conn->fetchOne('SELECT inversed_id2 FROM vct_xref_manytomany_compositeid LIMIT 1'));
        $this->assertEquals('tuv', $conn->fetchOne('SELECT owning_id FROM vct_xref_manytomany_compositeid LIMIT 1'));
    }

    /**
     * @depends testThatTheValueOfIdentifiersAreConvertedInTheDatabase
     */
    public function testThatEntitiesAreFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedManyToManyCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningManyToManyCompositeIdEntity::class,
            'ghi'
        );

        $this->assertInstanceOf(Models\ValueConversionType\InversedManyToManyCompositeIdEntity::class, $inversed);
        $this->assertInstanceOf(Models\ValueConversionType\OwningManyToManyCompositeIdEntity::class, $owning);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheValueOfIdentifiersAreConvertedBackAfterBeingFetchedFromTheDatabase()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedManyToManyCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        $owning = $this->_em->find(
            Models\ValueConversionType\OwningManyToManyCompositeIdEntity::class,
            'ghi'
        );

        $this->assertEquals('abc', $inversed->id1);
        $this->assertEquals('def', $inversed->id2);
        $this->assertEquals('ghi', $owning->id3);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheCollectionFromOwningToInversedIsLoaded()
    {
        $owning = $this->_em->find(
            Models\ValueConversionType\OwningManyToManyCompositeIdEntity::class,
            'ghi'
        );

        $this->assertCount(1, $owning->associatedEntities);
    }

    /**
     * @depends testThatEntitiesAreFetchedFromTheDatabase
     */
    public function testThatTheCollectionFromInversedToOwningIsLoaded()
    {
        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedManyToManyCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        $this->assertCount(1, $inversed->associatedEntities);
    }

    /**
     * @depends testThatTheCollectionFromOwningToInversedIsLoaded
     * @depends testThatTheCollectionFromInversedToOwningIsLoaded
     */
    public function testThatTheJoinTableRowsAreRemovedWhenRemovingTheAssociation()
    {
        $conn = $this->_em->getConnection();

        // remove association

        $inversed = $this->_em->find(
            Models\ValueConversionType\InversedManyToManyCompositeIdEntity::class,
            ['id1' => 'abc', 'id2' => 'def']
        );

        foreach ($inversed->associatedEntities as $owning) {
            $inversed->associatedEntities->removeElement($owning);
            $owning->associatedEntities->removeElement($inversed);
        }

        $this->_em->flush();
        $this->_em->clear();

        // test association is removed

        $this->assertEquals(0, $conn->fetchOne('SELECT COUNT(*) FROM vct_xref_manytomany_compositeid'));
    }
}
