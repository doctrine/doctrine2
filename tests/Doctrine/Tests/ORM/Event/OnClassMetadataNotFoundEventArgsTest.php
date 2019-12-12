<?php

namespace Doctrine\Tests\ORM;

use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see \Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs}
 *
 * @covers \Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs
 */
class OnClassMetadataNotFoundEventArgsTest extends TestCase
{
    public function testEventArgsMutability()
    {
        /** @var ObjectManager $objectManager */
        $objectManager = $this->createMock(ObjectManager::class);

        $args = new OnClassMetadataNotFoundEventArgs('foo', $objectManager);

        $this->assertSame('foo', $args->getClassName());
        $this->assertSame($objectManager, $args->getObjectManager());

        $this->assertNull($args->getFoundMetadata());

        /** @var ClassMetadata $metadata */
        $metadata = $this->createMock(ClassMetadata::class);

        $args->setFoundMetadata($metadata);

        $this->assertSame($metadata, $args->getFoundMetadata());

        $args->setFoundMetadata(null);

        $this->assertNull($args->getFoundMetadata());
    }
}
