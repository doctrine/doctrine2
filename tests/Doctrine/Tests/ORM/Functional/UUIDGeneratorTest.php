<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

use function strlen;

/**
 * @group DDC-451
 */
class UUIDGeneratorTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    public function testItIsDeprecated(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/7312');
        $this->_em->getClassMetadata(UUIDEntity::class);
    }

    public function testGenerateUUID(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'mysql') {
            $this->markTestSkipped('Currently restricted to MySQL platform.');
        }

        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(UUIDEntity::class),
        ]);
        $entity = new UUIDEntity();

        $this->_em->persist($entity);
        $this->assertNotNull($entity->getId());
        $this->assertTrue(strlen($entity->getId()) > 0);
    }
}

/**
 * @Entity
 */
class UUIDEntity
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="UUID")
     */
    private $id;

    /**
     * Get id.
     *
     * @return string.
     */
    public function getId(): string
    {
        return $this->id;
    }
}
