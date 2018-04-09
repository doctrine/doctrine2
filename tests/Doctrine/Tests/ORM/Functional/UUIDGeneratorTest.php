<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Annotation as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;
use function strlen;

/**
 * @group DDC-451
 */
class UUIDGeneratorTest extends OrmFunctionalTestCase
{
    public function setUp() : void
    {
        parent::setUp();

        if ($this->em->getConnection()->getDatabasePlatform()->getName() !== 'mysql') {
            $this->markTestSkipped('Currently restricted to MySQL platform.');
        }

        $this->schemaTool->createSchema(
            [$this->em->getClassMetadata(UUIDEntity::class)]
        );
    }

    public function testGenerateUUID() : void
    {
        $entity = new UUIDEntity();

        $this->em->persist($entity);
        self::assertNotNull($entity->getId());
        self::assertGreaterThan(0, strlen($entity->getId()));
    }
}

/**
 * @ORM\Entity
 */
class UUIDEntity
{
    /** @ORM\Id @ORM\Column(type="string") @ORM\GeneratedValue(strategy="UUID") */
    private $id;
    /**
     * Get id.
     *
     * @return string.
     */
    public function getId()
    {
        return $this->id;
    }
}
