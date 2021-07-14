<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Schema\Sequence;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function is_numeric;

class SequenceEmulatedIdentityStrategyTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->_em->getConnection()->getDatabasePlatform()->usesSequenceEmulatedIdentityColumns()) {
            $this->markTestSkipped(
                'This test is special to platforms emulating IDENTITY key generation strategy through sequences.'
            );
        } else {
            try {
                $this->_schemaTool->createSchema(
                    [$this->_em->getClassMetadata(SequenceEmulatedIdentityEntity::class)]
                );
            } catch (Exception $e) {
                // Swallow all exceptions. We do not test the schema tool here.
            }
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $connection = $this->_em->getConnection();
        $platform   = $connection->getDatabasePlatform();

        // drop sequence manually due to dependency
        $connection->exec(
            $platform->getDropSequenceSQL(
                new Sequence($platform->getIdentitySequenceName('seq_identity', 'id'))
            )
        );
    }

    public function testPreSavePostSaveCallbacksAreInvoked(): void
    {
        $entity = new SequenceEmulatedIdentityEntity();
        $entity->setValue('hello');
        $this->_em->persist($entity);
        $this->_em->flush();
        $this->assertTrue(is_numeric($entity->getId()));
        $this->assertTrue($entity->getId() > 0);
        $this->assertTrue($this->_em->contains($entity));
    }
}

/** @Entity @Table(name="seq_identity") */
class SequenceEmulatedIdentityEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $value;

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
