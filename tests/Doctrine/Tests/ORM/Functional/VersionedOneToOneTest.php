<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\ORMException;
use Doctrine\Tests\Models\VersionedOneToOne\FirstRelatedEntity;
use Doctrine\Tests\Models\VersionedOneToOne\SecondRelatedEntity;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Tests that an entity with a OneToOne relationship defined as the id, with a version field can be created.
 *
 * @group VersionedOneToOne
 */
class VersionedOneToOneTest extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        try {
            $this->schemaTool->createSchema(
                [
                    $this->em->getClassMetadata(FirstRelatedEntity::class),
                    $this->em->getClassMetadata(SecondRelatedEntity::class),
                ]
            );
        } catch (ORMException $e) {
        }
    }

    /**
     * This test case tests that a versionable entity, that has a oneToOne relationship as it's id can be created
     *  without this bug fix (DDC-3318), you could not do this
     */
    public function testSetVersionOnCreate() : void
    {
        $secondRelatedEntity       = new SecondRelatedEntity();
        $secondRelatedEntity->name = 'Bob';

        $this->em->persist($secondRelatedEntity);
        $this->em->flush();

        $firstRelatedEntity               = new FirstRelatedEntity();
        $firstRelatedEntity->name         = 'Fred';
        $firstRelatedEntity->secondEntity = $secondRelatedEntity;

        $this->em->persist($firstRelatedEntity);
        $this->em->flush();

        $firstEntity = $this->em->getRepository(FirstRelatedEntity::class)
            ->findOneBy(['name' => 'Fred']);

        $secondEntity = $this->em->getRepository(SecondRelatedEntity::class)
            ->findOneBy(['name' => 'Bob']);

        self::assertSame($firstRelatedEntity, $firstEntity);
        self::assertSame($secondRelatedEntity, $secondEntity);
        self::assertSame($firstEntity->secondEntity, $secondEntity);
    }
}
