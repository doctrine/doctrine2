<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function assert;

/**
 * @group DDC-2359
 */
class DDC2359Test extends TestCase
{
    /**
     * Verifies that {@see \Doctrine\ORM\Mapping\ClassMetadataFactory::wakeupReflection} is
     * not called twice when loading metadata from a driver
     */
    public function testIssue(): void
    {
        $mockDriver    = $this->createMock(MappingDriver::class);
        $mockMetadata  = $this->createMock(ClassMetadata::class);
        $entityManager = $this->createMock(EntityManager::class);

        $metadataFactory = $this->getMockBuilder(ClassMetadataFactory::class)
                                ->setMethods(['newClassMetadataInstance', 'wakeupReflection'])
                                ->getMock();
        assert($metadataFactory instanceof ClassMetadataFactory || $metadataFactory instanceof MockObject);

        $configuration = $this->getMockBuilder(Configuration::class)
                              ->setMethods(['getMetadataDriverImpl'])
                              ->getMock();

        $connection = $this->createMock(Connection::class);

        $configuration
            ->expects($this->any())
            ->method('getMetadataDriverImpl')
            ->will($this->returnValue($mockDriver));

        $entityManager->expects($this->any())->method('getConfiguration')->will($this->returnValue($configuration));
        $entityManager->expects($this->any())->method('getConnection')->will($this->returnValue($connection));
        $entityManager
            ->expects($this->any())
            ->method('getEventManager')
            ->will($this->returnValue($this->createMock(EventManager::class)));

        $metadataFactory->expects($this->any())->method('newClassMetadataInstance')->will($this->returnValue($mockMetadata));
        $metadataFactory->expects($this->once())->method('wakeupReflection');

        $metadataFactory->setEntityManager($entityManager);

        $this->assertSame($mockMetadata, $metadataFactory->getMetadataFor(DDC2359Foo::class));
    }
}

/** @Entity */
class DDC2359Foo
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}
