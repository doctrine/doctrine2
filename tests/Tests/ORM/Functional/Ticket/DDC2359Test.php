<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('DDC-2359')]
class DDC2359Test extends TestCase
{
    /**
     * Verifies that {@see \Doctrine\ORM\Mapping\ClassMetadataFactory::wakeupReflection} is
     * not called twice when loading metadata from a driver
     */
    public function testIssue(): void
    {
        $mockDriver    = $this->createStub(MappingDriver::class);
        $entityManager = $this->createStub(EntityManager::class);

        $metadataFactory   = $this->getMockBuilder(ClassMetadataFactory::class)
            ->onlyMethods(['wakeupReflection', 'validateRuntimeMetadata'])
            ->getMock();
        $reflectionService = new RuntimeReflectionService();
        $metadataFactory->expects(self::once())->method('wakeupReflection');

        $configuration = $this->getMockBuilder(Configuration::class)
            ->onlyMethods(['getMetadataDriverImpl'])
            ->getMock();

        $connection = $this->createStub(Connection::class);

        $configuration
            ->method('getMetadataDriverImpl')
            ->willReturn($mockDriver);

        $entityManager->method('getConfiguration')->willReturn($configuration);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager
            ->method('getEventManager')
            ->willReturn(new EventManager());

        $metadataFactory->setEntityManager($entityManager);
        $metadataFactory->getMetadataFor(DDC2359Foo::class);
    }
}

#[Entity]
class DDC2359Foo
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public int $id;
}
